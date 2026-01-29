<?php
declare(strict_types=1);

require_once __DIR__.'/../Stats/StandingsService.php';
require_once __DIR__.'/Division.php';

final class PlayoffService {
  private PDO $pdo;
  private StandingsService $standingsService;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
    $this->standingsService = new StandingsService($pdo);
  }

  public function simulatePlayoffs(int $seasonId, int $teamCount, int $seed): array {
    $standings = $this->standingsService->fetchStandings($seasonId);
    $qualified = array_slice($standings, 0, $teamCount);

    $seeded = [];
    foreach ($qualified as $index => $team) {
      $seeded[] = array_merge($team, [
        'seed' => $index + 1,
      ]);
    }

    $rng = new RNG($seed);
    $rounds = [];
    $current = $seeded;
    $round = 1;

    while (count($current) > 1) {
      $matchups = [];
      $next = [];
      $total = count($current);

      for ($i = 0; $i < $total / 2; $i++) {
        $home = $current[$i];
        $away = $current[$total - 1 - $i];
        $winner = $this->pickWinner($home, $away, $rng);

        $matchups[] = [
          'home' => $this->formatTeam($home),
          'away' => $this->formatTeam($away),
          'winner' => $this->formatTeam($winner),
        ];
        $next[] = $winner;
      }

      $rounds[] = [
        'round' => $round,
        'matchups' => $matchups,
      ];

      $current = $next;
      $round++;
    }

    $champion = $current[0] ?? null;

    return [
      'season_id' => $seasonId,
      'playoff_teams' => $teamCount,
      'rounds' => $rounds,
      'champion' => $champion ? $this->formatTeam($champion) : null,
      'rewards' => $this->buildRewards($seasonId, $champion),
    ];
  }

  private function pickWinner(array $home, array $away, RNG $rng): array {
    $ratingDiff = $home['rating'] - $away['rating'];
    $ratingEdge = max(-0.25, min(0.25, $ratingDiff / 400));
    $winChance = 0.5 + $ratingEdge;

    return $rng->float() < $winChance ? $home : $away;
  }

  private function formatTeam(array $team): array {
    $division = Division::fromRating($team['rating']);

    return [
      'team_id' => (int)$team['team_id'],
      'team_name' => $team['team_name'],
      'seed' => $team['seed'] ?? null,
      'rating' => (int)$team['rating'],
      'division' => $division['name'],
      'division_key' => $division['key'],
    ];
  }

  private function buildRewards(int $seasonId, ?array $champion): array {
    if (!$champion) return [];

    return [
      [
        'type' => 'trophy',
        'name' => 'Season '.$seasonId.' Champion',
        'team_id' => (int)$champion['team_id'],
        'team_name' => $champion['team_name'],
      ],
      [
        'type' => 'badge',
        'name' => 'Playoff Finalist',
        'team_id' => (int)$champion['team_id'],
        'team_name' => $champion['team_name'],
      ],
    ];
  }
}
