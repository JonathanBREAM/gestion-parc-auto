<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
session_start();

/* Authentification */
if (empty($_SESSION['user_id'])) {
    header('Location: index.php?error=auth');
    exit;
}

/* Récupération de l'ID */
$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
    header('Location: dashboard.php?error=ID%20invalid');
    exit;
}
$id = (int)$id;

/* Tentative de suppression */
try {
    $stmt = $pdo->prepare('DELETE FROM etablissements WHERE id = :id');
    $stmt->execute(['id' => $id]);

    // Si aucune ligne n’est affectée → l’ID n’existait pas ou était référencé
    if ($stmt->rowCount() === 0) {
        throw new Exception('Impossible de supprimer (peut-être référencé).');
    }

    $msg = 'Établissement supprimé avec succès.';
} catch (Throwable $e) {
    $msg = 'Erreur : ' . $e->getMessage();
}

/* Redirection */
header('Location: dashboard.php?msg=' . urlencode($msg));
exit;