<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$teamId = current_team_id();
$team = team_row($teamId);
$pdo = db();

$pdo->beginTransaction();

try {
  // 0) If team is already waiting, either keep waiting or auto-fill with AI
  $stmt = $pdo->prepare("
    SELECT * FROM matches
    WHERE status='WAITING_OPPONENT'
      AND home_team_id=?
      AND away_team_id IS NULL
    ORDER BY created_at DESC
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([$teamId]);
  $waitingMatch = $stmt->fetch();

  if ($waitingMatch) {
    $deadline = $waitingMatch['submit_deadline'];
    $deadlinePassed = $deadline ? (strtotime($deadline) <= time()) : false;

    if ($deadlinePassed) {
      $botId = ensure_bot_team_near_rating((int)$team['rating']);
      $pdo->prepare("UPDATE matches
        SET away_team_id=?, status='WAITING_SUBMISSIONS',
            submit_deadline=DATE_ADD(NOW(), INTERVAL ? MINUTE)
        WHERE id=?")->execute([$botId, MATCH_SUBMISSION_WINDOW_MINUTES, (int)$waitingMatch['id']]);

      $pdo->commit();
      out(['match_id' => (int)$waitingMatch['id'], 'status' => 'WAITING_SUBMISSIONS', 'opponent' => 'AI', 'bot_team_id' => $botId]);
    }

    $secondsLeft = $deadline ? max(0, (int)floor((strtotime($deadline) - time()))) : MATCH_HUMAN_WAIT_SECONDS;
    $pdo->commit();
    out([
      'match_id' => (int)$waitingMatch['id'],
      'status' => 'WAITING_OPPONENT',
      'opponent' => 'PENDING',
      'wait_seconds' => $secondsLeft
    ]);
  }

  // 1) Try to join an existing waiting match (human)
  $stmt = $pdo->prepare("
    SELECT * FROM matches
    WHERE status='WAITING_OPPONENT'
      AND away_team_id IS NULL
      AND (submit_deadline IS NULL OR submit_deadline > NOW())
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
          submit_deadline=DATE_ADD(NOW(), INTERVAL ? MINUTE)
      WHERE id=?")->execute([$teamId, MATCH_SUBMISSION_WINDOW_MINUTES, (int)$match['id']]);

    $pdo->commit();
    out(['match_id' => (int)$match['id'], 'status' => 'WAITING_SUBMISSIONS', 'opponent' => 'HUMAN']);
  }

  // 2) No human opponent -> create a match and wait up to X seconds for a human
  $seed = (int)(microtime(true) * 1000) ^ ($teamId << 8);
  $pdo->prepare("INSERT INTO matches(home_team_id, away_team_id, status, seed, submit_deadline)
    VALUES(?, NULL, 'WAITING_OPPONENT', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))")->execute([
    $teamId,
    $seed,
    MATCH_HUMAN_WAIT_SECONDS
  ]);
  $matchId = (int)$pdo->lastInsertId();

  $pdo->commit();
  out([
    'match_id' => $matchId,
    'status' => 'WAITING_OPPONENT',
    'opponent' => 'PENDING',
    'wait_seconds' => MATCH_HUMAN_WAIT_SECONDS
  ]);

} catch (Throwable $e) {
  $pdo->rollBack();
  out(['error' => 'Match find failed', 'detail' => $e->getMessage()], 500);
}
