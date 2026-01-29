<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../src/Stats/LeadersService.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;

$service = new LeadersService(db());
$leaders = $service->fetchGoalLeaders($seasonId, $limit);

out([
  'season_id' => $seasonId,
  'leaders' => $leaders,
]);
