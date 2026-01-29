<?php
declare(strict_types=1);

final class StandingsService {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function fetchStandings(int $seasonId = 1): array {
    $teams = $this->pdo->query("SELECT id, name, rating, is_bot FROM teams ORDER BY name ASC")->fetchAll();
    $standings = [];

    foreach ($teams as $team) {
      $teamId = (int)$team['id'];
      $standings[$teamId] = [
        'team_id' => $teamId,
        'team_name' => $team['name'],
        'rating' => (int)$team['rating'],
        'is_bot' => (int)$team['is_bot'],
        'games_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'ties' => 0,
        'points' => 0,
        'goals_for' => 0,
        'goals_against' => 0,
        'goal_diff' => 0,
      ];
    }

    $stmt = $this->pdo->prepare("
      SELECT home_team_id, away_team_id, home_score, away_score
      FROM matches
      WHERE status='DONE'
        AND season_id=?
        AND away_team_id IS NOT NULL
    ");
    $stmt->execute([$seasonId]);
    $matches = $stmt->fetchAll();

    foreach ($matches as $match) {
      $homeId = (int)$match['home_team_id'];
      $awayId = (int)$match['away_team_id'];
      if (!isset($standings[$homeId]) || !isset($standings[$awayId])) {
        continue;
      }

      $homeScore = (int)($match['home_score'] ?? 0);
      $awayScore = (int)($match['away_score'] ?? 0);

      $standings[$homeId]['games_played']++;
      $standings[$awayId]['games_played']++;
      $standings[$homeId]['goals_for'] += $homeScore;
      $standings[$homeId]['goals_against'] += $awayScore;
      $standings[$awayId]['goals_for'] += $awayScore;
      $standings[$awayId]['goals_against'] += $homeScore;

      if ($homeScore > $awayScore) {
        $standings[$homeId]['wins']++;
        $standings[$awayId]['losses']++;
      } elseif ($awayScore > $homeScore) {
        $standings[$awayId]['wins']++;
        $standings[$homeId]['losses']++;
      } else {
        $standings[$homeId]['ties']++;
        $standings[$awayId]['ties']++;
      }
    }

    foreach ($standings as &$row) {
      $row['points'] = $row['wins'] * 2;
      $row['goal_diff'] = $row['goals_for'] - $row['goals_against'];
    }
    unset($row);

    $rows = array_values($standings);
    usort($rows, function(array $a, array $b): int {
      return [$b['points'], $b['wins'], $b['goal_diff'], $b['goals_for'], $a['team_name']]
        <=>
        [$a['points'], $a['wins'], $a['goal_diff'], $a['goals_for'], $b['team_name']];
    });

    $rank = 1;
    foreach ($rows as &$row) {
      $row['rank'] = $rank++;
    }
    unset($row);

    return $rows;
  }
}
