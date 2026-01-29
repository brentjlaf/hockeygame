<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;
if ($matchId <= 0) out(['error' => 'match_id required'], 400);

$pdo = db();

$stmt = $pdo->prepare("SELECT * FROM matches WHERE id=?");
$stmt->execute([$matchId]);
$match = $stmt->fetch();
if (!$match) out(['error' => 'Match not found'], 404);

$home = team_row((int)$match['home_team_id']);
$away = $match['away_team_id'] ? team_row((int)$match['away_team_id']) : null;

$stmt = $pdo->prepare("
  SELECT period, tick, game_time_left, event_type, payload
  FROM match_events
  WHERE match_id=?
  ORDER BY period ASC, tick ASC, id ASC
");
$stmt->execute([$matchId]);
$rows = $stmt->fetchAll();

$events = [];
foreach ($rows as $r) {
  $p = json_decode($r['payload'], true);
  $events[] = [
    'period' => (int)$r['period'],
    'tick' => (int)$r['tick'],
    'game_time_left' => (int)$r['game_time_left'],
    'event_type' => $r['event_type'],
    'text' => $p['text'] ?? '',
    'payload' => $p,
  ];
}

out([
  'match' => [
    'id' => (int)$match['id'],
    'status' => $match['status'],
    'home_team' => ['id' => (int)$home['id'], 'name' => $home['name']],
    'away_team' => $away ? ['id' => (int)$away['id'], 'name' => $away['name']] : null,
    'home_score' => (int)($match['home_score'] ?? 0),
    'away_score' => (int)($match['away_score'] ?? 0),
  ],
  'events' => $events
]);
