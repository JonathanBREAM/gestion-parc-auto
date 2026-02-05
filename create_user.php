<?php
/* -------------------------------------------------
   create_user.php – crée un compte dans la BDD
   ------------------------------------------------- */

declare(strict_types=1);
require_once __DIR__.'/inc/db.php';   // $pdo est créé ici

/**
 * Crée un utilisateur.
 *
 * @param PDO    $pdo      connexion PDO
 * @param string $login    nom d'utilisateur (unique)
 * @param string $pwdPlain mot de passe en clair
 * @param string $role     rôle ('admin' ou 'user')
 * @return void
 */
function createUser(PDO $pdo, string $login, string $pwdPlain, string $role = 'user'): void
{
    // Vérifier que le login n'existe pas déjà
    $check = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $check->execute(['u' => $login]);

    if ($check->fetch()) {
        echo "❌ Le nom d'utilisateur « $login » existe déjà.\n";
        return;
    }

    // Hacher le mot de passe (algorithme bcrypt/argon2 par défaut)
    $hash = password_hash($pwdPlain, PASSWORD_DEFAULT);

    // Insertion
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role)
         VALUES (:u, :h, :r)'
    );

    $stmt->execute([
        'u' => $login,
        'h' => $hash,
        'r' => $role,
    ]);

    echo "✅ Compte « $login » créé avec succès (rôle : $role).\n";
}

/* ------------------------------
   Exemple d’utilisation
   ------------------------------ */

// Remplacez ces valeurs par celles que vous désirez
$login    = '';          // nom d'utilisateur
$password = ''; // mot de passe en clair
$role     = 'user';         // ou 'user'

createUser($pdo, $login, $password, $role);