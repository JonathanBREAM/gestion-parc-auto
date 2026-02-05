<?php
/* -------------------------------------------------
   add_vehicle.php – création d’un véhicule + documents
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

/* ---------- 5. Fonction d’échappement (gère null/int) ---------- */
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

/* ---------- 5‑bis. Petit helper « value » (facultatif) ---------- */
function value(string $key, array $src, $fallback = '')
{
    return $src[$key] ?? $fallback;
}

/* ---------- 6. Récupération des listes déroulantes ---------- */
function fetchAll(PDO $pdo, string $table, string $orderBy = 'nom')
{
    $stmt = $pdo->query("SELECT id, nom FROM $table ORDER BY $orderBy");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$etablissements = fetchAll($pdo, 'etablissements');
$marques        = fetchAll($pdo, 'marques');               // id, nom
$modeles        = fetchAll($pdo, 'modeles');               // id, nom (pour le <select> de base)

// Tous les modèles avec leur marque (pour le filtrage JS)
$allModelesStmt = $pdo->query('SELECT id, nom, marque_id FROM modeles ORDER BY nom');
$allModeles     = $allModelesStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 7. Traitement du formulaire (POST) ---------- */
$errors   = [];
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ----- 7.1 Récupération & nettoyage des champs ----- */
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

    // Conserver les valeurs pour le ré‑affichage
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

    /* ----- 7.2 Validation basique ----- */
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

    /* -------------------------------------------------
   Calcul de la date futur de contrôle technique
   ------------------------------------------------- */

    /**
     * Retourne la même date + $years années (format YYYY‑MM‑DD).
     *
     * @param string $date   Date au format YYYY‑MM‑DD
     * @param int    $years  Nombre d’années à ajouter
     * @return string        Date + $years années
     */
    function addYears(string $date, int $years = 1): string
    {
        $dt = new DateTimeImmutable($date);
        return $dt->modify('+' . $years . ' year')->format('Y-m-d');
    }

    /* -------------------------------------------------
   1️⃣  Normaliser le type de véhicule
   ------------------------------------------------- */
    // Remove surrounding whitespace and make the comparison case‑insensitive
    $typeVehicule = trim($typeVehicule);
    $typeVehiculeLower = strtolower($typeVehicule);

    /* -------------------------------------------------
    2️⃣  Déterminer le facteur d’ajout (x) selon le type
    ------------------------------------------------- */
    if ($typeVehiculeLower === 'non commercial') {
        // Non commercial → +1 year
        $x = 2;
    } elseif ($typeVehiculeLower === 'commercial') {
        // Commercial → +2 years
        $x = 1;
    } else {
        // Fallback (should never happen if validation is correct)
        // Choose a sensible default – here we pick 1 year
        $x = 2;
    }

    /* -------------------------------------------------
    3️⃣  (Optional) Keep the original mixed‑case value
    ------------------------------------------------- */
    // If you need the original spelling later (e.g., to store back in DB)
    $typeVehicule = ($typeVehiculeLower === 'non commercial') ? 'non commercial' : 'Commercial';

    /* 2️⃣  Parcourir les contrôles du plus récent au plus ancien */
    if ($controle5 !== '') {
        $dateFuturControl = addYears($controle5, $x);
    } elseif ($controle4 !== '') {
        $dateFuturControl = addYears($controle4, $x);
    } elseif ($controle3 !== '') {
        $dateFuturControl = addYears($controle3, $x);
    } elseif ($controle2 !== '') {
        $dateFuturControl = addYears($controle2, $x);
    } elseif ($controle1 !== '') {
        $dateFuturControl = addYears($controle1, $x);
    } else {
        // Aucun contrôle enregistré → on part du début du leasing
        $dateFuturControl = ($debutLeasing !== '')
            ? addYears($debutLeasing, $x)
            : null;   // si même début leasing absent, on laisse NULL
    }

    /* Si, pour une raison quelconque, aucune date de base n’est disponible,
   on laisse la colonne NULL (le INSERT gère déjà cela). */
    /* -------------------------------------------------
    Calcul de la date futur d'entretien
    ------------------------------------------------- */

    /**
     * Retourne la même date + 1 an (format YYYY‑MM‑DD).
     *
     * @param string $date  Date au format YYYY‑MM‑DD
     * @return string       Date + 1 an, même format
     */
    function addOneYear(string $date): string
    {
        $dt = new DateTimeImmutable($date);
        return $dt->modify('+1 year')->format('Y-m-d');
    }

    /* 1️⃣  Priorité : entretien3 → entretien2 → entretien1 → debut_leasing */
    if ($entretien3 !== '') {
        $dateFuturEntretien = addOneYear($entretien3);
    } elseif ($entretien2 !== '') {
        $dateFuturEntretien = addOneYear($entretien2);
    } elseif ($entretien1 !== '') {
        $dateFuturEntretien = addOneYear($entretien1);
    } else {
        // fallback sur le début du leasing
        $dateFuturEntretien = $debutLeasing !== '' ? addOneYear($debutLeasing) : null;
    }

    /* Si, pour une raison quelconque, aucune date de base n’est disponible,
   on laisse la colonne NULL (le INSERT gère déjà cela). */
    /* -------------------------------------------------
    (Optionnel) – Si, malgré tout, une date reste vide,
    on la laisse NULL dans la base (le INSERT gère déjà cela).
    ------------------------------------------------- */
    /* ----- 7.3 Vérifier que le modèle appartient bien à la marque ----- */
    $stmt = $pdo->prepare('SELECT marque_id FROM modeles WHERE id = :mid');
    $stmt->execute(['mid' => $modeleId]);
    $modelRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$modelRow) {
        $errors[] = 'Le modèle sélectionné n’existe pas.';
    } elseif ((int)$modelRow['marque_id'] !== (int)$marqueId) {
        $errors[] = 'Le modèle choisi ne correspond pas à la marque sélectionnée.';
    }

    /* ----- 7.4 Immatriculation unique ----- */
    $stmt = $pdo->prepare('SELECT id FROM vehicules WHERE immatriculation = :imm');
    $stmt->execute(['imm' => $immatriculation]);
    if ($stmt->fetch()) {
        $errors[] = 'Cette immatriculation existe déjà dans le parc.';
    }

    /* ----- 7.5 Gestion des fichiers ----- */
    $allowedExt = ['pdf','jpg','jpeg','png'];
    $maxSize    = 5 * 1024 * 1024; // 5 MiB

    // Helper de validation d’un fichier unique
    $validateFile = function(array $file, string $fieldName) use ($allowedExt,$maxSize,&$errors) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return null; // aucun fichier envoyé, ce n’est pas une erreur
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Erreur lors du téléchargement du fichier {$fieldName}.";
            return null;
        }
        if ($file['size'] > $maxSize) {
            $errors[] = "Le fichier {$fieldName} dépasse la taille maximale de 5 Mo.";
            return null;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = "Extension non autorisée pour {$fieldName} (pdf, jpg, jpeg, png).";
            return null;
        }
        // Nom sécurisé (random) + extension
        $newName = bin2hex(random_bytes(12)) . '.' . $ext;
        return $newName;
    };

    // Fichiers uniques déjà présents dans le script original
    $contractFileName          = $validateFile($_FILES['contract'] ?? [], 'Contrat');
    $carteGriseFileName       = $validateFile($_FILES['carte_grise'] ?? [], 'Carte Grise');
    $controleTechFileName      = $validateFile($_FILES['controle_technique'] ?? [], 'Contrôle Technique');

    // Nouveau champ : Photo du véhicule
    $photoFileName            = $validateFile($_FILES['photo'] ?? [], 'Photo du véhicule');

    // Factures (multiple)
    $factureNames = [];
    if (!empty($_FILES['factures']['name'][0])) {
        foreach ($_FILES['factures']['name'] as $idx => $origName) {
            $tmp = [
                'name'     => $origName,
                'type'     => $_FILES['factures']['type'][$idx],
                'tmp_name' => $_FILES['factures']['tmp_name'][$idx],
                'error'    => $_FILES['factures']['error'][$idx],
                'size'     => $_FILES['factures']['size'][$idx],
            ];
            $newName = $validateFile($tmp, "Facture #".($idx+1));
            if ($newName !== null) {
                $factureNames[] = $newName;
            }
        }
    }

    /* ----- 7.6 Insertion du véhicule (si aucune erreur) ----- */
    if (empty($errors)) {
        // 1️⃣ Insertion du véhicule
        $insertVeh = $pdo->prepare(
            'INSERT INTO vehicules
                (etablissement_id, immatriculation, modele_id, mensualite,
                debut_leasing, km_initial,
                date_entretien1, date_entretien2, date_entretien3,
                Date_contol1, Date_contol2, Date_contol3,
                Date_contol4, Date_contol5,
                date_futur_entretien,
                date_futur_control,          -- <<< NEW
                notes, photo, nombre_de_place, type_vehicule)
            VALUES
                (:etab, :imm, :modele, :mens, :debut, :km,
                :ent1, :ent2, :ent3,
                :cont1, :cont2, :cont3,
                :cont4, :cont5,
                :futureEntretien,
                :futureControl,              -- <<< NEW
                :notes, :photo, :places, :type)'
        );

        $insertVeh->execute([
            'etab'   => (int)$etablissementId,
            'imm'    => $immatriculation,
            'modele' => (int)$modeleId,
            'mens'   => str_replace(',', '.', $mensualite),
            'debut'  => $debutLeasing,
            'km'     => $kilometrage !== '' ? (int)str_replace(' ', '', $kilometrage) : null,
            'ent1'   => $entretien1 !== '' ? $entretien1 : null,
            'ent2'   => $entretien2 !== '' ? $entretien2 : null,
            'ent3'   => $entretien3 !== '' ? $entretien3 : null,
            'cont1'  => $controle1 !== '' ? $controle1 : null,
            'cont2'  => $controle2 !== '' ? $controle2 : null,
            'cont3'  => $controle3 !== '' ? $controle3 : null,
            'cont4'  => $controle4 !== '' ? $controle4 : null,
            'cont5'  => $controle5 !== '' ? $controle5 : null,
            'futureEntretien' => $dateFuturEntretien !== '' ? $dateFuturEntretien : null,
            'futureControl'   => $dateFuturControl   !== '' ? $dateFuturControl   : null,
            'notes'  => $notes !== '' ? $notes : null,
            'photo'  => $photoFileName ?? null,
            'places' => $nombrePlaces !== '' ? (int)$nombrePlaces : 4,
            'type'   => $typeVehicule !== '' ? $typeVehicule : 'non commercial',
        ]);

        $newVehicleId = (int)$pdo->lastInsertId();

        // 2️⃣ Créer le répertoire d’upload du véhicule
        $baseDir = __DIR__ . "/uploads/vehicles/{$newVehicleId}";
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        // 3️⃣ Fonction d’enregistrement d’un document dans la table `documents`
        $saveDocument = function(string $type, string $filename, string $tmpPath) use ($pdo,$newVehicleId) {
            $destDir = __DIR__ . "/uploads/vehicles/{$newVehicleId}/{$type}";
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            move_uploaded_file($tmpPath, "{$destDir}/{$filename}");

            $stmt = $pdo->prepare(
                'INSERT INTO documents (vehicule_id, type, version, filename, uploaded_at)
                 VALUES (:vehicule_id, :type, 1, :filename, NOW())'
            );
            $stmt->execute([
                'vehicule_id' => $newVehicleId,
                'type'        => $type,
                'filename'    => $filename,
            ]);
        };

        // 4️⃣ Enregistrer les fichiers uniques
        if ($contractFileName) {
            $saveDocument('contract', $contractFileName, $_FILES['contract']['tmp_name']);
        }
        if ($carteGriseFileName) {
            $saveDocument('carte_grise', $carteGriseFileName, $_FILES['carte_grise']['tmp_name']);
        }
        if ($controleTechFileName) {
            $saveDocument('controle_technique', $controleTechFileName, $_FILES['controle_technique']['tmp_name']);
        }
        if ($photoFileName) {
            $saveDocument('photo', $photoFileName, $_FILES['photo']['tmp_name']);
        }

        // 5️⃣ Enregistrer les factures (multiple)
        foreach ($_FILES['factures']['tmp_name'] as $idx => $tmpPath) {
            if (isset($factureNames[$idx])) {
                $saveDocument('facture', $factureNames[$idx], $tmpPath);
            }
        }

        // 6️⃣ Redirection avec message de succès
        header('Location: dashboard.php?msg=Vehicle%20added');
        exit;
    }
}

/* -----------------------------------------------------------------
   8. Affichage du formulaire (HTML)
   ----------------------------------------------------------------- */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ajouter un véhicule</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7f9;padding:2rem;}
        .container{background:#fff;padding:1.5rem;border-radius:6px;
                   max-width:750px;margin:auto;box-shadow:0 2px 6px rgba(0,0,0,.1);}
        h2{margin-top:0;}
        label{display:block;margin-top:.8rem;}
        input, select, textarea{width:100%;padding:.5rem;margin-top:.3rem;
                                 border:1px solid #ccc;border-radius:4px;}
        .btn{margin-top:1rem;padding:.6rem 1rem;background:#0066cc;color:#fff;
             border:none;border-radius:4px;cursor:pointer;}
        .btn:hover{background:#004999;}
        .error{color:#d00;margin-top:.5rem;}
        .msg{color:#28a745;margin-top:.5rem;}
    </style>
    <script>
        // -------------------------------------------------
        // Filtrage dynamique des modèles selon la marque
        // -------------------------------------------------
        const ALL_MODELS = <?= json_encode($allModeles, JSON_UNESCAPED_UNICODE) ?>;
        const PRESELECTED_MODEL_ID = <?= json_encode($oldInput['modele_id'] ?? 0) ?>;

        /**
         * Met à jour la liste déroulante des modèles en fonction de la marque sélectionnée.
         */
        function filterModels() {
            const marqueSelect = document.getElementById('marque_id');
            const modeleSelect = document.getElementById('modele_id');
            const selectedMarque = parseInt(marqueSelect.value, 10);

            // Réinitialiser le <select> des modèles
            modeleSelect.innerHTML = '<option value=\"\">Sélectionner un modèle</option>';

            // Ajouter uniquement les modèles appartenant à la marque choisie
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

                // Appliquer le filtre dès le chargement (utile lorsqu’on revient sur le formulaire après une erreur)
        window.addEventListener('DOMContentLoaded', () => {
            // Si une marque a déjà été sélectionnée (par exemple après une validation qui a échoué),
            // on déclenche le filtrage pour reconstituer la liste des modèles et éventuellement
            // pré‑sélectionner celui qui était choisi auparavant.
            if (document.getElementById('marque_id').value) {
                filterModels();
            }
        });
    </script>
</head>
<body>
<div class="container">
    <h2>Ajouter un nouveau véhicule</h2>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= esc($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="add_vehicle.php" enctype="multipart/form-data">

        <label for="photo">Photo du véhicule (PDF, JPG, PNG – max 5 Mo)</label>
        <input type="file" id="photo" name="photo"
               accept=".pdf,.jpg,.jpeg,.png">
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
            <!-- Les options seront injectées par JavaScript -->
        </select>
        
        <!-- Nombre de places -->
        <label for="nombre_de_place">Nombre de places *</label>
        <input type="number" id="nombre_de_place" name="nombre_de_place" min="1" value="" required>

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

        <!-- Début de leasing -->
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

        <label for="controle4">Date du quatrième contrôle réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="controle4" name="controle4"
            value="<?= esc(value('controle4', $oldInput)) ?>">

        <label for="controle5">Date du cinquième contrôle réalisé (AAAA-MM-JJ)</label>
        <input type="date" id="controle5" name="controle5"
            value="<?= esc(value('controle5', $oldInput)) ?>">

        <!-- ==== Documents ==== -->
        <hr style="margin:2rem 0;">

        <label for="contract">Contrat (PDF, JPG, PNG – max 5 Mo)</label>
        <input type="file" id="contract" name="contract"
               accept=".pdf,.jpg,.jpeg,.png">

        <label for="carte_grise">Carte Grise (PDF, JPG, PNG – max 5 Mo)</label>
        <input type="file" id="carte_grise" name="carte_grise"
               accept=".pdf,.jpg,.jpeg,.png">

        <label for="controle_technique">Contrôle Technique (PDF, JPG, PNG – max 5 Mo)</label>
        <input type="file" id="controle_technique" name="controle_technique"
               accept=".pdf,.jpg,.jpeg,.png">

        <label for="factures">Factures (PDF, JPG, PNG – max 5 Mo chacune, plusieurs possibles)</label>
        <input type="file" id="factures" name="factures[]" accept=".pdf,.jpg,.jpeg,.png" multiple>

        <!-- Boutons d’action -->
        <button type="submit" class="btn">Enregistrer le véhicule</button>
        <a href="dashboard.php" class="btn"
           style="background:#777;margin-left:0.5rem;">Annuler</a>
    </form>
</div>
</body>
</html>