<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$teamId = current_team_id();
$team = team_row($teamId);
$pdo = db();
$seasonId = current_season_id($pdo);

$pdo->beginTransaction();

try {
  // 1) Try to join an existing waiting match (human)
  $stmt = $pdo->prepare("
    SELECT * FROM matches
    WHERE status='WAITING_OPPONENT'
      AND away_team_id IS NULL
      AND (submit_deadline IS NULL OR submit_deadline > NOW())
      AND season_id = ?
      AND home_team_id <> ?
    ORDER BY ABS((SELECT rating FROM teams WHERE id=home_team_id) - ?) ASC, created_at ASC
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$seasonId, $teamId, (int)$team['rating']]);
  $match = $stmt->fetch();

  if ($match) {
    $pdo->prepare("UPDATE matches
      SET away_team_id=?, status='WAITING_SUBMISSIONS',
          submit_deadline=DATE_ADD(NOW(), INTERVAL ? MINUTE)
      WHERE id=?")->execute([$teamId, MATCH_SUBMISSION_WINDOW_MINUTES, (int)$match['id']]);

    $pdo->commit();
    out(['match_id' => (int)$match['id'], 'status' => 'WAITING_SUBMISSIONS', 'opponent' => 'HUMAN']);
  }

  // 2) No human opponent -> instantly assign AI team
  $seed = (int)(microtime(true) * 1000) ^ ($teamId << 8);
  $botId = ensure_bot_team_near_rating((int)$team['rating']);
  $pdo->prepare("INSERT INTO matches(season_id, home_team_id, away_team_id, status, seed, submit_deadline)
    VALUES(?, ?, ?, 'WAITING_SUBMISSIONS', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))")->execute([
    $seasonId,
    $teamId,
    $botId,
    $seed,
    MATCH_SUBMISSION_WINDOW_MINUTES
  ]);
  $matchId = (int)$pdo->lastInsertId();

  $pdo->commit();
  out([
    'match_id' => $matchId,
    'status' => 'WAITING_SUBMISSIONS',
    'opponent' => 'AI',
    'bot_team_id' => $botId
  ]);

} catch (Throwable $e) {
  $pdo->rollBack();
  out(['error' => 'Match find failed', 'detail' => $e->getMessage()], 500);
}
