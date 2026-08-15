<?php
// src/Database.php

declare(strict_types=1);

namespace UAC;

use PDO;
use PDOException;
use RuntimeException;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config.php';
            $dbConfig = $config['db'];
            $driver = strtolower($dbConfig['driver']);

            try {
                if ($driver === 'sqlite') {
                    $sqlitePath = $dbConfig['path'];
                    $dir = dirname($sqlitePath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                    self::$instance = new PDO("sqlite:" . $sqlitePath, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                    self::$instance->exec("PRAGMA foreign_keys = ON;");
                } elseif ($driver === 'pgsql') {
                    $dsn = sprintf(
                        "pgsql:host=%s;port=%d;dbname=%s",
                        $dbConfig['host'],
                        $dbConfig['port'],
                        $dbConfig['database']
                    );
                    self::$instance = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                } elseif ($driver === 'mysql') {
                    $dsn = sprintf(
                        "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
                        $dbConfig['host'],
                        $dbConfig['port'],
                        $dbConfig['database']
                    );
                    self::$instance = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                } else {
                    throw new RuntimeException("Unsupported database driver: {$driver}");
                }
            } catch (PDOException $e) {
                throw new RuntimeException("Database connection error: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return self::$instance;
    }

    public static function setConnection(PDO $pdo): void {
        self::$instance = $pdo;
    }

    public static function initSchema(): void {
        $db = self::getConnection();
        $schemaFile = __DIR__ . '/../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $db->exec($sql);
        }
    }
}
