<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($teamId <= 0) out(['error' => 'team_id required'], 400);

$team = team_row($teamId);
$players = team_players($teamId);

out([
  'team' => [
    'id' => (int)$team['id'],
    'name' => $team['name'],
    'rating' => (int)$team['rating'],
  ],
  'players' => array_map(static function(array $player): array {
    return [
      'id' => (int)$player['id'],
      'name' => $player['name'],
      'pos' => $player['pos'],
    ];
  }, $players),
]);
