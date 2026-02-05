<?php
require_once __DIR__.'/inc/db.php';   // $pdo déjà configuré

$stmt = $pdo->query('SELECT id, nom FROM poles ORDER BY nom');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($rows);
?>