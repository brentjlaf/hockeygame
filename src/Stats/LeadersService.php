<?php
declare(strict_types=1);

final class LeadersService {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function fetchSkaterLeaders(string $category, int $seasonId = 1, int $limit = 10): array {
    $orderBy = match ($category) {
      'assists' => 'assists DESC, goals DESC, points DESC',
      'goals' => 'goals DESC, assists DESC, points DESC',
      default => 'points DESC, goals DESC, assists DESC',
    };

    $sql = "
      SELECT p.id, p.name, p.team_id, t.name AS team_name,
        ps.games_played, ps.goals, ps.assists, ps.points
      FROM players p
      JOIN teams t ON t.id = p.team_id
      JOIN player_season_stats ps
        ON ps.player_id = p.id
        AND ps.season_id = ?
      WHERE p.pos <> 'G'
        AND ps.games_played > 0
      ORDER BY {$orderBy}, p.name ASC
      LIMIT ?
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$seasonId, $limit]);
    $rows = $stmt->fetchAll();

    $leaders = [];
    foreach ($rows as $row) {
      $leaders[] = [
        'player_id' => (int)$row['id'],
        'player_name' => $row['name'],
        'team_id' => (int)$row['team_id'],
        'team_name' => $row['team_name'],
        'games_played' => (int)$row['games_played'],
        'goals' => (int)$row['goals'],
        'assists' => (int)$row['assists'],
        'points' => (int)$row['points'],
      ];
    }

    return $leaders;
  }

  public function fetchGoalieLeaders(int $seasonId = 1, int $limit = 10, int $minGames = 3): array {
    $sql = "
      SELECT p.id, p.name, p.team_id, t.name AS team_name,
        ps.games_played, ps.saves, ps.shots_against, ps.wins
      FROM players p
      JOIN teams t ON t.id = p.team_id
      JOIN player_season_stats ps
        ON ps.player_id = p.id
        AND ps.season_id = ?
      WHERE p.pos = 'G'
        AND ps.games_played >= ?
      ORDER BY ps.saves DESC, ps.wins DESC, p.name ASC
      LIMIT ?
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$seasonId, $minGames, $limit]);
    $rows = $stmt->fetchAll();

    $leaders = [];
    foreach ($rows as $row) {
      $shotsAgainst = (int)$row['shots_against'];
      $saves = (int)$row['saves'];
      $goalsAgainst = max(0, $shotsAgainst - $saves);
      $games = max(1, (int)$row['games_played']);
      $savePct = $shotsAgainst > 0 ? round(($saves / $shotsAgainst) * 100, 1) : 0.0;
      $gaa = round(($goalsAgainst / $games), 2);

      $leaders[] = [
        'player_id' => (int)$row['id'],
        'player_name' => $row['name'],
        'team_id' => (int)$row['team_id'],
        'team_name' => $row['team_name'],
        'games_played' => (int)$row['games_played'],
        'wins' => (int)$row['wins'],
        'saves' => $saves,
        'shots_against' => $shotsAgainst,
        'save_pct' => $savePct,
        'gaa' => $gaa,
      ];
    }

    usort($leaders, function(array $a, array $b): int {
      return [$b['save_pct'], $a['gaa'], $b['wins'], $b['saves'], $a['player_name']]
        <=>
        [$a['save_pct'], $b['gaa'], $a['wins'], $a['saves'], $b['player_name']];
    });

    return array_slice($leaders, 0, $limit);
  }
}
