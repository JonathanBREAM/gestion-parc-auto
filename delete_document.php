<?php
/* -------------------------------------------------
   delete_document.php – suppression d'un document associé à un véhicule
   ------------------------------------------------- */

declare(strict_types=1);

/* ---------- 1. Chargement des dépendances ---------- */
require_once __DIR__ . '/inc/db.php';      // $pdo
require_once __DIR__ . '/inc/auth.php';   // fonctions d'authentification éventuelles

/* ---------- 2. Démarrage de la session ---------- */
session_start();

/* ---------- 3. Vérification de l'authentification ---------- */
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Utilisateur non authentifié']);
    exit;
}

/* ---------- 4. Lecture et validation des paramètres ---------- */
$type = $_POST['type'] ?? '';
$url  = $_POST['url']  ?? '';

$allowedTypes = ['contract', 'carte_grise', 'controle_technique', 'facture'];

if (!in_array($type, $allowedTypes, true) || empty($url)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

/* ---------- 5. Extraction du vehicle_id et du nom de fichier ----------
   L’URL a le format :
   uploads/vehicles/{vehicleId}/{type}/{filename}
*/
$path = parse_url($url, PHP_URL_PATH);          // garde uniquement le chemin
$segments = explode('/', trim($path, '/'));      // supprime les slashs de bord

// On s’attend à au moins 5 segments : uploads / vehicles / {id} / {type} / {filename}
if (count($segments) < 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Chemin d\'accès invalide']);
    exit;
}

$vehicleId = (int)$segments[2];                 // position 2 → {vehicleId}
$filename  = end($segments);                    // dernier segment → {filename}

/* ---------- 6. Vérification d’appartenance (optionnelle mais recommandée) ----------
   Si votre logique métier lie les véhicules à un utilisateur ou à une société,
   ajoutez ici une requête qui s’assure que l’utilisateur courant a le droit
   de toucher à ce véhicule. Exemple :

   $stmt = $pdo->prepare('SELECT user_id FROM vehicules WHERE id = :vid');
   $stmt->execute(['vid' => $vehicleId]);
   $owner = $stmt->fetchColumn();
   if ((int)$owner !== (int)$_SESSION['user_id']) {
       http_response_code(403);
       echo json_encode(['success' => false, 'error' => 'Accès refusé']);
       exit;
   }
*/

/* ---------- 7. Construction du chemin physique du fichier ---------- */
$realPath = __DIR__ . "/uploads/vehicles/{$vehicleId}/{$type}/{$filename}";

if (!is_file($realPath)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Fichier introuvable']);
    exit;
}

/* ---------- 8. Suppression du fichier ---------- */
if (!unlink($realPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Impossible de supprimer le fichier']);
    exit;
}

/* ---------- 9. Suppression de l’enregistrement en base ---------- */
$delStmt = $pdo->prepare(
    'DELETE FROM documents
     WHERE vehicule_id = :vid
       AND type        = :type
       AND filename    = :filename'
);
$delStmt->execute([
    'vid'      => $vehicleId,
    'type'     => $type,
    'filename' => $filename,
]);

/* ---------- 10. Réponse JSON ---------- */
header('Content-Type: application/json');
echo json_encode(['success' => true]);
exit;
?>