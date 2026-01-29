<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../src/Season/SeasonService.php';
require __DIR__.'/../src/Season/PlayoffService.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;
$teamCount = isset($_GET['team_count']) ? (int)$_GET['team_count'] : 0;

$seasonService = new SeasonService(db());
$season = $seasonId > 0 ? $seasonService->fetchSeason($seasonId) : $seasonService->fetchCurrentSeason();
$teamCount = $teamCount > 0 ? $teamCount : $season['playoff_team_count'];
$teamCount = max(2, $teamCount);

$seed = isset($_GET['seed']) ? (int)$_GET['seed'] : ($season['id'] * 7919 + $teamCount * 31);

$service = new PlayoffService(db());
$results = $service->simulatePlayoffs($season['id'], $teamCount, $seed);

out([
  'season' => $season,
  'seed' => $seed,
  'results' => $results,
]);
