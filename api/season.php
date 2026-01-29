<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../src/Season/SeasonService.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;

$service = new SeasonService(db());
$season = $seasonId > 0 ? $service->fetchSeason($seasonId) : $service->fetchCurrentSeason();

out([
  'season' => $season,
  'divisions' => $service->listDivisions(),
]);
