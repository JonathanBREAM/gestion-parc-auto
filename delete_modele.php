<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: index.php?error=auth');
    exit;
}

$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
    header('Location: dashboard.php?error=ID%20invalid');
    exit;
}
$id = (int)$id;

try {
    $stmt = $pdo->prepare('DELETE FROM modeles WHERE id = :id');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Impossible de supprimer (modèle peut être utilisé).');
    }

    $msg = 'Modèle supprimé avec succès.';
} catch (Throwable $e) {
    $msg = 'Erreur : ' . $e->getMessage();
}

header('Location: dashboard.php?msg=' . urlencode($msg));
exit;