<?php
/* -------------------------------------------------
   dashboard.php – tableau de bord complet
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

/* -----------------------------------------------------------------
   5.5 Traitement des formulaires de création (POST)
   ----------------------------------------------------------------- */
/**
 * Calcule la prochaine date d’entretien parmi les trois dates stockées,
 * puis ajoute exactement **un an** à cette date.
 *
 * @param string|null $d1  Date du 1er entretien (format YYYY‑MM‑DD ou null)
 * @param string|null $d2  Date du 2ème entretien (format YYYY‑MM‑DD ou null)
 * @param string|null $d3  Date du 3ème entretien (format YYYY‑MM‑DD ou null)
 *
 * @return string|null      Date au format YYYY‑MM‑DD après ajout d’un an,
 *                          ou null s’il n’y a aucune date future.
 */
function prochaineDateEntretien(?string $d1, ?string $d2, ?string $d3): ?string
{
    $today = new DateTimeImmutable('today');

    $rawDates = [$d1, $d2, $d3];
    $futureDates = [];

    foreach ($rawDates as $raw) {
        if ($raw && $raw !== '0000-00-00') {
            try {
                $dt = new DateTimeImmutable($raw);
                if ($dt > $today) {
                    $futureDates[] = $dt;
                }
            } catch (Exception $e) {
                // ignore malformed dates
            }
        }
    }

    if (empty($futureDates)) {
        return null;
    }

    usort($futureDates, fn($a, $b) => $a <=> $b);
    $prochaine = $futureDates[0];

    // Si vous avez besoin d’ajouter un an à la date, décommentez la ligne suivante :
    // $prochaine = $prochaine->modify('+1 year');

    return $prochaine->format('Y-m-d');
}

/* -----------------------------------------------------------------
   6. Traitement des formulaires de création (POST)
   ----------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action_type'])) {
    $msg = '';

    // ---- Création d'un pôle ----
    if ($_POST['action_type'] === 'create_pole') {
        $nom = trim($_POST['nom_pole'] ?? '');
        if ($nom === '') {
            $msg = 'Le nom du pôle est obligatoire.';
        } else {
            $pdo->prepare('INSERT INTO poles (nom) VALUES (:nom)')
                ->execute(['nom' => $nom]);
            $msg = 'Pôle créé avec succès.';
        }
    }
    // ---- Création d'un établissement ----
    if ($_POST['action_type'] === 'create_etablissement') {
        $nom    = trim($_POST['nom_etablissement'] ?? '');
        $poleId = $_POST['pole_id'] ?? '';

        // Validation basique
        if ($nom === '' || $poleId === '' || !ctype_digit($poleId)) {
            $msg = 'Le nom de l’établissement et le pôle sont obligatoires.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO etablissements (nom, pole_id) VALUES (:nom, :pole)'
            );
            $stmt->execute([
                'nom'  => $nom,
                'pole' => (int)$poleId,
            ]);
            $msg = 'Établissement créé avec succès.';
        }
    }

    // ---- Création d'une marque ----
    elseif ($_POST['action_type'] === 'create_marque') {
        $nom = trim($_POST['nom_marque'] ?? '');
        if ($nom === '') {
            $msg = 'Le nom de la marque est obligatoire.';
        } else {
            $pdo->prepare('INSERT INTO marques (nom) VALUES (:nom)')
                ->execute(['nom' => $nom]);
            $msg = 'Marque créée avec succès.';
        }
    }

    // ---- Création d'un modèle (nécessite la marque) ----
    elseif ($_POST['action_type'] === 'create_modele') {
        $nom      = trim($_POST['nom_modele'] ?? '');
        $marqueId = $_POST['marque_id'] ?? '';
        if ($nom === '' || $marqueId === '' || !ctype_digit($marqueId)) {
            $msg = 'Tous les champs du modèle sont obligatoires.';
        } else {
            $pdo->prepare('INSERT INTO modeles (nom, marque_id) VALUES (:nom, :mid)')
                ->execute(['nom' => $nom, 'mid' => (int)$marqueId]);
            $msg = 'Modèle créé avec succès.';
        }
    }

    // Redirection pour éviter le re‑post
    if ($msg !== '') {
        header('Location: dashboard.php?msg=' . urlencode($msg));
        exit;
    }
}

/* -----------------------------------------------------------------
   7. Récupération des filtres (GET) + paramètres de tri
   ----------------------------------------------------------------- */
$filterEtab            = $_GET['etab'] ?? '';
$filterModele          = $_GET['modele'] ?? '';
$filterMarque          = $_GET['marque'] ?? '';
$filterImmatriculation = trim($_GET['immatriculation'] ?? '');

/* Paramètres de tri */
$sort = $_GET['sort'] ?? 'id';               // colonne demandée
$dir  = strtoupper($_GET['dir'] ?? 'ASC');   // ASC ou DESC

/* Colonnes autorisées pour le tri et leur mapping SQL */
$sortableColumns = [
    'id'               => 'v.id',
    'etablissement'    => 'e.nom',
    'date_entretien'   => 'v.date_entretien1',   // première date d’entretien
    'control_tech'     => 'v.Date_contol1'
];

/* Validation du tri */
if (!array_key_exists($sort, $sortableColumns)) {
    $sort = 'id';
}
if ($dir !== 'ASC' && $dir !== 'DESC') {
    $dir = 'ASC';
}

/* -----------------------------------------------------------------
   8. Récupération des listes déroulantes
   ----------------------------------------------------------------- */
function fetchAll(PDO $pdo, string $table, string $orderBy = 'nom')
{
    $stmt = $pdo->query("SELECT id, nom FROM $table ORDER BY $orderBy");
    return $stmt->fetchAll();
}
$etablissements = fetchAll($pdo, 'etablissements');
$modeles        = fetchAll($pdo, 'modeles');
$marques        = fetchAll($pdo, 'marques');

/** Retourne la liste complète des pôles */
function fetchPoles(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, nom FROM poles ORDER BY nom');
    return $stmt->fetchAll();
}

/* -------------------------------------------------
   Chargement des listes déroulantes utilisées dans le
   tableau de bord (déjà présentes pour établissements,
   modèles, marques…) 
   ------------------------------------------------- */
$etablissements = fetchAll($pdo, 'etablissements');
$modeles        = fetchAll($pdo, 'modeles');
$marques        = fetchAll($pdo, 'marques');
$poles          = fetchPoles($pdo);          // ← nouvelle ligne

/* -----------------------------------------------------------------
   9. Construction dynamique de la requête véhicules (filtres + tri)
   ----------------------------------------------------------------- */
$sql = '
    SELECT
        v.id                     AS id_vehicule,
        v.photo                  AS photo,
        e.nom                    AS etablissement,
        p.nom                    AS pole,
        v.immatriculation,
        m.nom                    AS modele,
        ma.nom                   AS marque,
        v.km_initial,
        v.mensualite,
        v.debut_leasing,
        v.date_entretien1,
        v.date_entretien2,
        v.date_entretien3,
        v.Date_contol1           AS date_controle1,
        v.Date_contol2           AS date_controle2,
        v.Date_contol3           AS date_controle3,
        v.Date_contol4           AS date_controle4,
        v.Date_contol5           AS date_controle5,

        /* ── NEW ─────────────────────── */
        v.date_futur_entretien   AS date_futur_entretien,
        v.date_futur_control     AS date_futur_control,
        /* ─────────────────────────────── */

        v.nombre_de_place,
        v.notes
    FROM vehicules v
    LEFT JOIN etablissements e ON v.etablissement_id = e.id
    LEFT JOIN poles p          ON e.pole_id = p.id
    LEFT JOIN modeles m       ON v.modele_id = m.id
    LEFT JOIN marques ma      ON m.marque_id = ma.id
    WHERE 1 = 1
';
$params = [];

if ($filterEtab !== '' && ctype_digit($filterEtab)) {
    $sql .= ' AND e.id = :etab';
    $params['etab'] = (int)$filterEtab;
}
if ($filterModele !== '' && ctype_digit($filterModele)) {
    $sql .= ' AND m.id = :modele';
    $params['modele'] = (int)$filterModele;
}
if ($filterMarque !== '' && ctype_digit($filterMarque)) {
    $sql .= ' AND ma.id = :marque';
    $params['marque'] = (int)$filterMarque;
}
if ($filterImmatriculation !== '') {
    $sql .= ' AND v.immatriculation LIKE :immatriculation';
    $params['immatriculation'] = '%' . $filterImmatriculation . '%';
}

/* Ajout du ORDER BY dynamique */
$sql .= ' ORDER BY ' . $sortableColumns[$sort] . ' ' . $dir;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
} catch (Throwable $e) {
    $vehicles = [];
    $dbError = $e->getMessage();   // en prod, loggez plutôt que d’afficher
}

/* ---------- 10. Message d’information (succès) ---------- */
$infoMsg = $_GET['msg'] ?? '';

/* ---------- 11. Helper pour reconstruire l’URL avec de nouveaux paramètres ---------- */
function buildUrl(array $extraParams = []): string
{
    $params = $_GET;                     // copie les paramètres déjà présents
    foreach ($extraParams as $k => $v) {
        $params[$k] = $v;               // surcharge / ajoute
    }
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Dashboard – Gestion du parc automobile</title>
    <style>
        /* ------------------- GLOBAL ------------------- */
        body{font-family:Arial,sans-serif;background:#f4f7f9;margin:0;padding:0;}
        header{background:#0066cc;color:#fff;padding:1rem;display:flex;justify-content:space-between;align-items:center;}
        header h1{margin:0;font-size:1.5rem;}
        header .user-info{font-size:.9rem;}
        nav{background:#eaeff2;padding:.5rem;}
        nav a{margin-right:.8rem;color:#0066cc;text-decoration:none;}
        main{padding:1.5rem;}

        /* ------------------- FORMS ------------------- */
        .creation{display:none;background:#fff;padding:1rem;margin-bottom:1.5rem;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.1);}
        .filters,.creation{background:#fff;padding:1rem;margin-bottom:1.5rem;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.1);}
        .filters label,.creation label{margin-right:.5rem;}
        .filters select,.filters input,
        .creation input,.creation select{margin-right:1rem;padding:.4rem;}
        .filters button,.creation button{padding:.4rem .8rem;background:#0066cc;color:#fff;border:none;border-radius:4px;cursor:pointer;}
        .filters button:hover,.creation button:hover{background:#004999;}
        .filters{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;}
        .filter-controls{display:flex;flex-wrap:wrap;align-items:center;gap:0.6rem;}
        .export-container{display:flex;align-items:center;}
        .export-btn{display:inline-flex;align-items:center;justify-content:center;background:none;padding:0;cursor:pointer;}
        .export-btn img{max-height:34px;width:auto;transition:opacity 0.2s ease;}
        .export-btn:hover img{opacity: 0.6;}

        /* ------------------- ACTION BAR ------------------- */
        .action-bar .action-btn{margin-right:.8rem;margin-bottom:.8rem;padding:.4rem .8rem;background:#28a745;color:#fff;border:none;border-radius:4px;cursor:pointer;}
        .action-bar .action-btn:hover{background:#218838;}
        .action-barB .action-btn{margin-right:.8rem;margin-bottom:.8rem;padding:.4rem .8rem;background:#956E09;color:#fff;border:none;border-radius:4px;cursor:pointer;}
        .action-barB .action-btn:hover{background:#B8860B;}
        .action-barC .action-btn{margin-right:.8rem;margin-bottom:.8rem;padding:.4rem .8rem;background:#B22222;color:#fff;border:none;border-radius:4px;cursor:pointer;}
        .action-barC .action-btn:hover{background:#8B0000;}
        /* ------------------- TABLES ------------------- */
        table{width:100%;border-collapse:collapse;background:#fff;}
        th, td{padding:.6rem;border:1px solid #ddd;text-align:left;}
        th{background:#f0f4f8;}
        tr:nth-child(even){background:#fafafa;}
        .alert{color:#d00;margin-bottom:1rem;}
        .msg{color:#28a745;margin-bottom:1rem;}
        .btn{display:inline-block;background:#0066cc;color:#fff;padding:.5rem 1rem;border:none;border-radius:4px;text-decoration:none;}
        .btn:hover{background:#004999;}
        td.actions, th.actions{text-align:center;vertical-align:middle;}
        table td:last-child, table th:last-child{text-align:center;}

        /* ------------------- MODAL ------------------- */
        .modal{
            position:fixed;
            z-index:1000;
            left:0; top:0;
            width:100%; height:100%;
            overflow:auto;
            background-color:rgba(0,0,0,0.4);
            display:none;               /* hidden until opened */
        }
        .modal-content{
            background:#fff;
            margin:5% auto;
            padding:1.5rem;
            border-radius:6px;
            width:90%;
            max-width:800px;   /* increased from 600px */
            box-shadow:0 2px 10px rgba(0,0,0,0.2);
        }
        .close{
            color:#aaa;
            float:right;
            font-size:1.5rem;
            cursor:pointer;
        }
        .close:hover{color:#000;}
        table.modal-table{width:100%;border-collapse:collapse;}
        table.modal-table th, table.modal-table td{padding:.5rem;border:1px solid #ddd;text-align:left;}
        table.modal-table th{background:#f0f4f8;}
        button.delBtn{
            background:#d33;color:#fff;border:none;padding:.3rem .6rem;
            border-radius:4px;cursor:pointer;
        }
        button.delBtn:hover{background:#b22;}
        .msg{
            display:none;
        }
        .main-action-bar{
            display:flex;               /* crée un flex‑container */
            align-items:center;         /* centre verticalement les éléments */
            gap:0.8rem;                 /* espace entre les deux groupes de boutons */
        }
        .action-barC{
            margin-left:auto;           /* tout l’espace restant se place à gauche → le bloc se décale à droite */
        }
    </style>
</head>
<body>

<header>
    <h1>Gestion du parc automobile</h1>
    <div class="user-info">
        Connecté·e en tant que <strong><?= esc($_SESSION['username']) ?></strong>
        (<em><?= esc($_SESSION['role']) ?></em>) –
        <a href="logout.php" style="color:#fff;">Déconnexion</a>
    </div>
</header>

<main>

    <!-- ===== INFO / ERRORS ===== -->
    <?php if (!empty($infoMsg)): ?>
        <p class="msg"><?= esc($infoMsg) ?></p>
    <?php endif; ?>
    <?php if (!empty($dbError)): ?>
        <p class="alert">Erreur lors de la récupération des données : <?= esc($dbError) ?></p>
    <?php endif; ?>
    <div class="main-action-bar">
        <!-- ==== ACTION BAR (création) ==== -->
        <div class="action-bar">
            <button class="action-btn" onclick="toggleForm('form-pole')">
                + Créer un pôle
            </button>
            <button class="action-btn" onclick="toggleForm('form-etablissement')">
                + Créer un établissement
            </button>
            <button class="action-btn" onclick="toggleForm('form-marque')">
                + Créer une marque
            </button>
            <button class="action-btn" onclick="toggleForm('form-modele')">
                + Crérer un modèle
            </button>
        </div>

            <div class="action-barC">
                <!-- Bouton qui ouvre le modal des véhicules à entretien imminent -->
                <button class="action-btn" onclick="openVehiclesRedModal()">
                    Voir les véhicules à entretien imminent
                </button>
                <!-- Bouton qui ouvre le modal des véhicules dont le contrôle technique est imminent -->
                <button class="action-btn" onclick="openVehiclesControlRedModal()">
                    Voir les véhicules à contrôle technique imminent
                </button>
            </div>
    </div>
    <!-- ACTION BAR (suppressions) -->
            <div class="action-barB">
                <!-- Bouton qui ouvre le modal des pôles -->
                <button class="action-btn" onclick="openPolesModal()">
                    Voir les pôles
                </button>
                <!-- Bouton qui ouvre le modal des établissements -->
                <button class="action-btn" onclick="openEstablishmentsModal()">
                    Voir les établissements
                </button>
                <!-- Bouton qui ouvre le modal des marques -->
                <button class="action-btn" onclick="openMarquesModal()">
                    Voir les marques
                </button>
                <!-- Bouton qui ouvre le modal des modèles -->
                <button class="action-btn" onclick="openModelesModal()">
                    Voir les modèles
                </button>
            </div>
    <!-- ==============================================================
     1️⃣ FORMULAIRE – Créer un pôle
================================================================== -->
<div class="creation" id="form-pole">
    <h3>Créer un pôle</h3>

    <form method="POST" action="dashboard.php">
        <input type="hidden" name="action_type" value="create_pole">

        <label for="nom_pole">Nom du pôle</label>
        <input type="text"
               id="nom_pole"
               name="nom_pole"
               required>

        <button type="submit" class="btn">Enregistrer</button>

        <button type="button"
                class="btn"
                style="background:#d33;margin-left:0.5rem;"
                onclick="toggleForm('form-pole')">
            Annuler
        </button>
    </form>
</div>


<!-- ==============================================================
     2️⃣ FORMULAIRE – Créer un établissement
================================================================== -->
<div class="creation" id="form-etablissement">
    <h3>Créer un établissement</h3>

    <form method="POST" action="dashboard.php">
        <input type="hidden" name="action_type" value="create_etablissement">

        <!-- Nom de l'établissement -->
        <label for="nom_etablissement">Nom</label>
        <input type="text"
               id="nom_etablissement"
               name="nom_etablissement"
               required>

        <!-- Sélection du pôle auquel l'établissement appartient -->
        <label for="pole_id">Pôle</label>
        <select id="pole_id"
                name="pole_id"
                required>
            <option value="">Sélectionner un pôle</option>
            <?php foreach ($poles as $p): ?>
                <option value="<?= (int)$p['id'] ?>">
                    <?= esc($p['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn">Enregistrer</button>

        <button type="button"
                class="btn"
                style="background:#d33;margin-left:0.5rem;"
                onclick="toggleForm('form-etablissement')">
            Annuler
        </button>
    </form>
</div>


<!-- ==============================================================
     3️⃣ FORMULAIRE – Créer une marque
================================================================== -->
<div class="creation" id="form-marque">
    <h3>Créer une marque</h3>

    <form method="POST" action="dashboard.php">
        <input type="hidden" name="action_type" value="create_marque">

        <label for="nom_marque">Nom</label>
        <input type="text"
               id="nom_marque"
               name="nom_marque"
               required>

        <button type="submit" class="btn">Enregistrer</button>

        <button type="button"
                class="btn"
                style="background:#d33;margin-left:0.5rem;"
                onclick="toggleForm('form-marque')">
            Annuler
        </button>
    </form>
</div>


    <!-- ==============================================================
        4️⃣ FORMULAIRE – Créer un modèle
    ================================================================== -->
    <div class="creation" id="form-modele">
        <h3>Créer un modèle</h3>

        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action_type" value="create_modele">

            <!-- Nom du modèle -->
            <label for="nom_modele">Nom du modèle</label>
            <input type="text"
                id="nom_modele"
                name="nom_modele"
                required>

            <!-- Sélection de la marque à laquelle le modèle appartient -->
            <label for="marque_id">Marque associée</label>
            <select id="marque_id"
                    name="marque_id"
                    required>
                <option value="">Sélectionner une marque</option>
                <?php foreach ($marques as $ma): ?>
                    <option value="<?= (int)$ma['id'] ?>">
                        <?= esc($ma['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn">Enregistrer</button>

            <button type="button"
                    class="btn"
                    style="background:#d33;margin-left:0.5rem;"
                    onclick="toggleForm('form-modele')">
                Annuler
            </button>
        </form>
    </div>

        <!-- ==== LIEN AJOUT VEHICULE ==== -->
        <p style="margin-top:1.5rem;">
            <a class="btn" href="add_vehicle.php">+ Ajouter un nouveau véhicule</a>
        </p>

        <!-- ==== LISTE DES VÉHICULES ==== -->
        <h2>Liste des véhicules</h2>

        <!-- ==== FORMULAIRE DE FILTRES ==== -->
        <form class="filters" method="GET" action="dashboard.php">
            <div class="filter-controls">
                <label for="etab">Établissement :</label>
                <select name="etab" id="etab">
                    <option value="">Tous</option>
                    <?php foreach ($etablissements as $e): ?>
                        <option value="<?= $e['id'] ?>"
                            <?= ($filterEtab == $e['id']) ? 'selected' : '' ?>>
                            <?= esc($e['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="modele">Modèle :</label>
                <select name="modele" id="modele">
                    <option value="">Tous</option>
                    <?php foreach ($modeles as $m): ?>
                        <option value="<?= $m['id'] ?>"
                            <?= ($filterModele == $m['id']) ? 'selected' : '' ?>>
                            <?= esc($m['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="marque">Marque :</label>
                <select name="marque" id="marque">
                    <option value="">Toutes</option>
                    <?php foreach ($marques as $ma): ?>
                        <option value="<?= $ma['id'] ?>"
                            <?= ($filterMarque == $ma['id']) ? 'selected' : '' ?>>
                            <?= esc($ma['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="immatriculation">Plaque d’immatriculation :</label>
                <input type="text"
                       id="immatriculation"
                       name="immatriculation"
                       placeholder="ex. AB‑123‑CD"
                       value="<?= esc($filterImmatriculation) ?>">

                <button type="submit">Filtrer</button>
                <a href="dashboard.php" class="btn" style="margin-left:1rem;">Réinitialiser</a>
            </div>

            <!-- ----- EXPORT EXCEL ----- -->
            <div class="export-container">
                <?php
                $exportUrl = 'export_vehicles.php?';
                $parts = [];
                if ($filterEtab !== '')          $parts[] = 'etab=' . urlencode($filterEtab);
                if ($filterModele !== '')        $parts[] = 'modele=' . urlencode($filterModele);
                if ($filterMarque !== '')        $parts[] = 'marque=' . urlencode($filterMarque);
                if ($filterImmatriculation !== '') $parts[] = 'immatriculation=' . urlencode($filterImmatriculation);
                $exportUrl .= implode('&', $parts);
                ?>
                <a href="<?= $exportUrl ?>" class="export-btn">
                    <img src="img/exceller.png" alt="Export Excel">
                </a>
            </div>
        </form>

        <!-- ==== TABLEAU DES VÉHICULES ==== -->
        <?php if (empty($vehicles)): ?>
            <p>Aucun véhicule ne correspond aux critères sélectionnés.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <!-- 1️⃣ COLONNE PHOTO -->
                        <th>Photo</th>

                        <!-- 2️⃣ COLONNE POLE -->
                        <th>Pole</th>

                        <!-- 3️⃣ COLONNE ÉTABLISSEMENT (triable) -->
                        <?php
                        $nextDir = ($sort === 'etablissement' && $dir === 'ASC') ? 'DESC' : 'ASC';
                        $etabUrl = buildUrl(['sort' => 'etablissement', 'dir' => $nextDir]);
                        ?>
                        <th>
                            <a href="<?= esc($etabUrl) ?>">
                                Etablissement
                                <?php if ($sort === 'etablissement'): ?>
                                    <?= $dir === 'ASC' ? '▲' : '▼' ?>
                                <?php endif; ?>
                            </a>
                        </th>

                        <!-- le reste des colonnes… -->
                        <th>Immatriculation</th>
                        <th>Marque / Modèle</th>
                        <th>Nombre de place</th>
                        <th>Kilométrage</th>

                        <!-- 8️⃣ CONTRÔLE TECHNIQUE (date_entretien1) -->
                        <?php
                            // Direction toggle for the control‑technique column
                            $nextDirCtrl = ($sort === 'control_tech' && $dir === 'ASC') ? 'DESC' : 'ASC';
                            $ctrlUrl = buildUrl(['sort' => 'control_tech', 'dir' => $nextDirCtrl]);
                        ?>
                        <th>
                            <a href="<?= esc($ctrlUrl) ?>">
                                Contrôle technique
                                <?php if ($sort === 'control_tech'): ?>
                                    <?= $dir === 'ASC' ? '▲' : '▼' ?>
                                <?php endif; ?>
                            </a>
                        </th>

                        <!-- 9️⃣ DATE PROCHAIN ENTRETIEN (déjà calculée) -->
                        <?php
                        $nextDir = ($sort === 'date_entretien' && $dir === 'ASC') ? 'DESC' : 'ASC';
                        $dateUrl = buildUrl(['sort' => 'date_entretien', 'dir' => $nextDir]);
                        ?>
                        <th>
                            <a href="<?= esc($dateUrl) ?>">
                                Date prochain entretien
                                <?php if ($sort === 'date_entretien'): ?>
                                    <?= $dir === 'ASC' ? '▲' : '▼' ?>
                                <?php endif; ?>
                            </a>
                        </th>

                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vehicles as $v): ?>
                        <tr>
                            <!-- 1️⃣ PHOTO -->
                            <td style="text-align:center;">
                                <?php
                                $photoPath = $v['photo']
                                    ? "uploads/vehicles/{$v['id_vehicule']}/photo/{$v['photo']}"
                                    : null;
                                ?>
                                <?php if ($photoPath && file_exists(__DIR__ . '/' . $photoPath)): ?>
                                    <img src="<?= esc($photoPath) ?>"
                                         alt="Photo du véhicule"
                                         style="max-width:80px;height:auto;border:1px solid #ccc;">
                                <?php else: ?>
                                    <span style="color:#777;font-size:0.9em;">Aucune photo</span>
                                <?php endif; ?>
                            </td>

                            <!-- 2️⃣ POLE -->
                            <td><?= esc($v['pole'] ?? '-') ?></td>

                            <!-- 3️⃣ ÉTABLISSEMENT -->
                            <td><?= esc($v['etablissement']) ?></td>

                            <!-- 4️⃣ IMMATRICULATION -->
                            <td><?= esc($v['immatriculation']) ?></td>

                            <!-- 5️⃣ MARQUE / MODÈLE -->
                            <td><?= esc($v['marque'] . ' ' . $v['modele']) ?></td>

                            <!-- 6️⃣ NOMBRE DE PLACES -->
                            <td><?= esc($v['nombre_de_place'] ?? '-') ?></td>

                            <!-- 7️⃣ KILOMÉTRAGE -->
                            <td>
                                <?= $v['km_initial'] !== null
                                    ? esc(number_format((int)$v['km_initial'], 0, ',', ' '))
                                    : '&ndash;' ?>
                            </td>

                            <!-- 8️⃣ CONTRÔLE TECHNIQUE (même logique que “Date prochain entretien”) -->
                            <td>
                                <?php
                                // ------- NEW: use the pre‑computed future‑control date -------
                                $futureControl = $v['date_futur_control'] ?? null;

                                if ($futureControl) {
                                    $dateObj = new DateTimeImmutable($futureControl);
                                    $today   = new DateTimeImmutable('today');
                                    $diff    = $today->diff($dateObj);
                                    // Highlight in red if the date is within the next 30 days
                                    $isSoon  = ($diff->invert === 0 && $diff->days < 30);
                                    $display = $dateObj->format('d/m/Y');

                                    echo $isSoon
                                        ? '<span style="color:#d00;">' . esc($display) . '</span>'
                                        : esc($display);
                                } else {
                                    echo '<span style="color:#777;">Aucune date future</span>';
                                }
                                ?>
                            </td>

                            <!-- 9️⃣ DATE PROCHAIN ENTRETIEN -->
                            <td>
                                <?php
                                // ------- NEW: use the pre‑computed future‑entretien date -------
                                $futureEntretien = $v['date_futur_entretien'] ?? null;

                                if ($futureEntretien) {
                                    $dateObj = new DateTimeImmutable($futureEntretien);
                                    $today   = new DateTimeImmutable('today');
                                    $diff    = $today->diff($dateObj);
                                    // Highlight in red if the date is within the next 30 days
                                    $isSoon  = ($diff->invert === 0 && $diff->days < 30);
                                    $display = $dateObj->format('d/m/Y');

                                    echo $isSoon
                                        ? '<span style="color:#d00;">' . esc($display) . '</span>'
                                        : esc($display);
                                } else {
                                    echo '<span style="color:#777;">Aucune date future</span>';
                                }
                                ?>
                            </td>

                            <!-- 10️⃣ ACTIONS -->
                            <td class="actions">
                                <a class="btn"
                                   href="edit_vehicle.php?id=<?=urlencode((string)$v['id_vehicule'])?>">
                                    Modifier
                                </a>
                                <a class="btn"
                                   href="delete_vehicle.php?id=<?=urlencode((string)$v['id_vehicule'])?>"
                                   onclick="return confirm('Êtes‑vous sûr·e de vouloir supprimer ce véhicule ?');">
                                    Supprimer
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </main>

    <!-- ==================== MODAL DE SUPPRESSION ==================== -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle"></h3>
            <div id="modalBody"></div>
        </div>
    </div>

    <script>
    /* -------------------------------------------------
       Fonctions utilitaires
       ------------------------------------------------- */

    /**
     * Affiche / masque les mini‑formulaires de création
     */
    function toggleForm(id){
        const el = document.getElementById(id);
        el.style.display = (el.style.display === 'none' || el.style.display === '')
            ? 'block' : 'none';
    }

    /**
     * Ouvre le modal de suppression.
     *
     * @param {string} type  'etablissement' | 'marque' | 'modele'
     * @param {number} id    ID de l'élément à supprimer (facultatif)
     */
    function openDeleteModal(type, id = null) {
        const titles = {
            etablissement: 'Supprimer un établissement',
            marque:        'Supprimer une marque',
            modele:        'Supprimer un modèle'
        };
        document.getElementById('modalTitle').innerText = titles[type];

        // Message de chargement
        document.getElementById('modalBody').innerHTML = '<p>Chargement…</p>';

        // Endpoint qui renvoie les lignes au format JSON
        const url = `list_${type}s.php`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                // Si un ID est fourni, ne garder que la ligne correspondante
                const filtered = (id !== null)
                    ? data.filter(item => Number(item.id) === Number(id))
                    : data;
                renderTable(type, filtered);
            })
            .catch(err => {
                console.error(err);
                document.getElementById('modalBody').innerHTML =
                    `<p class="alert">Impossible de charger les données.</p>`;
            });

        // Afficher le modal
        document.getElementById('deleteModal').style.display = 'block';
    }

    /**
     * Construit le tableau à l’intérieur du modal.
     *
     * @param {string} type
     * @param {Array<Object>} rows
     */
    function renderTable(type, rows) {
        if (!rows.length) {
            document.getElementById('modalBody').innerHTML =
                `<p>Aucun élément à afficher.</p>`;
            return;
        }

        // Définition des colonnes selon le type
        let headers = [], cellRenderer = null;
        if (type === 'etablissement') {
            headers = ['ID', 'Nom'];
            cellRenderer = row => `<td>${row.id}</td><td>${escapeHtml(row.nom)}</td>`;
        } else if (type === 'marque') {
            headers = ['ID', 'Nom'];
            cellRenderer = row => `<td>${row.id}</td><td>${escapeHtml(row.nom)}</td>`;
        } else { // modele
            headers = ['ID', 'Nom', 'Marque'];
            cellRenderer = row => `<td>${row.id}</td><td>${escapeHtml(row.nom)}</td><td>${escapeHtml(row.marque_nom)}</td>`;
        }

        let html = `<table class="modal-table"><thead><tr>`;
        headers.forEach(h => html += `<th>${h}</th>`);
        html += `<th>Action</th></tr></thead><tbody>`;

        rows.forEach(row => {
            html += `<tr>${cellRenderer(row)}
                     <td><button class="delBtn"
                                 onclick="confirmDelete('${type}', ${row.id})">
                             Supprimer
                         </button></td></tr>`;
        });
        html += `</tbody></table>`;

        document.getElementById('modalBody').innerHTML = html;
    }

    /**
     * Escape HTML (prévention XSS)
     */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    /**
     * Confirmation + appel du script de suppression
     */
    function confirmDelete(type, id) {
        if (!confirm('Êtes‑vous sûr·e de vouloir supprimer cet élément ?')) return;

        const deleteUrl = `delete_${type}.php?id=${id}`; // le script attend le paramètre GET

        fetch(deleteUrl, {method:'POST'})
            .then(r => r.text())
            .then(msg => {
                alert(msg);               // vous pouvez remplacer par un toast plus élégant
                location.reload();        // rafraîchit la page pour mettre à jour les listes
            })
            .catch(err => {
                console.error(err);
                alert('Erreur lors de la suppression.');
            });
    }

    /**
    * Ouvre le modal et affiche la liste des établissements
    * -------------------------------------------------
    * Nouvelle colonne « Pole » ajoutée après « Nom ».
    */
    function openEstablishmentsModal() {
        document.getElementById('modalTitle').innerText = 'Gestion des établissements';
        document.getElementById('modalBody').innerHTML = '<p>Chargement…</p>';

        // On suppose que le script PHP renvoie, en plus de id/nom,
        // le champ `pole_id` (ou `pole_nom` si vous avez déjà joint la table poles).
        fetch('list_etablissements.php')
            .then(r => r.json())
            .then(data => {
                /* -------------------------------------------------
                TRI ALPHABÉTIQUE (nouvel ajout)
                ------------------------------------------------- */
                data.sort((a, b) => {
                    const nameA = (a.nom ?? '').toUpperCase();
                    const nameB = (b.nom ?? '').toUpperCase();
                    if (nameA < nameB) return -1;
                    if (nameA > nameB) return 1;
                    return 0;
                });

                /* -------------------------------------------------
                CONSTRUCTION DU TABLEAU
                ------------------------------------------------- */
                let html = '<table class="modal-table"><thead><tr>';
                html += '<th>Pole</th>';        // colonne Pole (déjà présente)
                html += '<th>Nom</th>';         // colonne Nom
                html += '<th>Action</th>';      // bouton Supprimer
                html += '</tr></thead><tbody>';

                data.forEach(row => {
                    // Si le script renvoie déjà le nom du pole (pole_nom), on l’utilise.
                    // Sinon on affiche simplement l’ID du pole (pole_id).
                    const poleDisplay = row.pole_nom
                        ? escapeHtml(row.pole_nom)
                        : (row.pole_id !== undefined ? escapeHtml(String(row.pole_id)) : '-');

                    html += `<tr>
                                <td>${poleDisplay}</td>
                                <td>${escapeHtml(row.nom)}</td>
                                <td>
                                    <button class="delBtn"
                                            onclick="confirmDelete('etablissement', ${row.id})">
                                        Supprimer
                                    </button>
                                </td>
                            </tr>`;
                });

                html += '</tbody></table>';
                document.getElementById('modalBody').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('modalBody').innerHTML =
                    '<p class="alert">Impossible de charger les établissements.</p>';
            });

        document.getElementById('deleteModal').style.display = 'block';
    }

    /**
    * Ouvre le modal et affiche la liste des marques
    */
    function openMarquesModal() {
        document.getElementById('modalTitle').innerText = 'Gestion des marques';
        document.getElementById('modalBody').innerHTML = '<p>Chargement…</p>';

        fetch('list_marques.php')
            .then(r => r.json())
            .then(data => {
                /* -------------------------------------------------
                TRI ALPHABÉTIQUE (nouvel ajout)
                ------------------------------------------------- */
                data.sort((a, b) => {
                    const nameA = (a.nom ?? '').toUpperCase();
                    const nameB = (b.nom ?? '').toUpperCase();
                    if (nameA < nameB) return -1;
                    if (nameA > nameB) return 1;
                    return 0;
                });

                /* -------------------------------------------------
                CONSTRUCTION DU TABLEAU
                ------------------------------------------------- */
                let html = '<table class="modal-table"><thead><tr>';
                html += '<th>Nom</th><th>Action</th>';
                html += '</tr></thead><tbody>';

                data.forEach(row => {
                    html += `<tr>
                                <td>${escapeHtml(row.nom)}</td>
                                <td>
                                    <button class="delBtn"
                                            onclick="confirmDelete('marque', ${row.id})">
                                        Supprimer
                                    </button>
                                </td>
                            </tr>`;
                });

                html += '</tbody></table>';
                document.getElementById('modalBody').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('modalBody').innerHTML =
                    '<p class="alert">Impossible de charger les marques.</p>';
            });

        document.getElementById('deleteModal').style.display = 'block';
    }

    /**
    * Ouvre le modal et affiche la liste des modèles
    */
    function openModelesModal() {
        document.getElementById('modalTitle').innerText = 'Gestion des modèles';
        document.getElementById('modalBody').innerHTML = '<p>Chargement…</p>';

        fetch('list_modeles.php')
            .then(r => r.json())
            .then(data => {
                /* -------------------------------------------------
                TRI ALPHABÉTIQUE (nouvel ajout)
                ------------------------------------------------- */
                data.sort((a, b) => {
                    // Le champ « nom » contient le nom du modèle
                    const nameA = (a.nom ?? '').toUpperCase();
                    const nameB = (b.nom ?? '').toUpperCase();
                    if (nameA < nameB) return -1;
                    if (nameA > nameB) return 1;
                    return 0;
                });

                /* -------------------------------------------------
                CONSTRUCTION DU TABLEAU
                ------------------------------------------------- */
                let html = '<table class="modal-table"><thead><tr>';
                html += '<th>Nom</th><th>Marque</th><th>Action</th>';
                html += '</tr></thead><tbody>';

                data.forEach(row => {
                    html += `<tr>
                                <td>${escapeHtml(row.nom)}</td>
                                <td>${escapeHtml(row.marque_nom ?? '')}</td>
                                <td>
                                    <button class="delBtn"
                                            onclick="confirmDelete('modele', ${row.id})">
                                        Supprimer
                                    </button>
                                </td>
                            </tr>`;
                });

                html += '</tbody></table>';
                document.getElementById('modalBody').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('modalBody').innerHTML =
                    '<p class="alert">Impossible de charger les modèles.</p>';
            });

        document.getElementById('deleteModal').style.display = 'block';
    }
    /**
     * Ouvre le modal et affiche la liste des pôles
     */
    /**
    * Ouvre le modal et affiche la liste des pôles
    */
    function openPolesModal() {
        document.getElementById('modalTitle').innerText = 'Gestion des pôles';
        document.getElementById('modalBody').innerHTML = '<p>Chargement…</p>';

        fetch('list_poles.php')
            .then(r => r.json())
            .then(data => {
                /* -------------------------------------------------
                TRI ALPHABÉTIQUE (inséré ici)
                ------------------------------------------------- */
                data.sort((a, b) => {
                    // on compare les noms en ignorant la casse
                    const nameA = (a.nom ?? '').toUpperCase();
                    const nameB = (b.nom ?? '').toUpperCase();
                    if (nameA < nameB) return -1;
                    if (nameA > nameB) return 1;
                    return 0;
                });

                /* -------------------------------------------------
                CONSTRUCTION DU TABLEAU
                ------------------------------------------------- */
                let html = '<table class="modal-table"><thead><tr>';
                html += '<th>Nom</th><th>Action</th>';
                html += '</tr></thead><tbody>';

                data.forEach(row => {
                    html += `<tr>
                                <td>${escapeHtml(row.nom)}</td>
                                <td>
                                    <button class="delBtn"
                                            onclick="confirmDelete('pole', ${row.id})">
                                        Supprimer
                                    </button>
                                </td>
                            </tr>`;
                });

                html += '</tbody></table>';
                document.getElementById('modalBody').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('modalBody').innerHTML =
                    '<p class="alert">Impossible de charger les pôles.</p>';
            });

        document.getElementById('deleteModal').style.display = 'block';
    }
    /**
     * Ouvre le modal et affiche la liste des véhicules dont la
     * colonne « Date prochain entretien » (maintenant `date_futur_entretien`)
     * serait affichée en rouge (c’est‑à‑dire que la prochaine date d’entretien
     * est dans les 30 prochains jours).  Deux colonnes supplémentaires
     * sont ajoutées : Etablissement et Marque / Modèle.
     */
    function openVehiclesRedModal() {
        document.getElementById('modalTitle').innerText = 'Véhicules à entretien imminent';
        document.getElementById('modalBody').innerHTML = '<p>Chargement…</p>';

        // Récupère toutes les informations des véhicules (inclut date_futur_entretien)
        fetch('list_vehicles_red.php')
            .then(r => r.json())
            .then(data => {
                const today = new Date();               // aujourd’hui (heure locale)

                // ---------- Filtrage ----------
                const redVehicles = data.filter(v => {
                    // 1️⃣  Utiliser la date pré‑calculée « date_futur_entretien »
                    const raw = v.date_futur_entretien ?? null;
                    if (!raw || raw === '0000-00-00') return false;

                    const futureDate = new Date(raw);
                    // 2️⃣  La date doit être dans le futur
                    if (futureDate <= today) return false;

                    // 3️⃣  Calcul du nombre de jours jusqu’à la date
                    const diffDays = Math.floor(
                        (futureDate - today) / (1000 * 60 * 60 * 24)
                    );

                    // 4️⃣  Retourner true si la date est < 30 jours
                    return diffDays < 30;
                });

                // ---------- Construction du tableau ----------
                if (!redVehicles.length) {
                    document.getElementById('modalBody').innerHTML =
                        '<p>Aucun véhicule n’a d’entretien prévu dans les 30 prochains jours.</p>';
                    return;
                }

                let html = '<table class="modal-table"><thead><tr>';
                html += '<th>Etablissement</th>';               // 2️⃣
                html += '<th>Marque / Modèle</th>';            // 3️⃣
                html += '<th>Immatriculation</th>';            // 4️⃣
                html += '<th>Date prochain entretien</th>';     // 5️⃣ (en rouge)
                html += '</tr></thead><tbody>';

                redVehicles.forEach(v => {
                    const display = new Date(v.date_futur_entretien)
                        .toLocaleDateString('fr-FR');

                    const marqueModele = `${v.marque ?? ''} ${v.modele ?? ''}`.trim();

                    html += `<tr>
                                <td>${escapeHtml(v.etablissement ?? '-')}</td>
                                <td>${escapeHtml(marqueModele || '-')}</td>
                                <td>${escapeHtml(v.immatriculation)}</td>
                                <td style="color:#d00;">${escapeHtml(display)}</td>
                            </tr>`;
                });

                html += '</tbody></table>';
                document.getElementById('modalBody').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('modalBody').innerHTML =
                    '<p class="alert">Impossible de charger les véhicules.</p>';
            });

        // Affiche le modal
        document.getElementById('deleteModal').style.display = 'block';
    }

    /**
     * Ouvre le modal et affiche la liste des véhicules dont la
     * colonne « Contrôle technique » (maintenant `date_futur_control`)
     * serait affichée en rouge (c’est‑à‑dire que la prochaine date de contrôle
     * est dans les 30 prochains jours).  Deux colonnes supplémentaires
     * sont ajoutées : Etablissement et Marque / Modèle.
     */
    function openVehiclesControlRedModal() {
        document.getElementById('modalTitle').innerText = 'Véhicules à contrôle technique imminent';
        document.getElementById('modalBody').innerHTML = '<p>Chargement…</p>';

        // Récupère toutes les informations des véhicules (inclut date_futur_control)
        fetch('list_vehicles_control_red.php')
            .then(r => r.json())
            .then(data => {
                const today = new Date();               // aujourd’hui (heure locale)

                // ---------- Filtrage ----------
                const redVehicles = data.filter(v => {
                    // 1️⃣  Utiliser la date pré‑calculée « date_futur_control »
                    const raw = v.date_futur_control ?? null;
                    if (!raw || raw === '0000-00-00') return false;

                    const futureDate = new Date(raw);
                    // 2️⃣  La date doit être dans le futur
                    if (futureDate <= today) return false;

                    // 3️⃣  Calcul du nombre de jours jusqu’à la date
                    const diffDays = Math.floor(
                        (futureDate - today) / (1000 * 60 * 60 * 24)
                    );

                    // 4️⃣  Retourner true si la date est < 30 jours
                    return diffDays < 30;
                });

                // ---------- Construction du tableau ----------
                if (!redVehicles.length) {
                    document.getElementById('modalBody').innerHTML =
                        '<p>Aucun véhicule n’a de contrôle technique prévu dans les 30 prochains jours.</p>';
                    return;
                }

                let html = '<table class="modal-table"><thead><tr>';
                html += '<th>Etablissement</th>';               // 2️⃣
                html += '<th>Marque / Modèle</th>';            // 3️⃣
                html += '<th>Immatriculation</th>';            // 4️⃣
                html += '<th>Contrôle technique</th>';         // 5️⃣ (en rouge)
                html += '</tr></thead><tbody>';

                redVehicles.forEach(v => {
                    const display = new Date(v.date_futur_control)
                        .toLocaleDateString('fr-FR');

                    const marqueModele = `${v.marque ?? ''} ${v.modele ?? ''}`.trim();

                    html += `<tr>
                                <td>${escapeHtml(v.etablissement ?? '-')}</td>
                                <td>${escapeHtml(marqueModele || '-')}</td>
                                <td>${escapeHtml(v.immatriculation)}</td>
                                <td style="color:#d00;">${escapeHtml(display)}</td>
                            </tr>`;
                });

                html += '</tbody></table>';
                document.getElementById('modalBody').innerHTML = html;
            })
            .catch(err => {
                console.error(err);
                document.getElementById('modalBody').innerHTML =
                    '<p class="alert">Impossible de charger les véhicules.</p>';
            });

        // Affiche le modal
        document.getElementById('deleteModal').style.display = 'block';
    }
    /**
     * Ferme le modal
     */
    function closeModal() {
        document.getElementById('deleteModal').style.display = 'none';
        document.getElementById('modalBody').innerHTML = ''; // nettoyage
    }
    </script>

</body>
</html>