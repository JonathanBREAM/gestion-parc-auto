<?php
/* -------------------------------------------------
   edit_vehicle.php – modification d’un véhicule + affichage & upload des documents
   ------------------------------------------------- */

declare(strict_types=1);

/* ---------- 1. Chargement des dépendances ---------- */
require_once __DIR__ . '/inc/db.php';      // $pdo
require_once __DIR__ . '/inc/auth.php';   // fonctions d'authentification

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

/* ---------- 5. Fonctions utilitaires ---------- */

/* Échappement HTML */
function esc($value): string
{
    if ($value === null) {
        $value = '';
    }
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        $value = (string)$value;
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/* Helper « value » (récupération avec fallback) */
function value(string $key, array $src, $fallback = '')
{
    return $src[$key] ?? $fallback;
}

/* Retourne les URLs relatives des documents déjà stockés */
function listVehicleDocs(int $vehicleId, string $type): array
{
    $baseDir = __DIR__ . "/uploads/vehicles/{$vehicleId}/{$type}";
    $baseUrl = "uploads/vehicles/{$vehicleId}/{$type}";

    if (!is_dir($baseDir)) {
        return [];
    }

    $files = [];
    foreach (scandir($baseDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_file("$baseDir/$entry")) {
            $files[] = $baseUrl . '/' . rawurlencode($entry);
        }
    }
    return $files;
}

/* Gestion de l’upload d’un ou plusieurs fichiers */
function handleUpload(int $vehicleId, string $type, array $filesArray): void
{
    global $pdo;

    $targetDir = __DIR__ . "/uploads/vehicles/{$vehicleId}/{$type}";
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        error_log("Impossible de créer le répertoire $targetDir");
        throw new RuntimeException("Impossible de créer le répertoire $targetDir");
    }

    /* -------- Normalisation du tableau $_FILES -------- */
    $files = [];
    if (isset($filesArray['name']) && is_array($filesArray['name'])) {
        $cnt = count($filesArray['name']);
        for ($i = 0; $i < $cnt; $i++) {
            $files[] = [
                'name'     => $filesArray['name'][$i],
                'type'     => $filesArray['type'][$i],
                'tmp_name' => $filesArray['tmp_name'][$i],
                'error'    => $filesArray['error'][$i],
                'size'     => $filesArray['size'][$i],
            ];
        }
    } else {
        $files[] = $filesArray; // upload simple
    }

    foreach ($files as $file) {

        /* ---------- 1️⃣ Vérification de l’extension ---------- */
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext === '') {
            error_log('Upload refusé : le fichier "' . $file['name'] . '" n’a pas d\'extension.');
            continue;
        }

        if (!in_array($ext, $allowed, true)) {
            error_log('Upload refusé : extension non autorisée "' . $ext .
                      '" pour le fichier "' . $file['name'] . '".');
            continue;
        }

        /* ---------- 2️⃣ Taille ---------- */
        if ($file['size'] > 5 * 1024 * 1024) {
            error_log('Upload refusé : taille > 5 Mo pour le fichier "' .
                      $file['name'] . '".');
            continue;
        }

        /* ---------- 3️⃣ Nom sécurisé ---------- */
        $safeName = uniqid('doc_', true) . '.' . $ext;
        $safeName = rtrim($safeName, '.');

        $destPath = $targetDir . '/' . $safeName;

        /* ---------- 4️⃣ Déplacement du fichier ---------- */
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $last = error_get_last();
            $msg = $last['message'] ?? 'unknown';
            error_log("move_uploaded_file failed (src={$file['tmp_name']}, dest={$destPath}) : $msg");
            continue;
        }

        /* ---------- 5️⃣ Insertion en base ---------- */
        $verStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM documents WHERE vehicule_id = :vid AND type = :type'
        );
        $verStmt->execute(['vid' => $vehicleId, 'type' => $type]);
        $version = (int)$verStmt->fetchColumn() + 1;

        $ins = $pdo->prepare(
            'INSERT INTO documents (vehicule_id, type, version, filename, uploaded_at)
             VALUES (:vid, :type, :ver, :fname, NOW())'
        );

        try {
            $ins->execute([
                'vid'   => $vehicleId,
                'type'  => $type,
                'ver'   => $version,
                'fname' => $safeName,
            ]);
        } catch (PDOException $e) {
            error_log('DB insert error for file ' . $safeName . ': ' . $e->getMessage());
            throw $e;
        }
    }
}

/* ---------- 6. Récupération de l'ID du véhicule (GET) ---------- */
$vehicleId = $_GET['id'] ?? '';
if (!ctype_digit((string)$vehicleId)) {
    die('Identifiant de véhicule invalide.');
}
$vehicleId = (int)$vehicleId;

/* ---------- 7. Chargement du véhicule existant ---------- */
$stmt = $pdo->prepare(
    'SELECT
        v.*,
        m.marque_id AS marque_id
     FROM vehicules v
     LEFT JOIN modeles m ON v.modele_id = m.id
     WHERE v.id = :vid'
);
$stmt->execute(['vid' => $vehicleId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    die('Véhicule introuvable.');
}

/* ---------- 8. Listes déroulantes (marques & établissements) ---------- */
function fetchAll(PDO $pdo, string $table, string $orderBy = 'nom')
{
    $stmt = $pdo->query("SELECT id, nom FROM $table ORDER BY $orderBy");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$etablissements = fetchAll($pdo, 'etablissements');
$marques        = fetchAll($pdo, 'marques');

/* Tous les modèles (pour le filtrage JS) */
$allModelesStmt = $pdo->query('SELECT id, nom, marque_id FROM modeles ORDER BY nom');
$allModeles     = $allModelesStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 9. Documents déjà associés au véhicule ---------- */
$docStmt = $pdo->prepare(
    'SELECT id, type, version, filename, uploaded_at
     FROM documents
     WHERE vehicule_id = :vid
     ORDER BY type, version DESC'
);
$docStmt->execute(['vid' => $vehicleId]);
$documentsRaw = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$documents = [
    'contract'          => [],
    'carte_grise'       => [],
    'controle_technique'=> [],
    'facture'           => []
];
foreach ($documentsRaw as $doc) {
    $documents[$doc['type']][] = $doc;
}

/* ---------- 10. Traitement du formulaire (POST) ---------- */
$errors   = [];
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ----- 10.1 Récupération & nettoyage des champs ----- */
    $etablissementId = trim($_POST['etablissement_id'] ?? '');
    $marqueId        = trim($_POST['marque_id'] ?? '');
    $modeleId        = trim($_POST['modele_id'] ?? '');
    $immatriculation = trim($_POST['immatriculation'] ?? '');
    $mensualite      = trim($_POST['mensualite'] ?? '');
    $debutLeasing    = trim($_POST['debut_leasing'] ?? '');
    $kilometrage     = trim($_POST['kilometrage'] ?? '');
    $entretien1      = trim($_POST['entretien1'] ?? '');
    $entretien2      = trim($_POST['entretien2'] ?? '');
    $entretien3      = trim($_POST['entretien3'] ?? '');
    $controle1       = trim($_POST['controle1'] ?? '');
    $controle2       = trim($_POST['controle2'] ?? '');
    $controle3       = trim($_POST['controle3'] ?? '');
    $controle4       = trim($_POST['controle4'] ?? '');
    $controle5       = trim($_POST['controle5'] ?? '');
    $nombrePlaces    = trim($_POST['nombre_de_place'] ?? '');
    $typeVehicule    = trim($_POST['type_vehicule'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');

    $oldInput = [
        'etablissement_id' => $etablissementId,
        'marque_id'        => $marqueId,
        'modele_id'        => $modeleId,
        'immatriculation'  => $immatriculation,
        'mensualite'       => $mensualite,
        'debut_leasing'    => $debutLeasing,
        'kilometrage'      => $kilometrage,
        'entretien1'       => $entretien1,
        'entretien2'       => $entretien2,
        'entretien3'       => $entretien3,
        'controle1'        => $controle1,
        'controle2'        => $controle2,
        'controle3'        => $controle3,
        'controle4'        => $controle4,
        'controle5'        => $controle5,
        'nombre_de_place'  => $nombrePlaces,
        'type_vehicule'    => $typeVehicule,
        'notes'            => $notes,
    ];

    /* ----- 10.2 Validation basique ----- */
    if ($etablissementId === '' || !ctype_digit($etablissementId)) {
        $errors[] = 'Veuillez sélectionner un établissement valide.';
    }
    if ($marqueId === '' || !ctype_digit($marqueId)) {
        $errors[] = 'Veuillez sélectionner une marque valide.';
    }
    if ($modeleId === '' || !ctype_digit($modeleId)) {
        $errors[] = 'Veuillez sélectionner un modèle valide.';
    }
    if ($immatriculation === '') {
        $errors[] = 'L’immatriculation est obligatoire.';
    }
    if ($mensualite === '' || !is_numeric(str_replace(',', '.', $mensualite))) {
        $errors[] = 'La mensualité doit être un nombre.';
    }
    if ($debutLeasing === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $debutLeasing)) {
        $errors[] = 'La date de début de leasing doit être au format AAAA-MM-JJ.';
    }
    if ($kilometrage !== '' && !ctype_digit(str_replace(' ', '', $kilometrage))) {
        $errors[] = 'Le kilométrage doit être un entier.';
    }
    foreach (['entretien1','entretien2','entretien3','controle1','controle2','controle3','controle4','controle5'] as $field) {
        if ($$field !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $$field)) {
            $errors[] = "La date {$field} doit être au format AAAA-MM-JJ.";
        }
    }
    if ($nombrePlaces !== '' && (!ctype_digit($nombrePlaces) || (int)$nombrePlaces < 1)) {
        $errors[] = 'Le nombre de places doit être un entier positif.';
    }
    if ($typeVehicule !== '' && !in_array($typeVehicule, ['Commercial','non commercial'], true)) {
        $errors[] = 'Le type de véhicule doit être « Commercial » ou « non commercial ».';
    }

    /* ----- 10.3 Vérifier que le modèle appartient à la marque ----- */
    $stmt = $pdo->prepare('SELECT marque_id FROM modeles WHERE id = :mid');
    $stmt->execute(['mid' => $modeleId]);
    $modelRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$modelRow) {
        $errors[] = 'Le modèle sélectionné n’existe pas.';
    } elseif ((int)$modelRow['marque_id'] !== (int)$marqueId) {
        $errors[] = 'Le modèle choisi ne correspond pas à la marque sélectionnée.';
    }

    /* ----- 10.4 Immatriculation unique (sauf si inchangée) ----- */
    if ($immatriculation !== $vehicle['immatriculation']) {
        $stmt = $pdo->prepare('SELECT id FROM vehicules WHERE immatriculation = :imm');
        $stmt->execute(['imm' => $immatriculation]);
        if ($stmt->fetch()) {
            $errors[] = 'Cette immatriculation existe déjà dans le parc.';
        }
    }

    /* -------------------------------------------------
    10.6 – Helper to add one year to a date (YYYY‑MM‑DD)
    ------------------------------------------------- */
    function addYears(string $date, int $years = 1): string
    {
        $dt = new DateTimeImmutable($date);
        return $dt->modify('+' . $years . ' year')->format('Y-m-d');
    }
    function addOneYear(string $date): string
    {
        $dt = new DateTimeImmutable($date);
        return $dt->modify('+1 year')->format('Y-m-d');
    }

    /* -------------------------------------------------
    10.7 – Compute date_futur_entretien
    ------------------------------------------------- */
    $dateFuturEntretien = null;   // default → NULL (will stay NULL if no base date)

    $typeVehicule = trim($_POST['type_vehicule'] ?? '');

    if (strcasecmp($typeVehicule, 'non commercial') === 0) {
        // non commercial → +2 years
        $x = 2;
    } elseif (strcasecmp($typeVehicule, 'Commercial') === 0) {
        // Commercial → +1 year
        $x = 1;
    } else {
        // Fallback (should never happen because of validation)
        $x = 2;
    }


    if (!empty($entretien3)) {
        $dateFuturEntretien = addOneYear($entretien3);
    } elseif (!empty($entretien2)) {
        $dateFuturEntretien = addOneYear($entretien2);
    } elseif (!empty($entretien1)) {
        $dateFuturEntretien = addOneYear($entretien1);
    } else {
        $postedDebutLeasing = $_POST['debut_leasing'] ?? '';
        if ($postedDebutLeasing !== '' && $postedDebutLeasing !== $vehicle['debut_leasing']) {
            $dateFuturEntretien = addYears($postedDebutLeasing, $x);
        }
    }

    /* -------------------------------------------------
    10.8 – Compute date_futur_control
    ------------------------------------------------- */
    $dateFuturControl = null;   // default → NULL (will stay NULL if no base date)

    if (!empty($controle5)) {
        $dateFuturControl = addYears($controle5, $x);
    } elseif (!empty($controle4)) {
        $dateFuturControl = addYears($controle4, $x);
    } elseif (!empty($controle3)) {
        $dateFuturControl = addYears($controle3, $x);
    } elseif (!empty($controle2)) {
        $dateFuturControl = addYears($controle2, $x);
    } elseif (!empty($controle1)) {
        $dateFuturControl = addYears($controle1, $x);
    } else {
        /**
         * Fallback to the posted debut_leasing **only if** the posted value
         * differs from the value already stored in the DB.
         *
         * $vehicle['debut_leasing'] holds the current value from the DB.
         */
        $postedDebutLeasing = $_POST['debut_leasing'] ?? '';
        if ($postedDebutLeasing !== '' && $postedDebutLeasing !== $vehicle['debut_leasing']) {
            $dateFuturControl = addYears($postedDebutLeasing, $x);
        }
    }
    // If none of the above supplied a date, $dateFuturEntretien remains NULL.
    /* ----- 10.5 Si aucune erreur, on met à jour le véhicule ----- */
    if (empty($errors)) {
        $update = $pdo->prepare(
            'UPDATE vehicules SET
                etablissement_id   = :etab,
                immatriculation    = :imm,
                modele_id          = :modele,
                mensualite         = :mens,
                debut_leasing      = :debut,
                km_initial         = :km,
                date_entretien1    = :ent1,
                date_entretien2    = :ent2,
                date_entretien3    = :ent3,
                Date_contol1       = :cont1,
                Date_contol2       = :cont2,
                Date_contol3       = :cont3,
                Date_contol4       = :cont4,
                Date_contol5       = :cont5,
                date_futur_control = :futureControl,   /* <-- NEW */
                date_futur_entretien = :futureEntretien,   /* <-- NEW */
                nombre_de_place    = :places,
                type_vehicule      = :type,
                notes              = :notes,
                photo              = COALESCE(:photo, photo)
            WHERE id = :vid'
        );

        /* ----- 10.5 Gestion du champ photo (upload éventuel) ----- */
        $photoFileName = null;                       // sera stocké en base si un fichier est fourni
        if (!empty($_FILES['photo']['name'])) {

            // Autoriser uniquement les images (jpg, jpeg, png)
            $allowedImg = ['jpg','jpeg','png'];
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

            // 1️⃣ Vérifications de base
            if (!in_array($ext, $allowedImg, true)) {
                $errors[] = 'Photo : extension autorisée uniquement jpg, jpeg ou png.';
            } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Photo : taille maximale 5 Mo.';
            } else {
                // 2️⃣ Générer un nom sécurisé
                $photoFileName = uniqid('photo_', true) . '.' . $ext;

                // 3️⃣ Chemin cible : uploads/vehicles/<id>/photo/
                $photoDir = __DIR__ . "/uploads/vehicles/{$vehicleId}/photo";
                if (!is_dir($photoDir) && !mkdir($photoDir, 0755, true)) {
                    $errors[] = 'Impossible de créer le répertoire photo du véhicule.';
                }

                // 4️⃣ Déplacer le fichier
                if (empty($errors)) {
                    $destPath = $photoDir . '/' . $photoFileName;
                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destPath)) {
                        $errors[] = 'Erreur lors du déplacement de la photo.';
                    }
                }
            }
        }

        // Si la photo a échoué, on ne poursuit pas la mise à jour
        if (!empty($errors)) {
            // on affichera les erreurs plus bas
        } else {
            $update->execute([
                'etab'   => (int)$etablissementId,
                'imm'    => $immatriculation,
                'modele' => (int)$modeleId,
                'mens'   => str_replace(',', '.', $mensualite),
                'debut'  => $debutLeasing,
                'km'        => $kilometrage !== '' ? (int)str_replace(' ', '', $kilometrage) : null,
                'ent1'      => $entretien1 !== '' ? $entretien1 : null,
                'ent2'      => $entretien2 !== '' ? $entretien2 : null,
                'ent3'      => $entretien3 !== '' ? $entretien3 : null,
                'cont1'     => $controle1 !== '' ? $controle1 : null,
                'cont2'     => $controle2 !== '' ? $controle2 : null,
                'cont3'     => $controle3 !== '' ? $controle3 : null,
                'cont4'     => $controle4 !== '' ? $controle4 : null,
                'cont5'     => $controle5 !== '' ? $controle5 : null,
                'futureControl' => $dateFuturControl,   // <-- NEW binding
                'futureEntretien' => $dateFuturEntretien,
                'places'    => $nombrePlaces !== '' ? (int)$nombrePlaces : 4,
                'type'      => $typeVehicule !== '' ? $typeVehicule : 'non commercial',
                'notes'     => $notes !== '' ? $notes : null,
                'photo'     => $photoFileName,
                'vid'       => $vehicleId,
            ]);
        }

        /* ----- 10.6 Gestion des uploads de documents (si aucune erreur persistante) ----- */
        if (empty($errors)) {
            // Contrat
            if (!empty($_FILES['contract']['name'])) {
                handleUpload($vehicleId, 'contract', $_FILES['contract']);
            }
            // Carte Grise
            if (!empty($_FILES['carte_grise']['name'])) {
                handleUpload($vehicleId, 'carte_grise', $_FILES['carte_grise']);
            }
            // Contrôle Technique
            if (!empty($_FILES['controle_technique']['name'])) {
                handleUpload($vehicleId, 'controle_technique', $_FILES['controle_technique']);
            }
            // Factures (multiple)
            if (!empty($_FILES['factures']['name'][0])) {
                handleUpload($vehicleId, 'facture', $_FILES['factures']);
            }

            // Redirection vers le tableau de bord ou page de confirmation
            header('Location: dashboard.php?msg=Vehicle%20updated');
            exit;
        }
    }
}

/* ---------- 11. Valeurs initiales du formulaire (GET ou POST invalide) ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($errors)) {
    // Si on vient d’une requête GET, on pré‑remplit avec les données de la base
    $oldInput = [
        'etablissement_id' => $vehicle['etablissement_id'],
        'marque_id'        => $vehicle['marque_id'],
        'modele_id'        => $vehicle['modele_id'],
        'immatriculation'  => $vehicle['immatriculation'],
        'mensualite'       => $vehicle['mensualite'],
        'debut_leasing'    => $vehicle['debut_leasing'],
        'kilometrage'      => $vehicle['km_initial'],
        'entretien1'       => $vehicle['date_entretien1'],
        'entretien2'       => $vehicle['date_entretien2'],
        'entretien3'       => $vehicle['date_entretien3'],
        'controle1'        => $vehicle['Date_contol1'],
        'controle2'        => $vehicle['Date_contol2'],
        'controle3'        => $vehicle['Date_contol3'],
        'controle4'        => $vehicle['Date_contol4'],
        'controle5'        => $vehicle['Date_contol5'],
        'nombre_de_place'  => $vehicle['nombre_de_place'],
        'type_vehicule'    => $vehicle['type_vehicule'],
        'notes'            => $vehicle['notes'],
    ];
}

/* ---------- 12. Affichage HTML ---------- */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Modifier le véhicule #<?= esc($vehicleId) ?></title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7f9;padding:2rem;}
        .container{background:#fff;padding:1.5rem;border-radius:6px;
                   max-width:800px;margin:auto;box-shadow:0 2px 6px rgba(0,0,0,.1);}
        h2{margin-top:0;}
        label{display:block;margin-top:.8rem;}
        input, select, textarea{width:100%;padding:.5rem;margin-top:.3rem;
                                 border:1px solid #ccc;border-radius:4px;}
        .btn{margin-top:1rem;padding:.6rem 1rem;background:#0066cc;color:#fff;
             border:none;border-radius:4px;cursor:pointer;}
        .btn:hover{background:#004999;}
        .error{color:#d00;margin-top:.5rem;}
        .msg{color:#28a745;margin-top:.5rem;}
        .doc-section{margin-top:2rem;padding:1rem;background:#f9f9f9;border-radius:4px;}
        .doc-section h3{margin-top:0;}
        .doc-list{list-style:none;padding-left:0;}
        .doc-list li{margin-bottom:.5rem;}
        .doc-list a{color:#0066cc;text-decoration:none;}
        .doc-list a:hover{text-decoration:underline;}
        #docModal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;
                  background:rgba(0,0,0,0.5);justify-content:center;align-items:center;}
        #docModal .inner{background:#fff;padding:1.5rem;border-radius:6px;
                         max-width:600px;max-height:80vh;overflow:auto;}
    </style>
    <script>
        // -------------------------------------------------
        // Filtrage dynamique des modèles selon la marque
        // -------------------------------------------------
        const ALL_MODELS = <?= json_encode($allModeles, JSON_UNESCAPED_UNICODE) ?>;
        const PRESELECTED_MODEL_ID = <?= json_encode($oldInput['modele_id'] ?? 0) ?>;

        function filterModels() {
            const marqueSelect = document.getElementById('marque_id');
            const modeleSelect = document.getElementById('modele_id');
            const selectedMarque = parseInt(marqueSelect.value, 10);

            // Réinitialiser le <select> des modèles
            modeleSelect.innerHTML = '<option value="">Sélectionner un modèle</option>';

            // Ajouter uniquement les modèles qui appartiennent à la marque choisie
            ALL_MODELS.forEach(m => {
                if (parseInt(m.marque_id, 10) === selectedMarque) {
                    const opt = document.createElement('option');
                    opt.value = m.id;
                    opt.textContent = m.nom;
                    if (m.id == PRESELECTED_MODEL_ID) {
                        opt.selected = true;
                    }
                    modeleSelect.appendChild(opt);
                }
            });
        }

        // Appliquer le filtrage dès le chargement (pré‑sélection du modèle)
        window.addEventListener('DOMContentLoaded', () => {
            filterModels();   // la marque déjà sélectionnée déclenchera le bon filtrage
        });

        // -----------------------------------------------------------------
        // Gestion du modal d’affichage des documents (voir / supprimer)
        // -----------------------------------------------------------------
        function showDocs(type) {
            const docs = window.vehicleDocs[type] || [];

            const titleMap = {
                contract: 'Contrats',
                carte_grise: 'Cartes Grises',
                controle_technique: 'Contrôles Techniques',
                facture: 'Factures'
            };
            document.getElementById('modalTitle').textContent = titleMap[type] || 'Documents';

            const listEl = document.getElementById('modalList');
            listEl.innerHTML = '';

            docs.forEach((url, idx) => {
                const li = document.createElement('li');

                const a = document.createElement('a');
                a.href = url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = decodeURIComponent(url.split('/').pop());

                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.textContent = '🗑️ Supprimer';
                delBtn.style.marginLeft = '0.5rem';
                delBtn.dataset.type = type;
                delBtn.dataset.url  = url;
                delBtn.onclick = deleteDocument;

                li.appendChild(a);
                li.appendChild(delBtn);
                listEl.appendChild(li);
            });

            document.getElementById('docModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('docModal').style.display = 'none';
        }

        async function deleteDocument(event) {
            const btn  = event.currentTarget;
            const type = btn.dataset.type;
            const url  = btn.dataset.url;

            if (!confirm('Êtes‑vous sûr·e de vouloir supprimer ce document ?')) {
                return;
            }

            const formData = new FormData();
            formData.append('type', type);
            formData.append('url',  url);
            // Ajoutez un token CSRF ici si nécessaire :
            // formData.append('csrf_token', csrfToken);

            try {
                const resp = await fetch('delete_document.php', {
                    method: 'POST',
                    body:   formData,
                });
                const result = await resp.json();

                if (result.success) {
                    btn.parentElement.remove();
                    alert('Document supprimé.');
                } else {
                    alert('Erreur : ' + (result.error || 'Impossible de supprimer le document.'));
                }
            } catch (e) {
                console.error(e);
                alert('Une erreur réseau est survenue.');
            }
        }
    </script>
</head>
<body>
<div class="container">
    <h2>Modifier le véhicule #<?= esc($vehicleId) ?></h2>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= esc($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST"
          action="edit_vehicle.php?id=<?= esc($vehicleId) ?>"
          enctype="multipart/form-data">

        <!-- Photo du véhicule -->
        <label for="photo">Photo du véhicule (JPG/PNG – max 5 Mo)</label>
        <?php
        // Affiche un aperçu si une photo existe déjà
        $existingPhoto = $vehicle['photo'] ?? '';
        if ($existingPhoto):
            $photoUrl = "uploads/vehicles/{$vehicleId}/photo/{$existingPhoto}";
        ?>
            <div style="margin-bottom:0.5rem;">
                <img src="<?= esc($photoUrl) ?>" alt="Photo du véhicule"
                     style="max-width:200px;height:auto;">
            </div>
        <?php endif; ?>
        <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png">

        <!-- Établissement -->
        <label for="etablissement_id">Établissement *</label>
        <select name="etablissement_id" id="etablissement_id" required>
            <option value="">Sélectionner</option>
            <?php foreach ($etablissements as $e): ?>
                <option value="<?= $e['id'] ?>"
                    <?= (value('etablissement_id', $oldInput) == $e['id']) ? 'selected' : '' ?>>
                    <?= esc($e['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Marque -->
        <label for="marque_id">Marque *</label>
        <select name="marque_id" id="marque_id" required onchange="filterModels()">
            <option value="">Sélectionner</option>
            <?php foreach ($marques as $ma): ?>
                <option value="<?= $ma['id'] ?>"
                    <?= (value('marque_id', $oldInput) == $ma['id']) ? 'selected' : '' ?>>
                    <?= esc($ma['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Modèle (filtré dynamiquement) -->
        <label for="modele_id">Modèle *</label>
        <select name="modele_id" id="modele_id" required>
            <option value="">Sélectionner</option>
            <!-- Options injectées par JavaScript -->
        </select>

        <!-- Nombre de places -->
        <label for="nombre_de_place">Nombre de places *</label>
        <input type="number" id="nombre_de_place" name="nombre_de_place"
               min="1"
               value="<?= esc(value('nombre_de_place', $oldInput)) ?>" required>

        <!-- Type de véhicule -->
        <label for="type_vehicule">Type de véhicule *</label>
        <select id="type_vehicule" name="type_vehicule">
            <option value="non commercial"
                <?= (value('type_vehicule', $oldInput) === 'non commercial') ? 'selected' : '' ?>>
                Non commercial
            </option>
            <option value="Commercial"
                <?= (value('type_vehicule', $oldInput) === 'Commercial') ? 'selected' : '' ?>>
                Commercial
            </option>
        </select>

        <!-- Immatriculation -->
        <label for="immatriculation">Immatriculation *</label>
        <input type="text" id="immatriculation" name="immatriculation"
               value="<?= esc(value('immatriculation', $oldInput)) ?>" required>

        <!-- Mensualité -->
        <label for="mensualite">Mensualité (€/mois) *</label>
        <input type="text" id="mensualite" name="mensualite"
               placeholder="ex. 396,00"
               value="<?= esc(value('mensualite', $oldInput)) ?>" required>

        <!-- Début du leasing -->
        <label for="debut_leasing">Début du leasing (AAAA-MM-JJ) *</label>
        <input type="date" id="debut_leasing" name="debut_leasing"
               value="<?= esc(value('debut_leasing', $oldInput)) ?>" required>

        <!-- Kilométrage -->
        <label for="kilometrage">Kilométrage (km) – optionnel</label>
        <input type="number" id="kilometrage" name="kilometrage"
               min="0"
               value="<?= esc(value('kilometrage', $oldInput)) ?>">

        <!-- Notes -->
        <label for="notes">Notes (optionnel)</label>
        <textarea id="notes" name="notes" rows="4"><?= esc(value('notes', $oldInput)) ?></textarea>

        <hr style="margin:2rem 0;">

        <!-- Dates d'entretien -->
        <label for="entretien1">Date du premier entretien réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="entretien1" name="entretien1"
               value="<?= esc(value('entretien1', $oldInput)) ?>">

        <label for="entretien2">Date du second entretien réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="entretien2" name="entretien2"
               value="<?= esc(value('entretien2', $oldInput)) ?>">

        <label for="entretien3">Date du troisième entretien réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="entretien3" name="entretien3"
               value="<?= esc(value('entretien3', $oldInput)) ?>">

        <hr style="margin:2rem 0;">

        <!-- Dates de contrôle technique -->
        <label for="controle1">Date du premier contrôle réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="controle1" name="controle1"
               value="<?= esc(value('controle1', $oldInput)) ?>">

        <label for="controle2">Date du second contrôle réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="controle2" name="controle2"
               value="<?= esc(value('controle2', $oldInput)) ?>">

        <label for="controle3">Date du troisième contrôle réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="controle3" name="controle3"
               value="<?= esc(value('controle3', $oldInput)) ?>">

        <!-- NEW – contrôle technique 4 -->
        <label for="controle4">Date du quatrième contrôle réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="controle4" name="controle4"
               value="<?= esc(value('controle4', $oldInput)) ?>">

        <!-- NEW – contrôle technique 5 -->
        <label for="controle5">Date du cinquième contrôle réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="controle5" name="controle5"
               value="<?= esc(value('controle5', $oldInput)) ?>">

        <!-- ==== Gestion des documents existants ==== -->
        <hr style="margin:2rem 0;">

        <label for="contract">Nouveau Contrat (PDF/JPG/PNG – max 5 Mo)</label>
        <?php $contractLinks = listVehicleDocs($vehicleId, 'contract'); ?>
        <?php if (!empty($contractLinks)): ?>
            <a href="#" onclick="showDocs('contract');return false;"
               style="margin-left:0.5rem;font-size:0.9em;">👁️ Voir les contrats</a>
        <?php endif; ?>
        <input type="file" id="contract" name="contract" accept=".pdf,.jpg,.jpeg,.png">

        <label for="carte_grise">Nouvelle Carte Grise (PDF/JPG/PNG – max 5 Mo)</label>
        <?php $carteLinks = listVehicleDocs($vehicleId, 'carte_grise'); ?>
        <?php if (!empty($carteLinks)): ?>
            <a href="#" onclick="showDocs('carte_grise');return false;"
               style="margin-left:0.5rem;font-size:0.9em;">👁️ Voir les cartes grises</a>
        <?php endif; ?>
        <input type="file" id="carte_grise" name="carte_grise" accept=".pdf,.jpg,.jpeg,.png">

        <label for="controle_technique">
            Nouveau Contrôle Technique (PDF/JPG/PNG – max 5 Mo)
        </label>
        <?php
        $ctLinks = listVehicleDocs($vehicleId, 'controle_technique');
        if (!empty($ctLinks)):
        ?>
            <a href="#" onclick="showDocs('controle_technique');return false;"
               style="margin-left:0.5rem;font-size:0.9em;">👁️ Voir les contrôles techniques</a>
        <?php endif; ?>
        <input type="file"
               id="controle_technique"
               name="controle_technique"
               accept=".pdf,.jpg,.jpeg,.png">

        <!-- -------------------------------------------------
             Factures (possibilité d’en télécharger plusieurs)
             ------------------------------------------------- -->
        <?php
        $factureLinks = listVehicleDocs($vehicleId, 'facture');
        ?>
        <label for="factures">
            Nouvelles Factures (PDF/JPG/PNG – max 5 Mo chacune, plusieurs possibles)
        </label>
        <?php if (!empty($factureLinks)): ?>
            <a href="#" onclick="showDocs('facture');return false;"
               style="margin-left:0.5rem;font-size:0.9em;">👁️ Voir les factures</a>
        <?php endif; ?>
        <input type="file"
               id="factures"
               name="factures[]"
               accept=".pdf,.jpg,.jpeg,.png"
               multiple>

        <!-- -------------------------------------------------
             Boutons d’action
             ------------------------------------------------- -->
        <button type="submit" class="btn">Enregistrer les modifications</button>
        <a href="dashboard.php"
           class="btn"
           style="background:#777;margin-left:0.5rem;">Annuler</a>
    </form>
</div>

<!-- =========================================================
     Modal d’affichage des documents (voir / supprimer)
     ========================================================= -->
<div id="docModal">
    <div class="inner">
        <h3 id="modalTitle"></h3>
        <ul id="modalList" class="doc-list"></ul>
        <button onclick="closeModal()" class="btn"
                style="background:#777;margin-top:1rem;">Fermer</button>
    </div>
</div>

<script>
    // Expose les listes de documents au JavaScript
    window.vehicleDocs = {
        contract: <?= json_encode($contractLinks, JSON_UNESCAPED_SLASHES) ?>,
        carte_grise: <?= json_encode($carteLinks, JSON_UNESCAPED_SLASHES) ?>,
        controle_technique: <?= json_encode($ctLinks, JSON_UNESCAPED_SLASHES) ?>,
        facture: <?= json_encode($factureLinks, JSON_UNESCAPED_SLASHES) ?>
    };
</script>
</body>
</html>