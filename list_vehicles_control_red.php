<?php
/**
 * list_vehicles_control_red.php
 *
 * Returns a JSON array of all vehicles together with the
 * pre‑computed future‑control‑technique date (date_futur_control).
 * The dashboard modal “Voir les véhicules à contrôle technique imminent”
 * consumes this endpoint.
 */

declare(strict_types=1);

// -----------------------------------------------------------------
// 1. Load the shared DB connection (creates $pdo)
// -----------------------------------------------------------------
require_once __DIR__ . '/inc/db.php';

// -----------------------------------------------------------------
// 2. Build the query
// -----------------------------------------------------------------
$sql = '
    SELECT
        v.id                     AS id_vehicule,
        v.immatriculation,
        v.date_futur_control,            -- <-- the column you need
        e.nom                    AS etablissement,
        p.nom                    AS pole,
        ma.nom                   AS marque,
        m.nom                    AS modele
    FROM vehicules v
    LEFT JOIN etablissements e ON v.etablissement_id = e.id
    LEFT JOIN poles          p ON e.pole_id = p.id
    LEFT JOIN modeles        m ON v.modele_id = m.id
    LEFT JOIN marques        ma ON m.marque_id = ma.id
    ORDER BY v.date_futur_control ASC
';

try {
    $stmt = $pdo->query($sql);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // In production you would log the error instead of exposing it.
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// -----------------------------------------------------------------
// 3. Output JSON
// -----------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
echo json_encode($vehicles, JSON_UNESCAPED_UNICODE);