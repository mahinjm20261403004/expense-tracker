<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function body(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : $_POST;
}

function require_user(): int
{
    if (empty($_SESSION['uid'])) {
        respond(['error' => 'Not authenticated'], 401);
    }
    return (int) $_SESSION['uid'];
}

function push_event(PDO $db, int $userId, string $type, array $payload = []): void
{
    $stmt = $db->prepare('INSERT INTO events (user_id, type, payload) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $type, json_encode($payload)]);
}

function fetch_transaction(PDO $db, int $id, int $userId): ?array
{
    $stmt = $db->prepare(
        'SELECT t.id, t.type, t.amount, t.description, t.occurred_on, t.category_id, c.name AS category_name
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.id = ? AND t.user_id = ?'
    );
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function validate_transaction(PDO $db, int $uid, array $b): array
{
    $errors = [];

    $type = $b['type'] ?? '';
    if (!in_array($type, ['income', 'expense'], true)) {
        $errors['type'] = 'Choose income or expense.';
    }

    $amount = $b['amount'] ?? null;
    if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 9999999999.99) {
        $errors['amount'] = 'Enter an amount greater than zero.';
    }

    $date = (string) ($b['occurred_on'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        $errors['occurred_on'] = 'Enter a valid date.';
    }

    $description = trim((string) ($b['description'] ?? ''));
    if (mb_strlen($description) > 255) {
        $errors['description'] = 'Description must be 255 characters or fewer.';
    }

    $categoryId = $b['category_id'] ?? null;
    if ($categoryId !== null && $categoryId !== '') {
        $stmt = $db->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) $categoryId, $uid]);
        if (!$stmt->fetchColumn()) {
            $errors['category_id'] = 'Invalid category.';
        }
        $categoryId = (int) $categoryId;
    } else {
        $categoryId = null;
    }

    return [$errors, [
        'type'        => $type,
        'amount'      => round((float) $amount, 2),
        'occurred_on' => $date,
        'description' => $description,
        'category_id' => $categoryId,
    ]];
}

/* ------------------------------------------------------------------ */
/* Auth                                                                */
/* ------------------------------------------------------------------ */

if ($action === 'register' && $method === 'POST') {
    $b        = body();
    $username = trim((string) ($b['username'] ?? ''));
    $email    = trim((string) ($b['email'] ?? ''));
    $password = (string) ($b['password'] ?? '');

    $errors = [];
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $errors['username'] = '3-30 letters, numbers or underscore.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($errors) {
        respond(['errors' => $errors], 422);
    }

    $db = db();
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetchColumn()) {
        respond(['errors' => ['username' => 'Username or email already in use.']], 409);
    }

    $token = bin2hex(random_bytes(32));
    $stmt  = $db->prepare('INSERT INTO users (username, email, password_hash, ws_token) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $token]);
    $uid = (int) $db->lastInsertId();

    $ins = $db->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
    foreach (['Food', 'Transport', 'Housing', 'Utilities', 'Entertainment', 'Health', 'Other'] as $cat) {
        $ins->execute([$uid, $cat]);
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    respond(['user' => ['id' => $uid, 'username' => $username], 'ws_token' => $token]);
}

if ($action === 'login' && $method === 'POST') {
    $b        = body();
    $login    = trim((string) ($b['login'] ?? ''));
    $password = (string) ($b['password'] ?? '');

    if ($login === '' || $password === '') {
        respond(['errors' => ['login' => 'Enter your username/email and password.']], 422);
    }

    $db   = db();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(['errors' => ['login' => 'Invalid credentials.']], 401);
    }

    $token = bin2hex(random_bytes(32));
    $db->prepare('UPDATE users SET ws_token = ? WHERE id = ?')->execute([$token, $user['id']]);

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    respond(['user' => ['id' => (int) $user['id'], 'username' => $user['username']], 'ws_token' => $token]);
}

if ($action === 'logout' && $method === 'POST') {
    if (!empty($_SESSION['uid'])) {
        db()->prepare('UPDATE users SET ws_token = NULL WHERE id = ?')->execute([$_SESSION['uid']]);
    }
    $_SESSION = [];
    session_destroy();
    respond(['ok' => true]);
}

if ($action === 'me' && $method === 'GET') {
    if (empty($_SESSION['uid'])) {
        respond(['error' => 'Not authenticated'], 401);
    }
    $stmt = db()->prepare('SELECT id, username, email, ws_token FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch();
    if (!$user) {
        respond(['error' => 'Not authenticated'], 401);
    }
    respond(['user' => ['id' => (int) $user['id'], 'username' => $user['username']], 'ws_token' => (string) $user['ws_token']]);
}

/* ------------------------------------------------------------------ */
/* Categories                                                          */
/* ------------------------------------------------------------------ */

if ($action === 'categories' && $method === 'GET') {
    $uid  = require_user();
    $stmt = db()->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name ASC');
    $stmt->execute([$uid]);
    respond(['categories' => $stmt->fetchAll()]);
}

if ($action === 'categories.create' && $method === 'POST') {
    $uid  = require_user();
    $b    = body();
    $name = trim((string) ($b['name'] ?? ''));

    if ($name === '' || mb_strlen($name) > 60) {
        respond(['errors' => ['name' => 'Category name must be 1-60 characters.']], 422);
    }

    $db   = db();
    $stmt = $db->prepare('SELECT id FROM categories WHERE user_id = ? AND name = ?');
    $stmt->execute([$uid, $name]);
    if ($stmt->fetchColumn()) {
        respond(['errors' => ['name' => 'Category already exists.']], 409);
    }

    $stmt = $db->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
    $stmt->execute([$uid, $name]);
    $id = (int) $db->lastInsertId();

    push_event($db, $uid, 'category.changed', ['id' => $id]);
    respond(['category' => ['id' => $id, 'name' => $name]], 201);
}

if ($action === 'categories.delete' && $method === 'POST') {
    $uid = require_user();
    $b   = body();
    $id  = (int) ($b['id'] ?? 0);

    $db   = db();
    $stmt = $db->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    if (!$stmt->rowCount()) {
        respond(['error' => 'Category not found.'], 404);
    }

    push_event($db, $uid, 'category.changed', ['id' => $id]);
    respond(['ok' => true]);
}

/* ------------------------------------------------------------------ */
/* Transactions                                                        */
/* ------------------------------------------------------------------ */

if ($action === 'transactions' && $method === 'GET') {
    $uid = require_user();

    $sql    = 'SELECT t.id, t.type, t.amount, t.description, t.occurred_on, t.category_id, c.name AS category_name
               FROM transactions t
               LEFT JOIN categories c ON c.id = t.category_id
               WHERE t.user_id = ?';
    $params = [$_SESSION['uid']];

    $from = $_GET['from'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql     .= ' AND t.occurred_on >= ?';
        $params[] = $from;
    }

    $to = $_GET['to'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql     .= ' AND t.occurred_on <= ?';
        $params[] = $to;
    }

    $categoryId = $_GET['category_id'] ?? '';
    if ($categoryId !== '' && $categoryId !== 'all') {
        $sql     .= ' AND t.category_id = ?';
        $params[] = (int) $categoryId;
    }

    $type = $_GET['type'] ?? '';
    if (in_array($type, ['income', 'expense'], true)) {
        $sql     .= ' AND t.type = ?';
        $params[] = $type;
    }

    $sql .= ' ORDER BY t.occurred_on DESC, t.id DESC LIMIT 500';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    respond(['transactions' => $stmt->fetchAll()]);
}

if ($action === 'transactions.create' && $method === 'POST') {
    $uid = require_user();
    [$errors, $data] = validate_transaction(db(), $uid, body());
    if ($errors) {
        respond(['errors' => $errors], 422);
    }

    $db   = db();
    $stmt = $db->prepare(
        'INSERT INTO transactions (user_id, type, amount, category_id, description, occurred_on)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $uid,
        $data['type'],
        $data['amount'],
        $data['category_id'],
        $data['description'],
        $data['occurred_on'],
    ]);
    $id = (int) $db->lastInsertId();

    push_event($db, $uid, 'transaction.created', ['id' => $id]);
    respond(['transaction' => fetch_transaction($db, $id, $uid)], 201);
}

if ($action === 'transactions.update' && $method === 'POST') {
    $uid = require_user();
    $b   = body();
    $id  = (int) ($b['id'] ?? 0);

    $db = db();
    if (!fetch_transaction($db, $id, $uid)) {
        respond(['error' => 'Transaction not found.'], 404);
    }

    [$errors, $data] = validate_transaction($db, $uid, $b);
    if ($errors) {
        respond(['errors' => $errors], 422);
    }

    $stmt = $db->prepare(
        'UPDATE transactions
         SET type = ?, amount = ?, category_id = ?, description = ?, occurred_on = ?
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([
        $data['type'],
        $data['amount'],
        $data['category_id'],
        $data['description'],
        $data['occurred_on'],
        $id,
        $uid,
    ]);

    push_event($db, $uid, 'transaction.updated', ['id' => $id]);
    respond(['transaction' => fetch_transaction($db, $id, $uid)]);
}

if ($action === 'transactions.delete' && $method === 'POST') {
    $uid = require_user();
    $b   = body();
    $id  = (int) ($b['id'] ?? 0);

    $db   = db();
    $stmt = $db->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    if (!$stmt->rowCount()) {
        respond(['error' => 'Transaction not found.'], 404);
    }

    push_event($db, $uid, 'transaction.deleted', ['id' => $id]);
    respond(['ok' => true]);
}

/* ------------------------------------------------------------------ */
/* Summary (dashboard + charts)                                        */
/* ------------------------------------------------------------------ */

if ($action === 'summary' && $method === 'GET') {
    $uid = require_user();
    $db  = db();

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'income'  THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
         FROM transactions WHERE user_id = ?"
    );
    $stmt->execute([$uid]);
    $totals = $stmt->fetch();

    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym, type, SUM(amount) AS total
         FROM transactions
         WHERE user_id = ? AND occurred_on >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY ym, type ORDER BY ym ASC"
    );
    $stmt->execute([$uid]);

    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-{$i} months"));
        $months[$key] = ['ym' => $key, 'income' => 0.0, 'expense' => 0.0];
    }
    foreach ($stmt->fetchAll() as $row) {
        if (isset($months[$row['ym']])) {
            $months[$row['ym']][$row['type']] = (float) $row['total'];
        }
    }

    $stmt = $db->prepare(
        "SELECT c.name, COALESCE(SUM(t.amount), 0) AS total
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = ? AND t.type = 'expense'
         GROUP BY c.id, c.name ORDER BY total DESC"
    );
    $stmt->execute([$uid]);

    respond([
        'totals'     => ['income' => (float) $totals['income'], 'expense' => (float) $totals['expense']],
        'monthly'    => array_values($months),
        'byCategory' => array_map(static fn ($r) => ['name' => $r['name'], 'total' => (float) $r['total']], $stmt->fetchAll()),
    ]);
}

respond(['error' => 'Unknown action.'], 404);