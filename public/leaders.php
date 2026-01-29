<?php
declare(strict_types=1);
require __DIR__.'/../api/bootstrap.php';
require __DIR__.'/../src/Stats/LeadersService.php';

header('Content-Type: text/html; charset=utf-8');

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 1;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;

$service = new LeadersService(db());
$leaders = $service->fetchGoalLeaders($seasonId, $limit);

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>League Leaders</title>
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
  <h1>League Leaders</h1>
  <div class="meta">Season <?= $seasonId ?> · Top <?= $limit ?></div>

  <?php if (!$leaders): ?>
    <div class="empty">No scoring data yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th class="rank">#</th>
          <th>Player</th>
          <th>Team</th>
          <th class="right">GP</th>
          <th class="right">G</th>
          <th class="right">S</th>
          <th class="right">SH%</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leaders as $index => $row): ?>
          <tr>
            <td class="rank"><?= $index + 1 ?></td>
            <td><?= $escape($row['player_name']) ?></td>
            <td><?= $escape($row['team_name']) ?></td>
            <td class="right"><?= $row['games_played'] ?></td>
            <td class="right"><?= $row['goals'] ?></td>
            <td class="right"><?= $row['shots'] ?></td>
            <td class="right"><?= number_format($row['shooting_pct'], 1) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
