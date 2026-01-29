<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
  $stmt = db()->query("
    SELECT
      m.id,
      m.status,
      m.home_team_id,
      m.away_team_id,
      m.home_score,
      m.away_score,
      m.created_at,
      ht.name AS home_name,
      at.name AS away_name
    FROM matches m
    LEFT JOIN teams ht ON ht.id = m.home_team_id
    LEFT JOIN teams at ON at.id = m.away_team_id
    ORDER BY m.id DESC
    LIMIT 20
  ");

  $matches = $stmt->fetchAll();
  out(['matches' => $matches]);
} catch (Throwable $e) {
  out(['error' => 'Unable to load matches.'], 500);
}
