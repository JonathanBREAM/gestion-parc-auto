<?php
/* -------------------------------------------------
   export_vehicles.php – export CSV (Excel) du parc automobile
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

/* ---------- 5. Récupération des filtres (GET) ---------- */
$filterEtab            = $_GET['etab'] ?? '';
$filterModele          = $_GET['modele'] ?? '';
$filterMarque          = $_GET['marque'] ?? '';
$filterImmatriculation = trim($_GET['immatriculation'] ?? '');

/* ---------- 6. Construction dynamique de la requête ---------- */
$sql = '
    SELECT
        v.id                     AS id_vehicule,
        p.nom                    AS pole,                -- ← nom du pole (via la jointure)
        e.nom                    AS etablissement,
        v.immatriculation,
        ma.nom                   AS marque,
        m.nom                    AS modele,
        v.mensualite,
        v.debut_leasing,
        v.km_initial,
        v.nombre_de_place,
        v.type_vehicule,
        v.date_entretien1,
        v.date_entretien2,
        v.date_entretien3,
        v.Date_contol1,
        v.Date_contol2,
        v.Date_contol3,
        v.Date_contol4,
        v.Date_contol5,
        v.notes,

        /* ── NOUVEAUX CHAMPS ─────────────────────── */
        v.date_futur_entretien   AS date_futur_entretien,
        v.date_futur_control     AS date_futur_control
        /* ───────────────────────────────────────────── */

    FROM vehicules v
    LEFT JOIN etablissements e ON v.etablissement_id = e.id
    LEFT JOIN poles p          ON e.pole_id = p.id          -- ← jointure sur pole_id
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
$sql .= ' ORDER BY v.id ASC';

/* ---------- 7. Exécution de la requête ---------- */
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 8. En‑têtes HTTP pour le téléchargement ---------- */
$filename = 'parc_automobile-' . date('d-m-Y') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

/* ---------- 9. Écriture du CSV ----------
   - BOM UTF‑8 pour que Excel détecte correctement l’encodage.
   - Séparateur « ; » (point‑virgule) adapté aux paramètres régionaux français. */
echo "\xEF\xBB\xBF";               // BOM UTF‑8

$out = fopen('php://output', 'w');

/* En‑tête du CSV – ordre exact des colonnes (avec les deux nouvelles) */
$header = [
    'ID véhicule',
    'Pole',                     // ← deuxième colonne
    'Etablissement',
    'Immatriculation',
    'Marque',
    'Modèle',
    'Mensualité (€)',
    'Début leasing',
    'Km initial',
    'Nombre de places',
    'Type de véhicule',
    'Entretien 1',
    'Entretien 2',
    'Entretien 3',
    'Contrôle 1',
    'Contrôle 2',
    'Contrôle 3',
    'Contrôle 4',
    'Contrôle 5',
    'Notes',
    'Date futur entretien',    // ← nouveau champ
    'Date futur contrôle'      // ← nouveau champ
];
fputcsv($out, $header, ';');

/* Parcours des lignes et écriture dans le CSV */
foreach ($rows as $row) {
    // Formattage de la mensualité (deux décimales, virgule)
    $mensualite = number_format((float)$row['mensualite'], 2, ',', ' ');

    $line = [
        $row['id_vehicule'],
        $row['pole'] ?? '',
        $row['etablissement'],
        $row['immatriculation'],
        $row['marque'],
        $row['modele'],
        $mensualite,
        $row['debut_leasing'],
        $row['km_initial'],
        $row['nombre_de_place'],
        $row['type_vehicule'],
        $row['date_entretien1'],
        $row['date_entretien2'],
        $row['date_entretien3'],
        $row['Date_contol1'],
        $row['Date_contol2'],
        $row['Date_contol3'],
        $row['Date_contol4'],
        $row['Date_contol5'],
        $row['notes'],
        $row['date_futur_entretien'] ?? '',
        $row['date_futur_control']   ?? ''
    ];
    fputcsv($out, $line, ';');
}

fclose($out);
exit;
?>