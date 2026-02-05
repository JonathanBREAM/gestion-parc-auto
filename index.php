<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion – Gestion du parc automobile</title>

    <!-- Styles simples – vous pouvez les remplacer par votre propre CSS -->
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
            width: 320px;
        }
        h2 {
            margin-top: 0;
            text-align: center;
            color: #333;
        }
        label {
            display: block;
            margin-top: 1rem;
            font-weight: bold;
            color: #555;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: .5rem;
            margin-top: .3rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .btn-submit {
            width: 100%;
            margin-top: 1.5rem;
            padding: .6rem;
            background: #0066cc;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn-submit:hover {
            background: #004999;
        }
        .error-msg {
            color: #d00;
            margin-top: .5rem;
            text-align: center;
        }
    </style>
</head>
<?php
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<body>
    <div class="login-box">
        <h2>Connexion</h2>
        <form action="login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
            <label for="username">Identifiant</label>
            <input type="text" id="username" name="username" required>

            <label for="password">Mot de passe</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn-submit">Se connecter</button>
        </form>
    </div>
</body>
</html>