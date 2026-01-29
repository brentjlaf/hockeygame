<?php
declare(strict_types=1);

require_once __DIR__.'/Division.php';

final class SeasonService {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function fetchSeason(int $seasonId): array {
    $stmt = $this->pdo->prepare("SELECT * FROM seasons WHERE id=?");
    $stmt->execute([$seasonId]);
    $season = $stmt->fetch();

    if (!$season) {
      return $this->defaultSeason($seasonId);
    }

    return $this->normalizeSeason($season);
  }

  public function fetchCurrentSeason(): array {
    $stmt = $this->pdo->query("SELECT * FROM seasons WHERE status='ACTIVE' ORDER BY starts_at DESC, id DESC LIMIT 1");
    $season = $stmt->fetch();

    if (!$season) {
      return $this->defaultSeason(1);
    }

    return $this->normalizeSeason($season);
  }

  public function listDivisions(): array {
    return Division::list();
  }

  public function softResetRating(int $rating, float $resetPct, int $baseline = 1000): int {
    $adjusted = $baseline + ($rating - $baseline) * $resetPct;
    return (int)round($adjusted);
  }

  private function defaultSeason(int $seasonId): array {
    return [
      'id' => $seasonId,
      'name' => 'Season '.$seasonId,
      'starts_at' => null,
      'ends_at' => null,
      'length_days' => 30,
      'rating_soft_reset_pct' => 0.75,
      'playoff_team_count' => 8,
      'status' => 'ACTIVE',
    ];
  }

  private function normalizeSeason(array $season): array {
    return [
      'id' => (int)$season['id'],
      'name' => $season['name'],
      'starts_at' => $season['starts_at'],
      'ends_at' => $season['ends_at'],
      'length_days' => isset($season['length_days']) ? (int)$season['length_days'] : 30,
      'rating_soft_reset_pct' => isset($season['rating_soft_reset_pct']) ? (float)$season['rating_soft_reset_pct'] : 0.75,
      'playoff_team_count' => isset($season['playoff_team_count']) ? (int)$season['playoff_team_count'] : 8,
      'status' => $season['status'] ?? 'ACTIVE',
    ];
  }
}
