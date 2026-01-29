<?php
declare(strict_types=1);

require_once __DIR__.'/../Season/Division.php';

final class StandingsService {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function fetchStandings(int $seasonId = 1): array {
    $stmt = $this->pdo->prepare("
      SELECT t.id, t.name, t.rating, t.is_bot,
        COALESCE(s.games_played, 0) AS games_played,
        COALESCE(s.wins, 0) AS wins,
        COALESCE(s.losses, 0) AS losses,
        COALESCE(s.ties, 0) AS ties,
        COALESCE(s.points, 0) AS points,
        COALESCE(s.goals_for, 0) AS goals_for,
        COALESCE(s.goals_against, 0) AS goals_against
      FROM teams t
      LEFT JOIN team_season_stats s
        ON s.team_id = t.id
        AND s.season_id = ?
      ORDER BY t.name ASC
    ");
    $stmt->execute([$seasonId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
      $division = Division::fromRating((int)$row['rating']);
      $row['team_id'] = (int)$row['id'];
      unset($row['id']);
      $row['rating'] = (int)$row['rating'];
      $row['is_bot'] = (int)$row['is_bot'];
      $row['games_played'] = (int)$row['games_played'];
      $row['wins'] = (int)$row['wins'];
      $row['losses'] = (int)$row['losses'];
      $row['ties'] = (int)$row['ties'];
      $row['points'] = (int)$row['points'];
      $row['goals_for'] = (int)$row['goals_for'];
      $row['goals_against'] = (int)$row['goals_against'];
      $row['goal_diff'] = $row['goals_for'] - $row['goals_against'];
      $row['team_name'] = $row['name'];
      $row['division'] = $division['name'];
      $row['division_key'] = $division['key'];
      unset($row['name']);
    }
    unset($row);

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
