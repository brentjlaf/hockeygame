<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../src/Stats/LeadersService.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$category = isset($_GET['category']) ? strtolower((string)$_GET['category']) : 'points';
$minGames = isset($_GET['min_gp']) ? max(0, (int)$_GET['min_gp']) : 3;

$service = new LeadersService(db());
if ($category === 'goalies') {
  $leaders = $service->fetchGoalieLeaders($seasonId, $limit, $minGames);
} else {
  $leaders = $service->fetchSkaterLeaders($category, $seasonId, $limit);
}

out([
  'season_id' => $seasonId,
  'category' => $category,
  'min_gp' => $minGames,
  'leaders' => $leaders,
]);
