<?php
declare(strict_types=1);

final class GameSimulator
{
    private const PERIODS = 3;
    private const TICKS_PER_PERIOD = 40;
    private const PERIOD_SECONDS = 1200;
    private const SECONDS_PER_TICK = 30;

    private PDO $db;
    private RNG $rng;
    private int $seed;

    private array $templates = [
        'START_PERIOD' => [
            '{time} — Period {period} begins. Puck drop.',
            '{time} — We are underway in period {period}.',
        ],
        'SHIFT' => [
            '{time} — {team} changes. {line} hops over the boards.',
            '{time} — Line change for {team}: {line} on the ice.',
        ],
        'FLOW' => [
            '{time} — {team} controls possession in the neutral zone.',
            '{time} — {team} resets and looks for a lane.',
        ],
        'TURNOVER' => [
            '{time} — Turnover by {victim}. {team} comes the other way.',
            '{time} — {team} forces a giveaway from {victim}.',
        ],
        'HIT' => [
            '{time} — {hitter} finishes a check on {victim}.',
            '{time} — Big hit by {hitter} on {victim}.',
        ],
        'SHOT' => [
            '{time} — {team}: {shooter} fires from the {lane}.',
            '{time} — {team}: {shooter} snaps a shot from the {lane}.',
        ],
        'SAVE' => [
            '{time} — Save by {goalie}.',
            '{time} — {goalie} turns it aside.',
        ],
        'MISS' => [
            '{time} — {team}: {shooter} just misses wide.',
            '{time} — {team}: {shooter} sails it high.',
        ],
        'BLOCK' => [
            '{time} — Blocked by {blocker}.',
            '{time} — {blocker} gets in the lane and blocks it.',
        ],
        'GOAL' => [
            '{time} — GOAL {team}! {shooter} scores from the {lane}. ({home}-{away})',
            '{time} — {team} scores! {shooter} finishes it. ({home}-{away})',
        ],
        'END_PERIOD' => [
            '{time} — Horn sounds. End of period {period}. ({home}-{away})',
            '{time} — Period {period} ends. ({home}-{away})',
        ],
    ];

    public function __construct(PDO $db, ?int $seed = null)
    {
        $this->db = $db;
        $this->seed = $seed ?? random_int(1, PHP_INT_MAX);
        $this->rng = new RNG($this->seed);
    }

    public function simulate(int $homeTeamId, ?int $awayTeamId = null, ?array $homePlan = null, ?array $awayPlan = null): array
    {
        $homeTeam = $this->teamRow($homeTeamId);

        if ($awayTeamId === null) {
            $awayTeamId = $this->ensureBotTeamNearRating((int)$homeTeam['rating']);
        }

        $awayTeam = $this->teamRow($awayTeamId);

        $homePlan = $homePlan ?? $this->buildDefaultPlan($homeTeamId);
        $awayPlan = $awayPlan ?? $this->buildAiPlan($awayTeamId, $awayTeam);

        $homePlayers = $this->indexPlayersById($this->teamPlayers($homeTeamId));
        $awayPlayers = $this->indexPlayersById($this->teamPlayers($awayTeamId));

        $gameId = $this->createGame($homeTeamId, $awayTeamId, $this->seed);

        $stats = $this->initializeStats(array_keys($homePlayers), array_keys($awayPlayers));

        $homeScore = 0;
        $awayScore = 0;

        $homeBias = $this->teamAttackBias($homeTeam, $homePlan);
        $awayBias = $this->teamAttackBias($awayTeam, $awayPlan);

        $homeGoalie = $this->playerByIdSafe($homePlayers, (int)($homePlan['goalie'] ?? 0), 'G');
        $awayGoalie = $this->playerByIdSafe($awayPlayers, (int)($awayPlan['goalie'] ?? 0), 'G');

        $prevHomeLine = null;
        $prevAwayLine = null;

        for ($period = 1; $period <= self::PERIODS; $period++) {
            $gameLeft = self::PERIOD_SECONDS;
            $this->insertEvent($gameId, $period, 0, $gameLeft, 'FACEOFF', [
                'text' => $this->render('START_PERIOD', $period * 1000 + 1, [
                    'time' => $this->clock($gameLeft),
                    'period' => $period,
                ]),
            ]);

            for ($tick = 0; $tick < self::TICKS_PER_PERIOD; $tick++) {
                $gameLeft = max(0, self::PERIOD_SECONDS - ($tick * self::SECONDS_PER_TICK));
                $saltBase = $period * 1000 + $tick * 10;

                $homeLine = $this->lineForTick($homePlan, $tick);
                $awayLine = $this->lineForTick($awayPlan, $tick);

                if ($homeLine['key'] !== $prevHomeLine) {
                    $this->insertEvent($gameId, $period, $tick, $gameLeft, 'SHIFT', [
                        'team_id' => $homeTeamId,
                        'line' => $homeLine['key'],
                        'text' => $this->render('SHIFT', $saltBase + 1, [
                            'time' => $this->clock($gameLeft),
                            'team' => $homeTeam['name'],
                            'line' => $homeLine['key'],
                        ]),
                    ]);
                    $prevHomeLine = $homeLine['key'];
                }

                if ($awayLine['key'] !== $prevAwayLine) {
                    $this->insertEvent($gameId, $period, $tick, $gameLeft, 'SHIFT', [
                        'team_id' => $awayTeamId,
                        'line' => $awayLine['key'],
                        'text' => $this->render('SHIFT', $saltBase + 2, [
                            'time' => $this->clock($gameLeft),
                            'team' => $awayTeam['name'],
                            'line' => $awayLine['key'],
                        ]),
                    ]);
                    $prevAwayLine = $awayLine['key'];
                }

                $this->addIceTime($stats, $homeLine['players'], self::SECONDS_PER_TICK);
                $this->addIceTime($stats, $awayLine['players'], self::SECONDS_PER_TICK);

                $homePush = ($this->rng->float() + $homeBias) > ($this->rng->float() + $awayBias);
                $attTeamId = $homePush ? $homeTeamId : $awayTeamId;
                $attTeam = $homePush ? $homeTeam : $awayTeam;
                $attLine = $homePush ? $homeLine : $awayLine;
                $defLine = $homePush ? $awayLine : $homeLine;
                $defPlayers = $homePush ? $awayPlayers : $homePlayers;
                $defGoalie = $homePush ? $awayGoalie : $homeGoalie;

                $shotChance = 0.18 + $this->tacticsShotBoost($homePush ? $homePlan : $awayPlan);
                $eventRoll = $this->rng->float();

                if ($eventRoll < $shotChance) {
                    $lane = ['left', 'slot', 'right'][$this->rng->int(0, 2)];
                    $danger = $this->computeDanger($homePush ? $homePlan : $awayPlan);
                    $shooter = $this->pickShooter($attLine['players'], $homePush ? $homePlayers : $awayPlayers);

                    $this->recordShot($stats, (int)$shooter['id']);

                    $goalProb = $this->computeGoalProbability($danger, $shooter, $defGoalie, $homePush ? $homeBias : $awayBias);
                    $saveProb = max(0.0, min(1.0, 0.78 - ($goalProb * 0.5)));
                    $roll = $this->rng->float();

                    $this->insertEvent($gameId, $period, $tick, $gameLeft, 'SHOT', [
                        'team_id' => $attTeamId,
                        'shooter_id' => (int)$shooter['id'],
                        'lane' => $lane,
                        'danger' => $danger,
                        'text' => $this->render('SHOT', $saltBase + 3, [
                            'time' => $this->clock($gameLeft),
                            'team' => $attTeam['name'],
                            'shooter' => $shooter['name'],
                            'lane' => $lane,
                        ]),
                    ]);

                    if ($roll < $goalProb) {
                        if ($homePush) {
                            $homeScore++;
                        } else {
                            $awayScore++;
                        }

                        $this->recordGoal($stats, (int)$shooter['id']);

                        $assist = $this->pickAssist($attLine['players'], $shooter['id'] ?? null, $homePush ? $homePlayers : $awayPlayers);
                        if ($assist) {
                            $this->recordAssist($stats, (int)$assist['id']);
                        }

                        $this->insertEvent($gameId, $period, $tick, $gameLeft, 'GOAL', [
                            'team_id' => $attTeamId,
                            'shooter_id' => (int)$shooter['id'],
                            'assist_id' => $assist ? (int)$assist['id'] : null,
                            'lane' => $lane,
                            'home_score' => $homeScore,
                            'away_score' => $awayScore,
                            'text' => $this->render('GOAL', $saltBase + 4, [
                                'time' => $this->clock($gameLeft),
                                'team' => $attTeam['name'],
                                'shooter' => $shooter['name'],
                                'lane' => $lane,
                                'home' => $homeScore,
                                'away' => $awayScore,
                            ]),
                        ]);
                    } elseif ($roll < ($goalProb + $saveProb)) {
                        $this->recordSave($stats, (int)$defGoalie['id']);
                        $this->insertEvent($gameId, $period, $tick, $gameLeft, 'SAVE', [
                            'goalie_id' => (int)$defGoalie['id'],
                            'text' => $this->render('SAVE', $saltBase + 5, [
                                'time' => $this->clock($gameLeft),
                                'goalie' => $defGoalie['name'],
                            ]),
                        ]);
                    } else {
                        if ($this->rng->float() < 0.5) {
                            $this->insertEvent($gameId, $period, $tick, $gameLeft, 'MISS', [
                                'text' => $this->render('MISS', $saltBase + 6, [
                                    'time' => $this->clock($gameLeft),
                                    'team' => $attTeam['name'],
                                    'shooter' => $shooter['name'],
                                ]),
                            ]);
                        } else {
                            $blocker = $this->pickDefender($defLine['players'], $defPlayers);
                            $this->recordBlock($stats, (int)$blocker['id']);
                            $this->insertEvent($gameId, $period, $tick, $gameLeft, 'BLOCK', [
                                'blocker_id' => (int)$blocker['id'],
                                'text' => $this->render('BLOCK', $saltBase + 7, [
                                    'time' => $this->clock($gameLeft),
                                    'blocker' => $blocker['name'],
                                ]),
                            ]);
                        }
                    }
                } else {
                    $r2 = $this->rng->float();
                    if ($r2 < 0.12) {
                        $hitter = $this->pickGritty($attLine['players'], $homePush ? $homePlayers : $awayPlayers);
                        $victim = $this->pickAnySkater($defLine['players'], $defPlayers);
                        $this->recordHit($stats, (int)$hitter['id']);

                        $this->insertEvent($gameId, $period, $tick, $gameLeft, 'HIT', [
                            'text' => $this->render('HIT', $saltBase + 8, [
                                'time' => $this->clock($gameLeft),
                                'hitter' => $hitter['name'],
                                'victim' => $victim['name'],
                            ]),
                        ]);
                    } elseif ($r2 < 0.25) {
                        $victim = $this->pickAnySkater($defLine['players'], $defPlayers);
                        $this->insertEvent($gameId, $period, $tick, $gameLeft, 'TURNOVER', [
                            'text' => $this->render('TURNOVER', $saltBase + 9, [
                                'time' => $this->clock($gameLeft),
                                'team' => $attTeam['name'],
                                'victim' => $victim['name'],
                            ]),
                        ]);
                    } elseif ($this->rng->float() < 0.18) {
                        $this->insertEvent($gameId, $period, $tick, $gameLeft, 'POSSESSION', [
                            'text' => $this->render('FLOW', $saltBase + 10, [
                                'time' => $this->clock($gameLeft),
                                'team' => $attTeam['name'],
                            ]),
                        ]);
                    }
                }

                if ($tick === self::TICKS_PER_PERIOD - 1) {
                    $this->insertEvent($gameId, $period, $tick, 0, 'HORN', [
                        'text' => $this->render('END_PERIOD', $period * 1000 + 999, [
                            'time' => '0:00',
                            'period' => $period,
                            'home' => $homeScore,
                            'away' => $awayScore,
                        ]),
                    ]);
                }
            }
        }

        $this->updateGameResult($gameId, $homeScore, $awayScore);
        $this->applyPlayerExperience($stats);

        return [
            'game_id' => $gameId,
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ];
    }

    private function createGame(int $homeTeamId, int $awayTeamId, int $seed): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO games(home_team_id, away_team_id, seed, status, created_at) VALUES(?,?,?,?,NOW())"
        );
        $stmt->execute([$homeTeamId, $awayTeamId, $seed, 'SIMULATING']);
        return (int)$this->db->lastInsertId();
    }

    private function updateGameResult(int $gameId, int $homeScore, int $awayScore): void
    {
        $stmt = $this->db->prepare(
            "UPDATE games SET home_score=?, away_score=?, status='DONE', simulated_at=NOW() WHERE id=?"
        );
        $stmt->execute([$homeScore, $awayScore, $gameId]);
    }

    private function insertEvent(int $gameId, int $period, int $tick, int $gameLeft, string $type, array $payload): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO game_events(game_id, period, tick, game_time_left, event_type, payload) VALUES(?,?,?,?,?,?)"
        );
        $stmt->execute([
            $gameId,
            $period,
            $tick,
            $gameLeft,
            $type,
            json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function render(string $key, int $salt, array $vars): string
    {
        $templates = $this->templates[$key] ?? [''];
        $index = $this->seededIndex(count($templates), $salt);
        $tpl = $templates[$index];
        foreach ($vars as $k => $v) {
            $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
        }
        return $tpl;
    }

    private function seededIndex(int $count, int $salt): int
    {
        if ($count <= 1) {
            return 0;
        }
        $index = (int)(($this->seed + $salt) % $count);
        return $index < 0 ? $index + $count : $index;
    }

    private function clock(int $gameTimeLeft): string
    {
        $m = intdiv($gameTimeLeft, 60);
        $s = $gameTimeLeft % 60;
        return sprintf('%d:%02d', $m, $s);
    }

    private function lineForTick(array $plan, int $tick): array
    {
        $lines = $plan['lines'] ?? [];
        $keys = array_values(array_keys($lines));
        if (!$keys) {
            return ['key' => 'L1', 'players' => []];
        }
        $shiftLength = 3;
        $index = intdiv($tick, $shiftLength) % count($keys);
        $key = $keys[$index];
        $line = $lines[$key] ?? [];
        $players = array_values(array_filter(array_merge($line['F'] ?? [], $line['D'] ?? [])));
        return ['key' => $key, 'players' => $players];
    }

    private function teamRow(int $teamId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM teams WHERE id=?');
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Team not found: ' . $teamId);
        }
        return $row;
    }

    private function teamPlayers(int $teamId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM players WHERE team_id=?');
        $stmt->execute([$teamId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function indexPlayersById(array $players): array
    {
        $out = [];
        foreach ($players as $player) {
            $out[(int)$player['id']] = $player;
        }
        return $out;
    }

    private function playerByIdSafe(array $playersById, int $id, string $pos): array
    {
        if ($id > 0 && isset($playersById[$id])) {
            return $playersById[$id];
        }
        $fallback = null;
        foreach ($playersById as $player) {
            if ($pos === 'G' && $player['pos'] !== 'G') {
                continue;
            }
            if ($fallback === null) {
                $fallback = $player;
                continue;
            }
            if ($pos === 'G' && $player['goalie'] > ($fallback['goalie'] ?? -1)) {
                $fallback = $player;
            }
        }
        return $fallback ?? array_values($playersById)[0];
    }

    private function buildDefaultPlan(int $teamId): array
    {
        $players = $this->teamPlayers($teamId);

        $forwards = array_values(array_filter($players, fn(array $p) => $p['pos'] !== 'D' && $p['pos'] !== 'G'));
        $defenders = array_values(array_filter($players, fn(array $p) => $p['pos'] === 'D'));
        $goalies = array_values(array_filter($players, fn(array $p) => $p['pos'] === 'G'));

        usort($forwards, fn(array $a, array $b) => ($b['shot'] + $b['pass_attr'] + $b['speed']) <=> ($a['shot'] + $a['pass_attr'] + $a['speed']));
        usort($defenders, fn(array $a, array $b) => ($b['defense_attr'] + $b['pass_attr'] + $b['grit']) <=> ($a['defense_attr'] + $a['pass_attr'] + $a['grit']));
        usort($goalies, fn(array $a, array $b) => ($b['goalie']) <=> ($a['goalie']));

        $line = function (int $fStart, int $dStart) use ($forwards, $defenders): array {
            return [
                'F' => [
                    $forwards[$fStart + 0]['id'] ?? 0,
                    $forwards[$fStart + 1]['id'] ?? 0,
                    $forwards[$fStart + 2]['id'] ?? 0,
                ],
                'D' => [
                    $defenders[$dStart + 0]['id'] ?? 0,
                    $defenders[$dStart + 1]['id'] ?? 0,
                ],
            ];
        };

        return [
            'lines' => [
                'L1' => $line(0, 0),
                'L2' => $line(3, 2),
                'L3' => $line(6, 4),
                'L4' => $line(9, 0),
            ],
            'goalie' => $goalies[0]['id'] ?? 0,
            'tactics' => [
                'aggression' => 55,
                'forecheck' => 50,
                'shoot_bias' => 60,
                'risk' => 45,
            ],
        ];
    }

    private function buildAiPlan(int $teamId, array $team): array
    {
        $plan = $this->buildDefaultPlan($teamId);
        $difficulty = (int)($team['bot_difficulty'] ?? 5);
        $style = strtoupper((string)($team['coach_style'] ?? 'BALANCED'));

        $baseAggression = 45 + ($difficulty * 3);
        $baseRisk = 40 + ($difficulty * 2);

        $tactics = $plan['tactics'] ?? [];
        $tactics['aggression'] = min(80, max(30, $baseAggression));
        $tactics['risk'] = min(75, max(25, $baseRisk));

        switch ($style) {
            case 'SNIPER':
                $tactics['shoot_bias'] = 70;
                $tactics['forecheck'] = 55;
                break;
            case 'GRIT':
                $tactics['shoot_bias'] = 55;
                $tactics['forecheck'] = 65;
                break;
            case 'DEFENSIVE':
                $tactics['shoot_bias'] = 45;
                $tactics['forecheck'] = 40;
                $tactics['aggression'] = max(30, $tactics['aggression'] - 10);
                $tactics['risk'] = max(20, $tactics['risk'] - 10);
                break;
            default:
                $tactics['shoot_bias'] = 58;
                $tactics['forecheck'] = 50;
                break;
        }

        $plan['tactics'] = $tactics;
        return $plan;
    }

    private function ensureBotTeamNearRating(int $targetRating): int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM teams WHERE is_bot=1 ORDER BY ABS(rating - ?) ASC LIMIT 1'
        );
        $stmt->execute([$targetRating]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int)$row['id'];
        }

        $rating = max(700, min(1300, $targetRating + $this->rng->int(-50, 50)));
        $styles = ['BALANCED', 'SNIPER', 'GRIT', 'DEFENSIVE'];
        $style = $styles[$this->rng->int(0, count($styles) - 1)];

        $stmt = $this->db->prepare(
            'INSERT INTO teams(user_id,name,rating,is_bot,bot_difficulty,coach_style) VALUES(NULL,?,?,?,?,?)'
        );
        $stmt->execute([
            'Bot ' . $rating,
            $rating,
            1,
            $this->rng->int(3, 7),
            $style,
        ]);

        $botId = (int)$this->db->lastInsertId();
        $base = (int)round(($rating - 800) / 10) + 45;
        $this->createBasicRoster($botId, $base);

        return $botId;
    }

    private function createBasicRoster(int $teamId, int $base): void
    {
        $names = [
            'Carter', 'Novak', 'Grayson', 'Miller', 'Reed', 'Benson', 'Hayes', 'Stone', 'Cruz', 'Keller',
            'Fox', 'Lane', 'Hart', 'Wells', 'Parker', 'Quinn', 'Sloane', 'Ryder', 'Shaw', 'Vale',
        ];
        $idx = 0;

        foreach (range(1, 12) as $i) {
            $pos = ($i % 3 === 1) ? 'C' : (($i % 3 === 2) ? 'LW' : 'RW');
            $this->db->prepare(
                'INSERT INTO players(team_id,name,pos,shot,pass_attr,speed,defense_attr,grit,goalie) VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                $teamId,
                $names[$idx++ % count($names)] . " F{$i}",
                $pos,
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                10,
            ]);
        }

        foreach (range(1, 6) as $i) {
            $this->db->prepare(
                'INSERT INTO players(team_id,name,pos,shot,pass_attr,speed,defense_attr,grit,goalie) VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                $teamId,
                $names[$idx++ % count($names)] . " D{$i}",
                'D',
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                10,
            ]);
        }

        foreach (range(1, 2) as $i) {
            $this->db->prepare(
                'INSERT INTO players(team_id,name,pos,shot,pass_attr,speed,defense_attr,grit,goalie) VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                $teamId,
                $names[$idx++ % count($names)] . " G{$i}",
                'G',
                10,
                10,
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(-10, 10),
                $base + $this->rng->int(0, 15),
            ]);
        }
    }

    private function tacticsShotBoost(array $plan): float
    {
        $t = $plan['tactics'] ?? [];
        $ag = (int)($t['aggression'] ?? 50);
        $sb = (int)($t['shoot_bias'] ?? 50);
        return (($ag - 50) / 100) * 0.05 + (($sb - 50) / 100) * 0.08;
    }

    private function teamAttackBias(array $team, array $plan): float
    {
        $t = $plan['tactics'] ?? [];
        $ag = (int)($t['aggression'] ?? 50);
        $rk = (int)($t['risk'] ?? 50);
        $rating = (int)($team['rating'] ?? 1000);
        return (($rating - 1000) / 1000) * 0.08 + (($ag - 50) / 100) * 0.04 + (($rk - 50) / 100) * 0.03;
    }

    private function computeDanger(array $plan): int
    {
        $t = $plan['tactics'] ?? [];
        $ag = (int)($t['aggression'] ?? 50);
        $rk = (int)($t['risk'] ?? 50);

        $base = 1 + $this->rng->int(0, 3);
        $boost = 0;
        if ($ag > 60) {
            $boost++;
        }
        if ($rk > 60) {
            $boost++;
        }
        if ($ag < 40) {
            $boost--;
        }
        return max(1, min(5, $base + $boost + ($this->rng->float() < 0.08 ? 1 : 0)));
    }

    private function computeGoalProbability(int $danger, array $shooter, array $goalie, float $bias): float
    {
        $baseByDanger = [1 => 0.02, 2 => 0.04, 3 => 0.06, 4 => 0.09, 5 => 0.12];
        $base = $baseByDanger[$danger] ?? 0.05;

        $shot = (int)($shooter['shot'] ?? 50);
        $g = (int)($goalie['goalie'] ?? 50);

        $shotFactor = ($shot - 50) / 200;
        $goalieFactor = -($g - 50) / 220;

        $p = $base + $shotFactor + $goalieFactor + ($bias * 0.03);
        return max(0.005, min(0.22, $p));
    }

    private function pickShooter(array $linePlayerIds, array $playersById): array
    {
        $skaters = array_values(array_filter(
            array_map(fn(int $id) => $playersById[$id] ?? null, $linePlayerIds),
            fn($p) => $p && $p['pos'] !== 'G'
        ));
        usort($skaters, fn(array $a, array $b) => ($b['shot'] <=> $a['shot']));
        $pool = array_slice($skaters, 0, min(5, count($skaters)));
        if (!$pool) {
            $pool = array_values(array_filter($playersById, fn(array $p) => $p['pos'] !== 'G'));
        }
        return $pool[$this->rng->int(0, count($pool) - 1)];
    }

    private function pickAssist(array $linePlayerIds, ?int $shooterId, array $playersById): ?array
    {
        $options = array_values(array_filter(
            array_map(fn(int $id) => $playersById[$id] ?? null, $linePlayerIds),
            fn($p) => $p && $p['pos'] !== 'G' && ($shooterId === null || (int)$p['id'] !== $shooterId)
        ));
        if (!$options) {
            return null;
        }
        return $options[$this->rng->int(0, count($options) - 1)];
    }

    private function pickDefender(array $linePlayerIds, array $playersById): array
    {
        $defenders = array_values(array_filter(
            array_map(fn(int $id) => $playersById[$id] ?? null, $linePlayerIds),
            fn($p) => $p && $p['pos'] === 'D'
        ));
        if ($defenders) {
            return $defenders[$this->rng->int(0, count($defenders) - 1)];
        }
        return $this->pickAnySkater($linePlayerIds, $playersById);
    }

    private function pickGritty(array $linePlayerIds, array $playersById): array
    {
        $skaters = array_values(array_filter(
            array_map(fn(int $id) => $playersById[$id] ?? null, $linePlayerIds),
            fn($p) => $p && $p['pos'] !== 'G'
        ));
        usort($skaters, fn(array $a, array $b) => (($b['grit'] ?? 0) <=> ($a['grit'] ?? 0)));
        $pool = array_slice($skaters, 0, min(5, count($skaters)));
        if (!$pool) {
            $pool = array_values(array_filter($playersById, fn(array $p) => $p['pos'] !== 'G'));
        }
        return $pool[$this->rng->int(0, count($pool) - 1)];
    }

    private function pickAnySkater(array $linePlayerIds, array $playersById): array
    {
        $skaters = array_values(array_filter(
            array_map(fn(int $id) => $playersById[$id] ?? null, $linePlayerIds),
            fn($p) => $p && $p['pos'] !== 'G'
        ));
        if (!$skaters) {
            $skaters = array_values(array_filter($playersById, fn(array $p) => $p['pos'] !== 'G'));
        }
        return $skaters[$this->rng->int(0, count($skaters) - 1)];
    }

    private function initializeStats(array $homeIds, array $awayIds): array
    {
        $stats = [];
        foreach (array_merge($homeIds, $awayIds) as $id) {
            $stats[$id] = [
                'shots' => 0,
                'goals' => 0,
                'assists' => 0,
                'hits' => 0,
                'blocks' => 0,
                'saves' => 0,
                'toi' => 0,
            ];
        }
        return $stats;
    }

    private function addIceTime(array &$stats, array $playerIds, int $seconds): void
    {
        foreach ($playerIds as $id) {
            if (!isset($stats[$id])) {
                continue;
            }
            $stats[$id]['toi'] += $seconds;
        }
    }

    private function recordShot(array &$stats, int $playerId): void
    {
        if (isset($stats[$playerId])) {
            $stats[$playerId]['shots']++;
        }
    }

    private function recordGoal(array &$stats, int $playerId): void
    {
        if (isset($stats[$playerId])) {
            $stats[$playerId]['goals']++;
        }
    }

    private function recordAssist(array &$stats, int $playerId): void
    {
        if (isset($stats[$playerId])) {
            $stats[$playerId]['assists']++;
        }
    }

    private function recordHit(array &$stats, int $playerId): void
    {
        if (isset($stats[$playerId])) {
            $stats[$playerId]['hits']++;
        }
    }

    private function recordBlock(array &$stats, int $playerId): void
    {
        if (isset($stats[$playerId])) {
            $stats[$playerId]['blocks']++;
        }
    }

    private function recordSave(array &$stats, int $playerId): void
    {
        if (isset($stats[$playerId])) {
            $stats[$playerId]['saves']++;
        }
    }

    private function applyPlayerExperience(array $stats): void
    {
        $stmt = $this->db->prepare('UPDATE players SET xp = xp + ? WHERE id=?');
        foreach ($stats as $playerId => $row) {
            $xp = ($row['goals'] * 10) + ($row['assists'] * 6) + ($row['shots'] * 1) + ($row['hits'] * 1) + ($row['blocks'] * 2) + ($row['saves'] * 1);
            if ($xp <= 0) {
                continue;
            }
            $stmt->execute([$xp, $playerId]);
        }
    }
}

final class RNG
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0x7fffffff;
    }

    private function nextInt(): int
    {
        $this->state = (1103515245 * $this->state + 12345) & 0x7fffffff;
        return $this->state;
    }

    public function float(): float
    {
        return $this->nextInt() / 0x7fffffff;
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        $r = $this->nextInt() % (($max - $min) + 1);
        return $min + $r;
    }
}
