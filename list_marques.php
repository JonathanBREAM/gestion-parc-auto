<?php
// list_marques.php – renvoie la liste des marques au format JSON
require_once __DIR__ . '/inc/db.php';

$stmt = $pdo->query('SELECT id, nom FROM marques ORDER BY nom');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>