<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const DB_HOST = 'localhost';
const DB_NAME = 'hockeysim';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function json_in(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Phase 1 auth shortcut:
 * - Pass ?team_id=### in query string.
 * Replace with your real login later.
 */
function current_team_id(): int {
  $tid = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
  if ($tid <= 0) out(['error' => 'Missing team_id (Phase 1 shortcut).'], 400);
  return $tid;
}

/**
 * Simple deterministic PRNG (LCG) so results & template picks are stable per match seed.
 */
final class RNG {
  private int $state;
  public function __construct(int $seed) { $this->state = $seed & 0x7fffffff; }
  private function nextInt(): int {
    $this->state = (1103515245 * $this->state + 12345) & 0x7fffffff;
    return $this->state;
  }
  public function float(): float {
    return $this->nextInt() / 0x7fffffff;
  }
  public function int(int $min, int $max): int {
    if ($max <= $min) return $min;
    $r = $this->nextInt() % (($max - $min) + 1);
    return $min + $r;
  }
}

function hockey_clock(int $gameTimeLeft): string {
  $m = intdiv($gameTimeLeft, 60);
  $s = $gameTimeLeft % 60;
  return sprintf("%d:%02d", $m, $s);
}

function pick_template(array $templates, int $seed, int $salt): string {
  $n = count($templates);
  if ($n <= 0) return '';
  $i = (int)(($seed + $salt) % $n);
  if ($i < 0) $i += $n;
  return $templates[$i];
}

function team_row(int $teamId): array {
  $stmt = db()->prepare("SELECT * FROM teams WHERE id=?");
  $stmt->execute([$teamId]);
  $row = $stmt->fetch();
  if (!$row) out(['error' => 'Team not found.'], 404);
  return $row;
}

function team_players(int $teamId): array {
  $stmt = db()->prepare("SELECT * FROM players WHERE team_id=?");
  $stmt->execute([$teamId]);
  return $stmt->fetchAll();
}

/**
 * Create a basic roster (Phase 1). You can replace with real drafting later.
 * NOTE: uses rand() for convenience; roster generation is not part of deterministic match sim.
 */
function create_basic_roster(int $teamId, int $base = 50): void {
  $pdo = db();
  $names = ["Carter","Novak","Grayson","Miller","Reed","Benson","Hayes","Stone","Cruz","Keller","Fox","Lane","Hart","Wells","Parker","Quinn","Sloane","Ryder","Shaw","Vale"];
  $idx = 0;

  // 12 forwards
  foreach (range(1,12) as $i) {
    $pos = ($i % 3 === 1) ? 'C' : (($i % 3 === 2) ? 'LW' : 'RW');
    $pdo->prepare("INSERT INTO players(team_id,name,pos,shot,pass_attr,speed,defense_attr,grit,goalie)
      VALUES(?,?,?,?,?,?,?,?,?)")->execute([
        $teamId, $names[$idx++ % count($names)]." F$i", $pos,
        $base + rand(-10,10),
        $base + rand(-10,10),
        $base + rand(-10,10),
        $base + rand(-10,10),
        $base + rand(-10,10),
        10
      ]);
  }

  // 6 defense
  foreach (range(1,6) as $i) {
    $pdo->prepare("INSERT INTO players(team_id,name,pos,shot,pass_attr,speed,defense_attr,grit,goalie)
      VALUES(?,?,?,?,?,?,?,?,?)")->execute([
        $teamId, $names[$idx++ % count($names)]." D$i", 'D',
        $base + rand(-10,10),
        $base + rand(-10,10),
        $base + rand(-10,10),
        $base + rand(-10,10),
        $base + rand(-10,10),
        10
      ]);
  }

  // 2 goalies
  foreach (range(1,2) as $i) {
    $pdo->prepare("INSERT INTO players(team_id,name,pos,shot,pass_attr,speed,defense_attr,grit,goalie)
      VALUES(?,?,?,?,?,?,?,?,?)")->execute([
        $teamId, $names[$idx++ % count($names)]." G$i", 'G',
        10, 10, $base + rand(-10,10), $base + rand(-10,10), $base + rand(-10,10),
        $base + rand(0,15)
      ]);
  }
}

function ensure_bot_team_near_rating(int $targetRating): int {
  $pdo = db();

  // Find an existing bot near rating
  $stmt = $pdo->prepare("
    SELECT id FROM teams
    WHERE is_bot=1
    ORDER BY ABS(rating - ?) ASC
    LIMIT 1
  ");
  $stmt->execute([$targetRating]);
  $row = $stmt->fetch();
  if ($row) return (int)$row['id'];

  // Create one if none exist
  $rating = max(700, min(1300, $targetRating + rand(-50,50)));
  $styles = ['BALANCED','SNIPER','GRIT','DEFENSIVE'];
  $style = $styles[array_rand($styles)];

  $pdo->prepare("INSERT INTO teams(user_id,name,rating,is_bot,bot_difficulty,coach_style)
    VALUES(NULL,?,?,?,?,?)")->execute([
      "Bot ".$rating,
      $rating,
      1,
      rand(3,7),
      $style
  ]);
  $botId = (int)$pdo->lastInsertId();

  // Give the bot a roster
  $base = (int)round(($rating - 800) / 10) + 45;
  create_basic_roster($botId, $base);

  return $botId;
}

/**
 * Build a default plan_json from roster if user/bot hasn't submitted yet.
 */
function build_default_plan(int $teamId): array {
  $players = team_players($teamId);

  $F = array_values(array_filter($players, fn($p) => $p['pos'] !== 'D' && $p['pos'] !== 'G'));
  $D = array_values(array_filter($players, fn($p) => $p['pos'] === 'D'));
  $G = array_values(array_filter($players, fn($p) => $p['pos'] === 'G'));

  usort($F, fn($a,$b) => ($b['shot']+$b['pass_attr']+$b['speed']) <=> ($a['shot']+$a['pass_attr']+$a['speed']));
  usort($D, fn($a,$b) => ($b['defense_attr']+$b['pass_attr']+$b['grit']) <=> ($a['defense_attr']+$a['pass_attr']+$a['grit']));
  usort($G, fn($a,$b) => ($b['goalie']) <=> ($a['goalie']));

  $line = function(int $fStart, int $dStart) use ($F,$D) {
    return [
      'F' => [
        $F[$fStart+0]['id'] ?? 0,
        $F[$fStart+1]['id'] ?? 0,
        $F[$fStart+2]['id'] ?? 0,
      ],
      'D' => [
        $D[$dStart+0]['id'] ?? 0,
        $D[$dStart+1]['id'] ?? 0,
      ],
    ];
  };

  return [
    'lines' => [
      'L1' => $line(0,0),
      'L2' => $line(3,2),
      'L3' => $line(6,4),
      'L4' => $line(9,0),
    ],
    'goalie' => $G[0]['id'] ?? 0,
    'tactics' => [
      'aggression' => 55,
      'forecheck'  => 50,
      'shoot_bias' => 60,
      'risk'       => 45,
    ],
  ];
}
