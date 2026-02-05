<?php
// list_modeles.php – renvoie la liste des modèles avec le nom de leur marque
require_once __DIR__ . '/inc/db.php';

/*
 * On veut trois colonnes :
 *   id          – identifiant du modèle
 *   nom         – nom du modèle
 *   marque_nom  – nom de la marque liée (LEFT JOIN pour gérer les éventuels NULL)
 */
$sql = '
    SELECT m.id,
           m.nom,
           ma.nom AS marque_nom
    FROM modeles m
    LEFT JOIN marques ma ON m.marque_id = ma.id
    ORDER BY m.nom
';
$stmt = $pdo->query($sql);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>