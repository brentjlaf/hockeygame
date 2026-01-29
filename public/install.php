<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$configRoot = $projectRoot . '/config';
$envPath = $configRoot . '/.env.php';
$schemaFile = $projectRoot . '/schema.sql';

$installed = is_file($envPath);
$errors = [];
$successMessage = null;

function render_field(string $name, string $label, string $value = '', string $type = 'text'): void {
    $escapedValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    echo "<label>{$escapedLabel}<input type=\"{$type}\" name=\"{$name}\" value=\"{$escapedValue}\" required></label>";
}

function write_env_file(string $envPath, string $dbHost, string $dbName, string $dbUser, string $dbPass): bool {
    $envContents = "<?php\nreturn [\n" .
        "    'DB_HOST' => '" . addslashes($dbHost) . "',\n" .
        "    'DB_NAME' => '" . addslashes($dbName) . "',\n" .
        "    'DB_USER' => '" . addslashes($dbUser) . "',\n" .
        "    'DB_PASS' => '" . addslashes($dbPass) . "',\n" .
        "];\n";

    return file_put_contents($envPath, $envContents) !== false;
}

function import_schema(PDO $pdo, string $schemaFile): void {
    $schemaSql = file_get_contents($schemaFile);
    if ($schemaSql === false) {
        throw new RuntimeException('Unable to read schema.sql.');
    }

    $statements = array_filter(array_map('trim', preg_split('/;\\s*\\n/', $schemaSql)));
    foreach ($statements as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        $pdo->exec($statement);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'All fields except password are required.';
    }

    if (empty($errors)) {
        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            $errors[] = 'Unable to connect to the database with the provided credentials.';
        }
    }

    if (empty($errors) && !is_dir($configRoot)) {
        $errors[] = 'Config directory could not be located.';
    }

    if (empty($errors) && !is_file($schemaFile)) {
        $errors[] = 'Schema file could not be found at schema.sql.';
    }

    if (empty($errors)) {
        if (!write_env_file($envPath, $dbHost, $dbName, $dbUser, $dbPass)) {
            $errors[] = 'Failed to write environment file. Please check permissions for config/.';
        } else {
            try {
                import_schema($pdo, $schemaFile);
                $successMessage = 'Installation complete! Database schema imported and configuration saved. You can now visit the app at <a href="/">/</a>.';
            } catch (Throwable $e) {
                $errors[] = 'Database import failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rink Micro-Sim Installer</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 640px; margin: 40px auto; background: white; padding: 24px 28px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { margin-top: 0; }
        form { display: grid; grid-template-columns: 1fr; gap: 14px; }
        label { display: flex; flex-direction: column; font-weight: bold; }
        input { margin-top: 6px; padding: 10px; font-size: 14px; border: 1px solid #ccc; border-radius: 4px; }
        .actions { margin-top: 10px; }
        button { background: #2d7ff9; border: none; color: white; padding: 12px 16px; font-size: 15px; border-radius: 4px; cursor: pointer; }
        .notice { padding: 12px; background: #e8f4ff; border: 1px solid #cddff8; border-radius: 4px; margin-bottom: 12px; }
        .error { padding: 12px; background: #ffecec; border: 1px solid #f5c2c2; border-radius: 4px; color: #a40000; margin-bottom: 12px; }
        .success { padding: 12px; background: #e6ffed; border: 1px solid #b7f5cb; border-radius: 4px; color: #0b6b2a; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Rink Micro-Sim Installer</h1>
    <p>Enter your database details to configure the app. This will create a local environment file and import the MySQL schema.</p>
    <?php if ($installed && !$successMessage): ?>
        <div class="notice">Existing installation detected. Submitting the form will overwrite <code>config/.env.php</code> and re-import the database schema.</div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <div><?php echo $error; ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="success"><?php echo $successMessage; ?></div>
    <?php else: ?>
        <form method="post">
            <?php render_field('db_host', 'Database Host', $_POST['db_host'] ?? 'localhost'); ?>
            <?php render_field('db_name', 'Database Name', $_POST['db_name'] ?? 'hockeysim'); ?>
            <?php render_field('db_user', 'Database User', $_POST['db_user'] ?? 'root'); ?>
            <?php render_field('db_pass', 'Database Password', $_POST['db_pass'] ?? '', 'password'); ?>
            <div class="actions">
                <button type="submit">Install Rink Micro-Sim</button>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
