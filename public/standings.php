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
    th.sortable { cursor:pointer; }
    th.sortable:after { content:" ⬍"; font-size:10px; color:#64748b; }
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
          <th class="rank sortable" data-type="number">#</th>
          <th class="sortable" data-type="string">Team</th>
          <th class="right sortable" data-type="number">GP</th>
          <th class="right sortable" data-type="number">W</th>
          <th class="right sortable" data-type="number">L</th>
          <th class="right sortable" data-type="number">T</th>
          <th class="right sortable" data-type="number">GF</th>
          <th class="right sortable" data-type="number">GA</th>
          <th class="right sortable" data-type="number">DIFF</th>
          <th class="right sortable" data-type="number">PTS</th>
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
  <script>
    const table = document.querySelector('table');
    if (table) {
      const getCellValue = (row, index) => row.children[index]?.textContent?.trim() ?? '';
      const sortState = {};
      table.querySelectorAll('th.sortable').forEach((header, index) => {
        header.addEventListener('click', () => {
          const type = header.dataset.type || 'string';
          sortState[index] = !sortState[index];
          const direction = sortState[index] ? 1 : -1;
          const rows = Array.from(table.querySelectorAll('tbody tr'));
          rows.sort((a, b) => {
            const aVal = getCellValue(a, index);
            const bVal = getCellValue(b, index);
            if (type === 'number') {
              return (parseFloat(aVal) - parseFloat(bVal)) * direction;
            }
            return aVal.localeCompare(bVal) * direction;
          });
          const tbody = table.querySelector('tbody');
          rows.forEach(row => tbody.appendChild(row));
        });
      });
    }
  </script>
</body>
</html>
