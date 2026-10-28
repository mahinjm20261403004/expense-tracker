<?php
declare(strict_types=1);

/**
 * PDO singleton for the Personal Expense Tracker.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $cfg = [
            'host'    => '127.0.0.1',
            'port'    => '3306',
            'name'    => 'expense_tracker',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ];

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}