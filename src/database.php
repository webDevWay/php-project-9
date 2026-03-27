<?php

namespace App\Database;

if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');

    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

function dbConnection()
{
    $databaseUrl = parse_url($_ENV['DATABASE_URL']) ?? "";

    $username = $databaseUrl['user'] ?? $_ENV['DATABASE_URL']; // janedoe
    $password = $databaseUrl['pass'] ?? $_ENV['DB_URLS_PASS']; // mypassword
    $host = $databaseUrl['host'] ?? $_ENV["DB_URLS_HOST"]; // localhost
    $port = $databaseUrl['port'] ?? $_ENV['DB_URLS_PORT']; // 5432
    $dbName = $databaseUrl['path'] ? ltrim($databaseUrl['path'], '/') : $_ENV['DB_URLS_NAME']; // urls

    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";

        $pdo = new \PDO($dsn, $username, $password);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    } catch (\PDOException $e) {
        echo($e->getMessage());
    }
}
