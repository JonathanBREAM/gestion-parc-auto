<?php
require_once __DIR__.'/inc/db.php';

$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
    http_response_code(400);
    exit('Identifiant invalide');
}

$stmt = $pdo->prepare('DELETE FROM poles WHERE id = :id');
$stmt->execute(['id' => (int)$id]);

echo 'Pôle supprimé avec succès.';
?>