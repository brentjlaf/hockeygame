<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function simulate_match(int $matchId): void {
  $pdo = db();

  $stmt = $pdo->prepare("SELECT * FROM matches WHERE id=?");
  $stmt->execute([$matchId]);
  $match = $stmt->fetch();
  if (!$match) out(['error' => 'Match not found'], 404);

  $homeId = (int)$match['home_team_id'];
  $awayId = (int)$match['away_team_id'];
  $seed = (int)$match['seed'];
  $seasonId = (int)$match['season_id'];
  $wasDone = $match['status'] === 'DONE';

  // Load plans (fallback if missing)
  $plans = [];
  foreach ([$homeId, $awayId] as $tid) {
    $stmt = $pdo->prepare("SELECT plan_json FROM match_submissions WHERE match_id=? AND team_id=?");
    $stmt->execute([$matchId, $tid]);
    $row = $stmt->fetch();
    $plans[$tid] = $row ? json_decode($row['plan_json'], true) : build_default_plan($tid);
    if (!is_array($plans[$tid])) $plans[$tid] = build_default_plan($tid);
  }

  $T = [
    "START_PERIOD" => [
      "{time} — Period {period} starts. Puck is down.",
      "{time} — We’re underway in Period {period}.",
    ],
    "FLOW" => [
      "{time} — {team}: regrouping through the neutral zone.",
      "{time} — {team}: resets and looks for a lane.",
      "{time} — Tight gap control—no clean entry there.",
      "{time} — {team}: dumps it in and goes to work.",
    ],
    "TURNOVER" => [
      "{time} — Giveaway! {victim} loses it—{team} takes over.",
      "{time} — Turnover forced—{team} comes back the other way.",
    ],
    "HIT" => [
      "{time} — {hitter} finishes a check on {victim}. Puck pops loose!",
      "{time} — Big hit by {hitter} on {victim}!",
    ],
    "SHOT_LOW" => [
      "{time} — {team}: {shooter} throws it on net from the {lane}.",
      "{time} — {team}: low wrister by {shooter}.",
    ],
    "SHOT_HIGH" => [
      "{time} — {team}: {shooter} SNAPSHOT from the slot!",
      "{time} — {team}: point-blank chance for {shooter}!",
    ],
    "SAVE" => [
      "{time} — Save! {goalie} turns it aside.",
      "{time} — Big stop by {goalie}!",
      "{time} — {goalie} swallows it up—no rebound.",
    ],
    "MISS" => [
      "{time} — {team}: {shooter} just misses wide.",
      "{time} — {team}: {shooter} sails it over the crossbar.",
    ],
    "BLOCK" => [
      "{time} — Blocked! {blocker} gets in front of it.",
      "{time} — {blocker} blocks the shot and clears the danger.",
    ],
    "GOAL" => [
      "{time} — GOAL! {team}: {shooter} buries it from the {lane}! ({home}-{away})",
      "{time} — GOAL! {team}: {shooter} finishes! ({home}-{away})",
    ],
    "END_PERIOD" => [
      "{time} — That’s the horn. End of Period {period}. ({home}-{away})",
      "{time} — Period {period} ends. ({home}-{away})",
    ],
  ];

  $homeTeam = team_row($homeId);
  $awayTeam = team_row($awayId);

  $homePlayers = index_players_by_id(team_players($homeId));
  $awayPlayers = index_players_by_id(team_players($awayId));

  $homeGoalie = player_by_id_safe($homePlayers, (int)($plans[$homeId]['goalie'] ?? 0), 'G');
  $awayGoalie = player_by_id_safe($awayPlayers, (int)($plans[$awayId]['goalie'] ?? 0), 'G');

  $homeScore = 0;
  $awayScore = 0;
  $teamStats = init_team_stats($homeId, $awayId);
  $playerStats = init_player_stats($homePlayers, $homeId, $awayPlayers, $awayId);

  // Remove old events if re-simulated
  $pdo->prepare("DELETE FROM match_events WHERE match_id=?")->execute([$matchId]);

  $rng = new RNG($seed);

  $homeBias = team_attack_bias($homeTeam, $plans[$homeId]);
  $awayBias = team_attack_bias($awayTeam, $plans[$awayId]);

  $periodLength = MATCH_TICKS_PER_PERIOD * MATCH_SECONDS_PER_TICK;

  for ($period = 1; $period <= MATCH_PERIODS; $period++) {

    $gameLeft = $periodLength;
    insert_event($matchId, $period, 0, $gameLeft, 'FACEOFF', [
      'text' => render($T, 'START_PERIOD', $seed, $period*1000 + 1, [
        'time' => hockey_clock($gameLeft),
        'period' => $period
      ])
    ]);

    for ($tick = 0; $tick < MATCH_TICKS_PER_PERIOD; $tick++) {
      $gameLeft = $periodLength - ($tick * MATCH_SECONDS_PER_TICK);
      $saltBase = $period * 1000 + $tick * 10;

      $homePush = ($rng->float() + $homeBias) > ($rng->float() + $awayBias);

      // shot chance influenced by tactics
      $homeShotChance = 0.18 + tactics_shot_boost($plans[$homeId]);
      $awayShotChance = 0.18 + tactics_shot_boost($plans[$awayId]);
      $shotChance = $homePush ? $homeShotChance : $awayShotChance;

      $r = $rng->float();

      if ($r < $shotChance) {
        $attTeamId = $homePush ? $homeId : $awayId;
        $attTeamName = $homePush ? $homeTeam['name'] : $awayTeam['name'];
        $defGoalie = $homePush ? $awayGoalie : $homeGoalie;
        $defTeamId = $homePush ? $awayId : $homeId;

        $attPlayers = $homePush ? $homePlayers : $awayPlayers;
        $defPlayers = $homePush ? $awayPlayers : $homePlayers;

        $lane = ['left','slot','right'][$rng->int(0,2)];

        $danger = compute_danger($rng, $attTeamId, $plans);
        $shooter = pick_shooter($rng, $attPlayers);

        $goalProb = compute_goal_probability($danger, $shooter, $defGoalie, $homePush ? $homeBias : $awayBias);
        $saveProb = max(0.0, min(1.0, 0.78 - ($goalProb * 0.5)));
        $x = $rng->float();

        $shotKey = ($danger >= 4) ? 'SHOT_HIGH' : 'SHOT_LOW';
        insert_event($matchId, $period, $tick, $gameLeft, 'SHOT', [
          'team_id' => $attTeamId,
          'shooter_id' => (int)$shooter['id'],
          'lane' => $lane,
          'danger' => $danger,
          'text' => render($T, $shotKey, $seed, $saltBase + 1, [
            'time' => hockey_clock($gameLeft),
            'team' => $attTeamName,
            'shooter' => $shooter['name'],
            'lane' => $lane
          ])
        ]);

        $shooterId = (int)$shooter['id'];
        if (isset($playerStats[$shooterId])) {
          $playerStats[$shooterId]['shots']++;
        }
        $teamStats[$attTeamId]['shots_for']++;
        $teamStats[$defTeamId]['shots_against']++;
        $defGoalieId = (int)$defGoalie['id'];
        if (isset($playerStats[$defGoalieId])) {
          $playerStats[$defGoalieId]['shots_against']++;
        }

        if ($x < $goalProb) {
          if ($homePush) $homeScore++; else $awayScore++;

          $assistIds = pick_assists($rng, $attPlayers, $shooterId);
          foreach ($assistIds as $assistId) {
            if (isset($playerStats[$assistId])) {
              $playerStats[$assistId]['assists']++;
            }
          }

          insert_event($matchId, $period, $tick, $gameLeft, 'GOAL', [
            'team_id' => $attTeamId,
            'shooter_id' => (int)$shooter['id'],
            'assist_ids' => $assistIds,
            'lane' => $lane,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'text' => render($T, 'GOAL', $seed, $saltBase + 2, [
              'time' => hockey_clock($gameLeft),
              'team' => $attTeamName,
              'shooter' => $shooter['name'],
              'lane' => $lane,
              'home' => $homeScore,
              'away' => $awayScore
            ])
          ]);
          if (isset($playerStats[$shooterId])) {
            $playerStats[$shooterId]['goals']++;
          }
          $teamStats[$attTeamId]['goals_for']++;
          $teamStats[$defTeamId]['goals_against']++;
        } elseif ($x < ($goalProb + $saveProb)) {
          insert_event($matchId, $period, $tick, $gameLeft, 'SAVE', [
            'goalie_id' => (int)$defGoalie['id'],
            'text' => render($T, 'SAVE', $seed, $saltBase + 3, [
              'time' => hockey_clock($gameLeft),
              'goalie' => $defGoalie['name']
            ])
          ]);
          if (isset($playerStats[$defGoalieId])) {
            $playerStats[$defGoalieId]['saves']++;
          }
        } else {
          if ($rng->float() < 0.5) {
            insert_event($matchId, $period, $tick, $gameLeft, 'MISS', [
              'text' => render($T, 'MISS', $seed, $saltBase + 4, [
                'time' => hockey_clock($gameLeft),
                'team' => $attTeamName,
                'shooter' => $shooter['name']
              ])
            ]);
          } else {
            $blocker = pick_defender($rng, $defPlayers);
            insert_event($matchId, $period, $tick, $gameLeft, 'BLOCK', [
              'blocker_id' => (int)$blocker['id'],
              'text' => render($T, 'BLOCK', $seed, $saltBase + 5, [
                'time' => hockey_clock($gameLeft),
                'blocker' => $blocker['name']
              ])
            ]);
          }
        }

      } else {
        $r2 = $rng->float();

        if ($r2 < 0.12) {
          $attPlayers = $homePush ? $homePlayers : $awayPlayers;
          $defPlayers = $homePush ? $awayPlayers : $homePlayers;
          $hitter = pick_gritty($rng, $attPlayers);
          $victim = pick_any_skater($rng, $defPlayers);

          insert_event($matchId, $period, $tick, $gameLeft, 'HIT', [
            'text' => render($T, 'HIT', $seed, $saltBase + 6, [
              'time' => hockey_clock($gameLeft),
              'hitter' => $hitter['name'],
              'victim' => $victim['name'],
            ])
          ]);

        } elseif ($r2 < 0.25) {
          $takeTeamName = $homePush ? $homeTeam['name'] : $awayTeam['name'];
          $defPlayers = $homePush ? $awayPlayers : $homePlayers;
          $victim = pick_any_skater($rng, $defPlayers);

          insert_event($matchId, $period, $tick, $gameLeft, 'TURNOVER', [
            'text' => render($T, 'TURNOVER', $seed, $saltBase + 7, [
              'time' => hockey_clock($gameLeft),
              'team' => $takeTeamName,
              'victim' => $victim['name'],
            ])
          ]);

        } else {
          if ($rng->float() < 0.18) {
            $teamName = $homePush ? $homeTeam['name'] : $awayTeam['name'];
            insert_event($matchId, $period, $tick, $gameLeft, 'POSSESSION', [
              'text' => render($T, 'FLOW', $seed, $saltBase + 8, [
                'time' => hockey_clock($gameLeft),
                'team' => $teamName
              ])
            ]);
          }
        }
      }

      if ($tick === (MATCH_TICKS_PER_PERIOD - 1)) {
        insert_event($matchId, $period, $tick, 0, 'HORN', [
          'text' => render($T, 'END_PERIOD', $seed, $period*1000 + 999, [
            'time' => '0:00',
            'period' => $period,
            'home' => $homeScore,
            'away' => $awayScore
          ])
        ]);
      }
    }
  }

  $teamStats[$homeId]['games_played'] = 1;
  $teamStats[$awayId]['games_played'] = 1;
  $teamStats[$homeId]['goals_for'] = $homeScore;
  $teamStats[$homeId]['goals_against'] = $awayScore;
  $teamStats[$awayId]['goals_for'] = $awayScore;
  $teamStats[$awayId]['goals_against'] = $homeScore;

  $homeGoalieId = (int)$homeGoalie['id'];
  $awayGoalieId = (int)$awayGoalie['id'];

  if ($homeScore > $awayScore) {
    $teamStats[$homeId]['wins'] = 1;
    $teamStats[$awayId]['losses'] = 1;
    $teamStats[$homeId]['points'] = 2;
    if (isset($playerStats[$homeGoalieId])) {
      $playerStats[$homeGoalieId]['wins'] = 1;
    }
  } elseif ($awayScore > $homeScore) {
    $teamStats[$awayId]['wins'] = 1;
    $teamStats[$homeId]['losses'] = 1;
    $teamStats[$awayId]['points'] = 2;
    if (isset($playerStats[$awayGoalieId])) {
      $playerStats[$awayGoalieId]['wins'] = 1;
    }
  } else {
    $teamStats[$homeId]['ties'] = 1;
    $teamStats[$awayId]['ties'] = 1;
    $teamStats[$homeId]['points'] = 1;
    $teamStats[$awayId]['points'] = 1;
  }

  foreach ($playerStats as &$stats) {
    $stats['points'] = $stats['goals'] + $stats['assists'];
  }
  unset($stats);

  $pdo->prepare("UPDATE matches
    SET home_score=?, away_score=?, status='DONE', simulated_at=NOW()
    WHERE id=?")->execute([$homeScore, $awayScore, $matchId]);

  if (!$wasDone) {
    record_match_stats($pdo, $matchId, $seasonId, $teamStats, $playerStats);
  }
}

function index_players_by_id(array $players): array {
  $out = [];
  foreach ($players as $p) $out[(int)$p['id']] = $p;
  return $out;
}

function player_by_id_safe(array $playersById, int $id, string $pos): array {
  if ($id > 0 && isset($playersById[$id])) return $playersById[$id];
  $fallback = null;
  foreach ($playersById as $p) {
    if ($pos === 'G' && $p['pos'] !== 'G') continue;
    if (!$fallback) $fallback = $p;
    if ($pos === 'G' && $p['goalie'] > ($fallback['goalie'] ?? -1)) $fallback = $p;
  }
  return $fallback ?: array_values($playersById)[0];
}

function tactics_shot_boost(array $plan): float {
  $t = $plan['tactics'] ?? [];
  $ag = (int)($t['aggression'] ?? 50);
  $sb = (int)($t['shoot_bias'] ?? 50);
  return (($ag - 50) / 100) * 0.05 + (($sb - 50) / 100) * 0.08;
}

function team_attack_bias(array $team, array $plan): float {
  $t = $plan['tactics'] ?? [];
  $ag = (int)($t['aggression'] ?? 50);
  $rk = (int)($t['risk'] ?? 50);
  $rating = (int)($team['rating'] ?? 1000);
  return (($rating - 1000) / 1000) * 0.08 + (($ag - 50) / 100) * 0.04 + (($rk - 50) / 100) * 0.03;
}

function compute_danger(RNG $rng, int $attTeamId, array $plans): int {
  $attPlan = $plans[$attTeamId];
  $t = $attPlan['tactics'] ?? [];
  $ag = (int)($t['aggression'] ?? 50);
  $rk = (int)($t['risk'] ?? 50);

  $base = 1 + $rng->int(0, 3);
  $boost = 0;
  if ($ag > 60) $boost++;
  if ($rk > 60) $boost++;
  if ($ag < 40) $boost--;
  $danger = max(1, min(5, $base + $boost + ($rng->float() < 0.08 ? 1 : 0)));
  return $danger;
}

function compute_goal_probability(int $danger, array $shooter, array $goalie, float $bias): float {
  $baseByDanger = [1 => 0.02, 2 => 0.04, 3 => 0.06, 4 => 0.09, 5 => 0.12];
  $base = $baseByDanger[$danger] ?? 0.05;

  $shot = (int)($shooter['shot'] ?? 50);
  $g = (int)($goalie['goalie'] ?? 50);

  $shotFactor = ($shot - 50) / 200;
  $goalieFactor = -($g - 50) / 220;

  $p = $base + $shotFactor + $goalieFactor + ($bias * 0.03);
  return max(0.005, min(0.22, $p));
}

function pick_shooter(RNG $rng, array $playersById): array {
  $skaters = array_values(array_filter($playersById, fn($p) => $p['pos'] !== 'G'));
  usort($skaters, fn($a,$b) => ($b['shot'] <=> $a['shot']));
  $pool = array_slice($skaters, 0, min(6, count($skaters)));
  return $pool ? $pool[$rng->int(0, count($pool)-1)] : $skaters[0];
}

function pick_defender(RNG $rng, array $playersById): array {
  $d = array_values(array_filter($playersById, fn($p) => $p['pos'] === 'D'));
  if ($d) return $d[$rng->int(0, count($d)-1)];
  return pick_any_skater($rng, $playersById);
}

function pick_gritty(RNG $rng, array $playersById): array {
  $skaters = array_values(array_filter($playersById, fn($p) => $p['pos'] !== 'G'));
  usort($skaters, fn($a,$b) => (($b['grit']??0) <=> ($a['grit']??0)));
  $pool = array_slice($skaters, 0, min(6, count($skaters)));
  return $pool ? $pool[$rng->int(0, count($pool)-1)] : $skaters[0];
}

function pick_any_skater(RNG $rng, array $playersById): array {
  $skaters = array_values(array_filter($playersById, fn($p) => $p['pos'] !== 'G'));
  return $skaters[$rng->int(0, count($skaters)-1)];
}

function render(array $T, string $key, int $seed, int $salt, array $vars): string {
  $tpl = pick_template($T[$key] ?? [''], $seed, $salt);
  foreach ($vars as $k => $v) {
    $tpl = str_replace('{'.$k.'}', (string)$v, $tpl);
  }
  return $tpl;
}

function insert_event(int $matchId, int $period, int $tick, int $gameLeft, string $type, array $payload): void {
  $pdo = db();
  $pdo->prepare("
    INSERT INTO match_events(match_id, period, tick, game_time_left, event_type, payload)
    VALUES(?,?,?,?,?,?)
  ")->execute([
    $matchId, $period, $tick, $gameLeft, $type,
    json_encode($payload, JSON_UNESCAPED_SLASHES)
  ]);
}

function init_team_stats(int $homeId, int $awayId): array {
  return [
    $homeId => [
      'team_id' => $homeId,
      'games_played' => 0,
      'wins' => 0,
      'losses' => 0,
      'ties' => 0,
      'goals_for' => 0,
      'goals_against' => 0,
      'points' => 0,
      'shots_for' => 0,
      'shots_against' => 0,
    ],
    $awayId => [
      'team_id' => $awayId,
      'games_played' => 0,
      'wins' => 0,
      'losses' => 0,
      'ties' => 0,
      'goals_for' => 0,
      'goals_against' => 0,
      'points' => 0,
      'shots_for' => 0,
      'shots_against' => 0,
    ],
  ];
}

function init_player_stats(array $homePlayers, int $homeId, array $awayPlayers, int $awayId): array {
  $stats = [];
  foreach ($homePlayers as $player) {
    $stats[(int)$player['id']] = [
      'player_id' => (int)$player['id'],
      'team_id' => $homeId,
      'games_played' => 1,
      'goals' => 0,
      'assists' => 0,
      'points' => 0,
      'shots' => 0,
      'saves' => 0,
      'shots_against' => 0,
      'wins' => 0,
    ];
  }
  foreach ($awayPlayers as $player) {
    $stats[(int)$player['id']] = [
      'player_id' => (int)$player['id'],
      'team_id' => $awayId,
      'games_played' => 1,
      'goals' => 0,
      'assists' => 0,
      'points' => 0,
      'shots' => 0,
      'saves' => 0,
      'shots_against' => 0,
      'wins' => 0,
    ];
  }

  return $stats;
}

function pick_assists(RNG $rng, array $playersById, int $shooterId): array {
  $skaters = array_values(array_filter($playersById, fn($p) => $p['pos'] !== 'G' && (int)$p['id'] !== $shooterId));
  if (!$skaters) return [];
  usort($skaters, fn($a,$b) => (($b['pass_attr'] ?? 0) <=> ($a['pass_attr'] ?? 0)));
  $pool = array_slice($skaters, 0, min(8, count($skaters)));
  $assistCount = $rng->int(0, 2);
  $assists = [];
  $available = $pool;
  for ($i = 0; $i < $assistCount && $available; $i++) {
    $idx = $rng->int(0, count($available) - 1);
    $assists[] = (int)$available[$idx]['id'];
    array_splice($available, $idx, 1);
  }
  return $assists;
}

function record_match_stats(PDO $pdo, int $matchId, int $seasonId, array $teamStats, array $playerStats): void {
  $pdo->beginTransaction();

  $teamStmt = $pdo->prepare("
    INSERT INTO team_season_stats(season_id, team_id, games_played, wins, losses, ties, goals_for, goals_against, points)
    VALUES(?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      games_played = games_played + VALUES(games_played),
      wins = wins + VALUES(wins),
      losses = losses + VALUES(losses),
      ties = ties + VALUES(ties),
      goals_for = goals_for + VALUES(goals_for),
      goals_against = goals_against + VALUES(goals_against),
      points = points + VALUES(points)
  ");

  foreach ($teamStats as $stats) {
    $teamStmt->execute([
      $seasonId,
      $stats['team_id'],
      $stats['games_played'],
      $stats['wins'],
      $stats['losses'],
      $stats['ties'],
      $stats['goals_for'],
      $stats['goals_against'],
      $stats['points'],
    ]);
  }

  $seasonStmt = $pdo->prepare("
    INSERT INTO player_season_stats(
      season_id, player_id, team_id, games_played, goals, assists, points,
      shots, saves, shots_against, wins
    )
    VALUES(?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      games_played = games_played + VALUES(games_played),
      goals = goals + VALUES(goals),
      assists = assists + VALUES(assists),
      points = points + VALUES(points),
      shots = shots + VALUES(shots),
      saves = saves + VALUES(saves),
      shots_against = shots_against + VALUES(shots_against),
      wins = wins + VALUES(wins)
  ");

  $matchStmt = $pdo->prepare("
    INSERT INTO player_match_stats(
      match_id, season_id, player_id, team_id, games_played, goals, assists, points,
      shots, saves, shots_against, wins
    )
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      games_played = VALUES(games_played),
      goals = VALUES(goals),
      assists = VALUES(assists),
      points = VALUES(points),
      shots = VALUES(shots),
      saves = VALUES(saves),
      shots_against = VALUES(shots_against),
      wins = VALUES(wins)
  ");

  foreach ($playerStats as $stats) {
    $seasonStmt->execute([
      $seasonId,
      $stats['player_id'],
      $stats['team_id'],
      $stats['games_played'],
      $stats['goals'],
      $stats['assists'],
      $stats['points'],
      $stats['shots'],
      $stats['saves'],
      $stats['shots_against'],
      $stats['wins'],
    ]);

    $matchStmt->execute([
      $matchId,
      $seasonId,
      $stats['player_id'],
      $stats['team_id'],
      $stats['games_played'],
      $stats['goals'],
      $stats['assists'],
      $stats['points'],
      $stats['shots'],
      $stats['saves'],
      $stats['shots_against'],
      $stats['wins'],
    ]);
  }

  $pdo->commit();
}
