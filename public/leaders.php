<?php
declare(strict_types=1);
require __DIR__.'/../api/bootstrap.php';
require __DIR__.'/../src/Stats/LeadersService.php';
require __DIR__.'/../src/Season/SeasonService.php';

header('Content-Type: text/html; charset=utf-8');

$seasonId = isset($_GET['season_id']) ? (int)$_GET['season_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$category = isset($_GET['category']) ? strtolower((string)$_GET['category']) : 'points';
$minGames = isset($_GET['min_gp']) ? max(0, (int)$_GET['min_gp']) : 3;

$service = new LeadersService(db());
$seasonService = new SeasonService(db());
$season = $seasonId > 0 ? $seasonService->fetchSeason($seasonId) : $seasonService->fetchCurrentSeason();
if ($category === 'goalies') {
  $leaders = $service->fetchGoalieLeaders($season['id'], $limit, $minGames);
} else {
  $leaders = $service->fetchSkaterLeaders($category, $season['id'], $limit);
}

$tabs = [
  'points' => 'Points',
  'goals' => 'Goals',
  'assists' => 'Assists',
  'goalies' => 'Goalies',
];

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
    .tabs { display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
    .tab { padding:8px 14px; border-radius:999px; text-decoration:none; color:#cbd5f5; background:#111c2b; border:1px solid #223148; font-size:14px; }
    .tab.active { background:#1d4ed8; color:#fff; border-color:#1d4ed8; }
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
  <div class="meta">Season <?= $season['id'] ?> · <?= $escape($season['name']) ?> · Top <?= $limit ?></div>

  <div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
      <?php
        $isActive = $key === $category;
        $url = '?season_id='.$season['id'].'&limit='.$limit.'&category='.$key;
        if ($key === 'goalies') {
          $url .= '&min_gp='.$minGames;
        }
      ?>
      <a class="tab <?= $isActive ? 'active' : '' ?>" href="<?= $url ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$leaders): ?>
    <div class="empty">No leader data yet.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th class="rank">#</th>
          <th>Player</th>
          <th>Team</th>
          <th class="right">GP</th>
          <?php if ($category === 'goalies'): ?>
            <th class="right">W</th>
            <th class="right">SV%</th>
            <th class="right">GAA</th>
            <th class="right">SA</th>
          <?php else: ?>
            <th class="right">G</th>
            <th class="right">A</th>
            <th class="right">PTS</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leaders as $index => $row): ?>
          <tr>
            <td class="rank"><?= $index + 1 ?></td>
            <td><?= $escape($row['player_name']) ?></td>
            <td><?= $escape($row['team_name']) ?></td>
            <td class="right"><?= $row['games_played'] ?></td>
            <?php if ($category === 'goalies'): ?>
              <td class="right"><?= $row['wins'] ?></td>
              <td class="right"><?= number_format($row['save_pct'], 1) ?></td>
              <td class="right"><?= number_format($row['gaa'], 2) ?></td>
              <td class="right"><?= $row['shots_against'] ?></td>
            <?php else: ?>
              <td class="right"><?= $row['goals'] ?></td>
              <td class="right"><?= $row['assists'] ?></td>
              <td class="right"><?= $row['points'] ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($category === 'goalies'): ?>
      <div class="meta">Minimum GP: <?= $minGames ?></div>
    <?php endif; ?>
  <?php endif; ?>
</body>
</html>
