<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../src/Stats/StandingsService.php';
require __DIR__.'/../src/Season/SeasonService.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;

$service = new StandingsService(db());
$seasonService = new SeasonService(db());
$season = $seasonId > 0 ? $seasonService->fetchSeason($seasonId) : $seasonService->fetchCurrentSeason();
$standings = $service->fetchStandings($season['id']);

out([
  'season' => $season,
  'divisions' => $seasonService->listDivisions(),
  'standings' => $standings,
]);
