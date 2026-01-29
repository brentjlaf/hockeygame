<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$gameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
if ($gameId <= 0) out(['error' => 'game_id required'], 400);

$pdo = db();

$stmt = $pdo->prepare("SELECT * FROM games WHERE id=?");
$stmt->execute([$gameId]);
$game = $stmt->fetch();
if (!$game) out(['error' => 'Game not found'], 404);

$home = team_row((int)$game['home_team_id']);
$away = team_row((int)$game['away_team_id']);

$stmt = $pdo->prepare("
  SELECT id, period, tick, game_time_left, event_type, payload
  FROM game_events
  WHERE game_id=?
  ORDER BY period ASC, tick ASC, id ASC
");
$stmt->execute([$gameId]);
$rows = $stmt->fetchAll();

$events = [];
foreach ($rows as $r) {
  $p = json_decode($r['payload'], true);
  $events[] = [
    'id' => (int)$r['id'],
    'period' => (int)$r['period'],
    'tick' => (int)$r['tick'],
    'game_time_left' => (int)$r['game_time_left'],
    'event_type' => $r['event_type'],
    'text' => $p['text'] ?? '',
    'payload' => $p,
  ];
}

out([
  'game' => [
    'id' => (int)$game['id'],
    'status' => $game['status'],
    'home_team' => ['id' => (int)$home['id'], 'name' => $home['name']],
    'away_team' => ['id' => (int)$away['id'], 'name' => $away['name']],
    'home_score' => (int)($game['home_score'] ?? 0),
    'away_score' => (int)($game['away_score'] ?? 0),
  ],
  'events' => $events,
]);
