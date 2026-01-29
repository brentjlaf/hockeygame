<?php
declare(strict_types=1);
require __DIR__.'/../api/bootstrap.php';
require __DIR__.'/../src/Stats/StandingsService.php';

header('Content-Type: text/html; charset=utf-8');

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 1;
$service = new StandingsService(db());
$standings = $service->fetchStandings($seasonId);

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>League Standings</title>
  <style>
    body { font-family: Arial, sans-serif; background:#0b1522; color:#f8fafc; margin:0; padding:24px; }
    h1 { margin-bottom: 8px; }
    .meta { color:#cbd5f5; margin-bottom: 16px; }
    table { width:100%; border-collapse: collapse; background:#111c2b; border-radius:8px; overflow:hidden; }
    th, td { padding:10px 12px; border-bottom:1px solid #223148; text-align:left; }
    th { background:#142338; text-transform:uppercase; font-size:12px; letter-spacing:0.08em; }
    tr:last-child td { border-bottom:none; }
    .rank { width:48px; text-align:center; }
    .right { text-align:right; }
    .empty { padding:16px; background:#111c2b; border-radius:8px; }
  </style>
</head>
<body>
  <h1>League Standings</h1>
  <div class="meta">Season <?= $seasonId ?></div>

  <?php if (!$standings): ?>
    <div class="empty">No completed games yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th class="rank">#</th>
          <th>Team</th>
          <th class="right">GP</th>
          <th class="right">W</th>
          <th class="right">L</th>
          <th class="right">T</th>
          <th class="right">GF</th>
          <th class="right">GA</th>
          <th class="right">DIFF</th>
          <th class="right">PTS</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($standings as $row): ?>
          <tr>
            <td class="rank"><?= $row['rank'] ?></td>
            <td><?= $escape($row['team_name']) ?></td>
            <td class="right"><?= $row['games_played'] ?></td>
            <td class="right"><?= $row['wins'] ?></td>
            <td class="right"><?= $row['losses'] ?></td>
            <td class="right"><?= $row['ties'] ?></td>
            <td class="right"><?= $row['goals_for'] ?></td>
            <td class="right"><?= $row['goals_against'] ?></td>
            <td class="right"><?= $row['goal_diff'] ?></td>
            <td class="right"><?= $row['points'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
