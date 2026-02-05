<?php
/* -------------------------------------------------
   delete_vehicle.php – suppression d’un véhicule
   ------------------------------------------------- */

declare(strict_types=1);

/* ---------- 1. Chargement des dépendances ---------- */
require_once __DIR__ . '/inc/db.php';      // $pdo
require_once __DIR__ . '/inc/auth.php';   // fonctions d'authentification éventuelles

/* ---------- 2. Démarrage de la session ---------- */
session_start();

/* ---------- 3. Vérification de l'authentification ---------- */
if (empty($_SESSION['user_id'])) {
    header('Location: index.php?error=auth');
    exit;
}

/* ---------- 4. Timeout de session (30 min) ---------- */
$timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    header('Location: index.php?error=timeout');
    exit;
}
$_SESSION['last_activity'] = time();

/* ---------- 5. Fonction d’échappement (pour affichage) ---------- */
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/* ---------- 6. Récupération de l’ID du véhicule (GET) ---------- */
$vehicleId = $_GET['id'] ?? '';
if (!ctype_digit((string)$vehicleId)) {
    die('Identifiant de véhicule invalide.');
}
$vehicleId = (int)$vehicleId;

/* ---------- 7. Vérifier que le véhicule existe ---------- */
$checkStmt = $pdo->prepare('SELECT id FROM vehicules WHERE id = :vid');
$checkStmt->execute(['vid' => $vehicleId]);
if (!$checkStmt->fetchColumn()) {
    die('Véhicule introuvable.');
}

/* ---------- 8. Suppression des documents liés (tables) ---------- */
$delDocsStmt = $pdo->prepare('DELETE FROM documents WHERE vehicule_id = :vid');
$delDocsStmt->execute(['vid' => $vehicleId]);

/* ---------- 9. Suppression du véhicule ---------- */
$delVehStmt = $pdo->prepare('DELETE FROM vehicules WHERE id = :vid');
$delVehStmt->execute(['vid' => $vehicleId]);

/* ---------- 10. Suppression physique des fichiers ----------
   Structure attendue : uploads/vehicles/<vehicle_id>/… */
$vehicleDir = __DIR__ . "/uploads/vehicles/{$vehicleId}";
if (is_dir($vehicleDir)) {
    /**
     * Supprime récursivement un répertoire.
     * Cette fonction ne suit pas les liens symboliques pour éviter les traversées.
     */
    $removeDirectory = function (string $dir) use (&$removeDirectory) {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    };
    $removeDirectory($vehicleDir);
}

/* ---------- 11. Redirection ----------
   Vous pouvez adapter le paramètre de succès selon votre UI. */
header('Location: dashboard.php?msg=Vehicle+deleted');
exit;
?>