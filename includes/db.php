<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';

// Database connection for player data. Override with environment variables in production.
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_DATABASE') ?: 'prohpcl-core';
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
