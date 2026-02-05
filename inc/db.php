<?php
declare(strict_types=1);

$user = "root";
$password = "";
$dbname = "voiture";
$servername = "localhost";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$pdo = new PDO("mysql:host=$servername;dbname=$dbname", $user, $password);