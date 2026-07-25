<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

function attempt_login(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'    => (int) $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) ($p['secure'] ?? false), (bool) ($p['httponly'] ?? true));
    }
    session_destroy();
}

function require_admin(): void
{
    require_login();
    $user = current_user();
    if (($user['role'] ?? '') !== 'admin') {
        flash('error', 'Admin access required.');
        redirect('index.php');
    }
}
