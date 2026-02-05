<?php
/* -------------------------------------------------
   logout.php – déconnexion sécurisée
   ------------------------------------------------- */

declare(strict_types=1);

/* 1️⃣ Démarrer (ou reprendre) la session */
session_start();

/* 2️⃣ Vider toutes les variables de session */
$_SESSION = [];

/* 3️⃣ Supprimer le cookie de session (si le serveur en utilise un) */
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),          // nom du cookie de session
        '',                      // valeur vide
        time() - 4200,           // expiration dans le passé
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

/* 4️⃣ Détruire la session côté serveur */
session_destroy();

/* 5️⃣ Rediriger l’utilisateur vers la page de connexion */
header('Location: index.php');
exit;
?>