<?php

declare(strict_types=1);

$publicRoot = __DIR__;
$projectRoot = dirname(__DIR__);
$apiRoot = $projectRoot . '/api';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim($uri, '/');

if (str_starts_with($path, '/api/')) {
    $apiPath = realpath($apiRoot . $path);
    if ($apiPath && str_starts_with($apiPath, realpath($apiRoot)) && is_file($apiPath)) {
        require $apiPath;
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'API endpoint not found.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/' || $path === '/index.php') {
    $path = '/replay.html';
}

$publicPath = realpath($publicRoot . $path);
if ($publicPath && str_starts_with($publicPath, realpath($publicRoot)) && is_file($publicPath)) {
    $mime = mime_content_type($publicPath) ?: 'text/plain';
    header("Content-Type: {$mime}");
    readfile($publicPath);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
