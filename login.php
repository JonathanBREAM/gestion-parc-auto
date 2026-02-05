<?php
declare(strict_types=1);
require_once __DIR__.'/inc/auth.php';   // fonctions
require_once __DIR__.'/inc/db.php';     // $pdo

session_start();   // nécessaire pour le token CSRF

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}

/* CSRF protection */
if (empty($_POST['csrf_token']) ||
    $_POST['csrf_token'] !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    exit('Token CSRF invalide.');
}

/* Nettoyage des champs */
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: index.html?error=empty');
    exit;
}

/* Authentification */
$user = authenticate($pdo, $username, $password);

if ($user) {
    startSecureSession();
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();

    header('Location: dashboard.php');
    exit;
}

/* Échec */
header('Location: index.php');
exit;