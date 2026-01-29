<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../src/Stats/LeadersService.php';
require __DIR__.'/../src/Season/SeasonService.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$category = isset($_GET['category']) ? strtolower((string)$_GET['category']) : 'points';
$minGames = isset($_GET['min_gp']) ? max(0, (int)$_GET['min_gp']) : 3;

$service = new LeadersService(db());
$seasonService = new SeasonService(db());
$season = $seasonId > 0 ? $seasonService->fetchSeason($seasonId) : $seasonService->fetchCurrentSeason();
if ($category === 'goalies') {
  $leaders = $service->fetchGoalieLeaders($season['id'], $limit, $minGames);
} else {
  $leaders = $service->fetchSkaterLeaders($category, $season['id'], $limit);
}

out([
  'season' => $season,
  'category' => $category,
  'min_gp' => $minGames,
  'leaders' => $leaders,
]);
