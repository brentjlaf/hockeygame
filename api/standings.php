<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../src/Stats/StandingsService.php';

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 1;

$service = new StandingsService(db());
$standings = $service->fetchStandings($seasonId);

out([
  'season_id' => $seasonId,
  'standings' => $standings,
]);
