<?php
declare(strict_types=1);

final class LeadersService {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  public function fetchGoalLeaders(int $seasonId = 1, int $limit = 10): array {
    $teamGames = $this->fetchTeamGames($seasonId);

    $sql = "
      SELECT p.id, p.name, p.team_id, t.name AS team_name,
        COALESCE(g.goals, 0) AS goals,
        COALESCE(s.shots, 0) AS shots
      FROM players p
      JOIN teams t ON t.id = p.team_id
      LEFT JOIN (
        SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(me.payload, '$.shooter_id')) AS UNSIGNED) AS player_id,
          COUNT(*) AS goals
        FROM match_events me
        JOIN matches m ON m.id = me.match_id
        WHERE me.event_type='GOAL'
          AND m.status='DONE'
          AND m.season_id=?
        GROUP BY player_id
      ) g ON g.player_id = p.id
      LEFT JOIN (
        SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(me.payload, '$.shooter_id')) AS UNSIGNED) AS player_id,
          COUNT(*) AS shots
        FROM match_events me
        JOIN matches m ON m.id = me.match_id
        WHERE me.event_type='SHOT'
          AND m.status='DONE'
          AND m.season_id=?
        GROUP BY player_id
      ) s ON s.player_id = p.id
      WHERE p.pos <> 'G'
        AND (g.goals IS NOT NULL OR s.shots IS NOT NULL)
      ORDER BY goals DESC, shots DESC, p.name ASC
      LIMIT ?
    ";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$seasonId, $seasonId, $limit]);
    $rows = $stmt->fetchAll();

    $leaders = [];
    foreach ($rows as $row) {
      $teamId = (int)$row['team_id'];
      $goals = (int)$row['goals'];
      $shots = (int)$row['shots'];
      $games = $teamGames[$teamId] ?? 0;
      $leaders[] = [
        'player_id' => (int)$row['id'],
        'player_name' => $row['name'],
        'team_id' => $teamId,
        'team_name' => $row['team_name'],
        'games_played' => $games,
        'goals' => $goals,
        'shots' => $shots,
        'shooting_pct' => $shots > 0 ? round(($goals / $shots) * 100, 1) : 0.0,
      ];
    }

    return $leaders;
  }

  private function fetchTeamGames(int $seasonId): array {
    $stmt = $this->pdo->prepare("
      SELECT team_id, COUNT(*) AS games_played
      FROM (
        SELECT home_team_id AS team_id FROM matches WHERE status='DONE' AND season_id=?
        UNION ALL
        SELECT away_team_id AS team_id FROM matches WHERE status='DONE' AND season_id=?
      ) AS all_games
      GROUP BY team_id
    ");
    $stmt->execute([$seasonId, $seasonId]);
    $rows = $stmt->fetchAll();

    $games = [];
    foreach ($rows as $row) {
      $games[(int)$row['team_id']] = (int)$row['games_played'];
    }

    return $games;
  }
}
