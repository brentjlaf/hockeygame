<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$teamId = current_team_id();
$in = json_in();

$matchId = isset($in['match_id']) ? (int)$in['match_id'] : 0;
$plan = $in['plan'] ?? null;
if ($matchId <= 0 || !is_array($plan)) out(['error' => 'match_id and plan required'], 400);

$pdo = db();

// Ensure match exists
$stmt = $pdo->prepare("SELECT * FROM matches WHERE id=?");
$stmt->execute([$matchId]);
$match = $stmt->fetch();
if (!$match) out(['error' => 'Match not found'], 404);

$homeId = (int)$match['home_team_id'];
$awayId = (int)($match['away_team_id'] ?? 0);
if ($teamId !== $homeId && $teamId !== $awayId) out(['error' => 'Team not in this match'], 403);

$pdo->beginTransaction();
try {
  // Upsert submission
  $pdo->prepare("
    INSERT INTO match_submissions(match_id, team_id, plan_json)
    VALUES(?,?,?)
    ON DUPLICATE KEY UPDATE plan_json=VALUES(plan_json), submitted_at=NOW()
  ")->execute([$matchId, $teamId, json_encode($plan, JSON_UNESCAPED_SLASHES)]);

  // If opponent is a bot and hasn't submitted, auto-submit a default plan for bot
  if ($awayId > 0) {
    $awayTeam = team_row($awayId);
    if ((int)$awayTeam['is_bot'] === 1) {
      $stmt2 = $pdo->prepare("SELECT 1 FROM match_submissions WHERE match_id=? AND team_id=?");
      $stmt2->execute([$matchId, $awayId]);
      if (!$stmt2->fetch()) {
        $botPlan = build_default_plan($awayId);
        $pdo->prepare("
          INSERT INTO match_submissions(match_id, team_id, plan_json)
          VALUES(?,?,?)
        ")->execute([$matchId, $awayId, json_encode($botPlan, JSON_UNESCAPED_SLASHES)]);
      }
    }
  }

  // Check if both submissions exist
  $stmt = $pdo->prepare("SELECT COUNT(*) c FROM match_submissions WHERE match_id=?");
  $stmt->execute([$matchId]);
  $count = (int)$stmt->fetch()['c'];

  if ($count >= 2 && $match['status'] !== 'DONE') {
    $pdo->prepare("UPDATE matches SET status='SIMULATING' WHERE id=?")->execute([$matchId]);
    $pdo->commit();

    require __DIR__.'/simulate.php';
    simulate_match($matchId);

    out(['ok' => true, 'status' => 'DONE', 'match_id' => $matchId]);
  }

  $pdo->commit();
  out(['ok' => true, 'status' => 'WAITING_SUBMISSIONS', 'match_id' => $matchId]);

} catch (Throwable $e) {
  $pdo->rollBack();
  out(['error' => 'Submit failed', 'detail' => $e->getMessage()], 500);
}
