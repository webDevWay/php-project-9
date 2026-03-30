<?php

namespace App\Database;

if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');

    if (is_array($env)) {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

function dbConnection(): ?\PDO
{
    $databaseUrl = getenv('DATABASE_URL') ?: $_ENV['DATABASE_URL'] ?? null;

    if ($databaseUrl) {
        $url = parse_url($databaseUrl);

        $host = $url['host'] ?? 'localhost';
        $port = $url['port'] ?? '5432';
        $dbName = ltrim($url['path'] ?? '', '/');
        $username = $url['user'] ?? 'postgres';
        $password = $url['pass'] ?? '';
    } else {
        $host = $_ENV['DB_URLS_HOST'] ?: getenv('DB_URLS_HOST') ?: 'localhost';
        $port = $_ENV['DB_URLS_PORT'] ?: getenv('DB_URLS_PORT') ?: '5432';
        $dbName = $_ENV['DB_URLS_NAME'] ?: getenv('DB_URLS_NAME') ?: 'urls';
        $username = $_ENV['DB_URLS_USERNAME'] ?: getenv('DB_URLS_USERNAME') ?: 'postgres';
        $password = $_ENV['DB_URLS_PASS'] ?: getenv('DB_URLS_PASS') ?: '';
    }

    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";

        $pdo = new \PDO($dsn, $username, $password);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    } catch (\PDOException $e) {
        error_log($e->getMessage());

        return null;
    }
}
