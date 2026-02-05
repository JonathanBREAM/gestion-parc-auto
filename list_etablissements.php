<?php
require_once __DIR__.'/inc/db.php';   // $pdo

$stmt = $pdo->query('
    SELECT e.id,
           e.nom,
           e.pole_id,
           p.nom AS pole_nom          -- jointure pour récupérer le nom du pôle
    FROM etablissements e
    LEFT JOIN poles p ON e.pole_id = p.id
');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($rows);
?>