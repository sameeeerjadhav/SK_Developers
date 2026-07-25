<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function is_staff(): bool
{
    return (current_user()['role'] ?? '') === 'staff';
}

function can_delete(): bool
{
    return is_admin();
}

function can_manage_users(): bool
{
    return is_admin();
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
    // Force password change gate (except profile/logout pages)
    $script = basename($_SERVER['PHP_SELF'] ?? '');
    $allowed = ['profile.php', 'logout.php', 'force-password.php'];
    if (!empty($_SESSION['must_change_password']) && !in_array($script, $allowed, true)) {
        redirect('pages/force-password.php');
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash('error', 'Admin access required.');
        redirect('index.php');
    }
}

function login_is_rate_limited(PDO $pdo, string $email): bool
{
    try {
        $ip = client_ip();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= (NOW() - INTERVAL 15 MINUTE) AND (email = ? OR ip_address = ?)');
        $stmt->execute([strtolower($email), $ip]);
        return (int) $stmt->fetchColumn() >= 8;
    } catch (Throwable $e) {
        return false;
    }
}

function record_login_attempt(PDO $pdo, string $email): void
{
    try {
        $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?,?)')->execute([strtolower($email), client_ip()]);
    } catch (Throwable $e) {
    }
}

function clear_login_attempts(PDO $pdo, string $email): void
{
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE email = ? OR ip_address = ?')->execute([strtolower($email), client_ip()]);
    } catch (Throwable $e) {
    }
}

function attempt_login(PDO $pdo, string $email, string $password): bool
{
    if (login_is_rate_limited($pdo, $email)) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        record_login_attempt($pdo, $email);
        return false;
    }
    if (($user['status'] ?? 'active') === 'disabled') {
        record_login_attempt($pdo, $email);
        return false;
    }

    clear_login_attempts($pdo, $email);
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'    => (int) $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
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
