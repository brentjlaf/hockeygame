<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../simulate.php';

$payload = json_in();
$action = strtolower((string)($payload['action'] ?? ''));
$matchId = (int)($payload['match_id'] ?? 0);

if ($matchId <= 0) {
  out(['error' => 'Valid match_id is required.'], 400);
}

switch ($action) {
  case 'simulate':
    try {
      simulate_match($matchId);
      out(['message' => "Match #{$matchId} simulated."]);
    } catch (Throwable $e) {
      out(['error' => 'Failed to simulate match.'], 500);
    }
    break;
  case 'cancel':
    $stmt = db()->prepare("UPDATE matches SET status='CANCELLED' WHERE id=?");
    $stmt->execute([$matchId]);
    out(['message' => "Match #{$matchId} cancelled."]);
    break;
  default:
    out(['error' => 'Unknown admin action.'], 400);
}
