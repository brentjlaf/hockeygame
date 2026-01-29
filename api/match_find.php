<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$teamId = current_team_id();
$team = team_row($teamId);
$pdo = db();

$pdo->beginTransaction();

try {
  // 1) Try to join an existing waiting match (human)
  $stmt = $pdo->prepare("
    SELECT * FROM matches
    WHERE status='WAITING_OPPONENT'
      AND away_team_id IS NULL
      AND home_team_id <> ?
    ORDER BY ABS((SELECT rating FROM teams WHERE id=home_team_id) - ?) ASC, created_at ASC
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$teamId, (int)$team['rating']]);
  $match = $stmt->fetch();

  if ($match) {
    $pdo->prepare("UPDATE matches
      SET away_team_id=?, status='WAITING_SUBMISSIONS',
          submit_deadline=DATE_ADD(NOW(), INTERVAL 10 MINUTE)
      WHERE id=?")->execute([$teamId, (int)$match['id']]);

    $pdo->commit();
    out(['match_id' => (int)$match['id'], 'status' => 'WAITING_SUBMISSIONS', 'opponent' => 'HUMAN']);
  }

  // 2) No human opponent -> create a match and immediately fill with AI bot (Phase 1)
  $seed = (int)(microtime(true) * 1000) ^ ($teamId << 8);
  $pdo->prepare("INSERT INTO matches(home_team_id, away_team_id, status, seed, submit_deadline)
    VALUES(?, NULL, 'WAITING_OPPONENT', ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")->execute([$teamId, $seed]);
  $matchId = (int)$pdo->lastInsertId();

  $botId = ensure_bot_team_near_rating((int)$team['rating']);
  $pdo->prepare("UPDATE matches
    SET away_team_id=?, status='WAITING_SUBMISSIONS'
    WHERE id=?")->execute([$botId, $matchId]);

  $pdo->commit();
  out(['match_id' => $matchId, 'status' => 'WAITING_SUBMISSIONS', 'opponent' => 'AI', 'bot_team_id' => $botId]);

} catch (Throwable $e) {
  $pdo->rollBack();
  out(['error' => 'Match find failed', 'detail' => $e->getMessage()], 500);
}
