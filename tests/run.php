<?php
declare(strict_types=1);

/** MySQL-only deployment smoke check; it never writes local files or data. */
if (!extension_loaded('pdo_mysql')) {
    echo "SKIP: PDO MySQL extension is not loaded\n";
    exit(0);
}
$config = require __DIR__ . '/../config.php';
$mysql = $config['mysql'] ?? [];
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $mysql['host'] ?? '127.0.0.1', $mysql['port'] ?? 3306, $mysql['database'] ?? 'holderbot', $mysql['charset'] ?? 'utf8mb4');
try {
    $pdo = new PDO($dsn, $mysql['username'] ?? '', $mysql['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->query('SELECT 1');
    echo "PASS: MySQL connection\n";
} catch (Throwable $error) {
    echo "FAIL: MySQL connection: {$error->getMessage()}\n";
    exit(1);
}
