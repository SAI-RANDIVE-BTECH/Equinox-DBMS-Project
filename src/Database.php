<?php

declare(strict_types=1);

namespace Equinox;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        Env::load(dirname(__DIR__) . '/.env');

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::get('DB_PORT', '3306');
        $db = Env::get('DB_NAME', 'equinox_dbms');
        $user = Env::get('DB_USER', 'root');
        $password = Env::get('DB_PASSWORD', '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

        try {
            self::$pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $exception) {
            Response::json([
                'success' => false,
                'error' => 'Database connection failed',
                'details' => $exception->getMessage(),
            ], 500);
            exit;
        }

        return self::$pdo;
    }
}
