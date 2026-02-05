<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';   // $pdo est créé ici

function authenticate(PDO $pdo, string $username, string $password): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, role
         FROM users
         WHERE username = :u
         LIMIT 1'
    );
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    return ($user && password_verify($password, $user['password_hash']))
           ? $user
           : null;
}

function startSecureSession(): void
{
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookieParams['path'],
        'domain'   => $cookieParams['domain'],
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}