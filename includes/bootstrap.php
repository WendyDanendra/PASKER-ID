<?php
session_start();

define('APP_NAME', 'Karirhub');
define('APP_URL', '');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'paskerid');
define('DB_USER', 'root');
define('DB_PASS', '');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        ensure_platform_schema();
    }

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cachedUser = null;
    static $cachedUserId = null;

    if ($cachedUser !== null && $cachedUserId === (int) $_SESSION['user_id']) {
        return $cachedUser;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$_SESSION['user_id']]);
    $cachedUser = $statement->fetch() ?: null;
    $cachedUserId = $cachedUser['id'] ?? null;

    return $cachedUser;
}

function login_user(array $user): void
{
    $_SESSION['user_id'] = (int) $user['id'];
}

function logout_user(): void
{
    unset($_SESSION['user_id']);
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        redirect('login.php');
    }

    return $user;
}

function role_home(string $role): string
{
    return match ($role) {
        'admin' => 'admin.php',
        'seeker' => 'seeker.php',
        default => 'dashboard.php',
    };
}

function is_profile_complete(array $user): bool
{
    return (int) ($user['profile_complete'] ?? 0) === 1;
}

function require_role(string $role): array
{
    $user = require_login();

    if (($user['role'] ?? '') !== $role) {
        redirect(role_home($user['role'] ?? 'employer'));
    }

    return $user;
}

function find_user_by_email(string $email): ?array
{
    $statement = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);

    return $statement->fetch() ?: null;
}

function create_user(string $name, string $email, string $password, string $role): int
{
    $statement = db()->prepare('INSERT INTO users (name, email, password_hash, role, profile_complete, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    $statement->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $role,
    ]);

    return (int) db()->lastInsertId();
}

function employer_profile_exists(int $userId): bool
{
    $statement = db()->prepare('SELECT COUNT(*) FROM employer_profiles WHERE user_id = ?');
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn() > 0;
}

function seeker_profile_exists(int $userId): bool
{
    $statement = db()->prepare('SELECT COUNT(*) FROM seeker_profiles WHERE user_id = ?');
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn() > 0;
}

require_once __DIR__ . '/platform.php';
