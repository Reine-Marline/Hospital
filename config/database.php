<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'admin_hopital';
$password = getenv('DB_PASSWORD') ?: 'Admin@123';
$database = getenv('DB_NAME') ?: 'hopital_db';
$appTimezone = getenv('APP_TIMEZONE') ?: 'Africa/Bujumbura';
if (!@date_default_timezone_set($appTimezone)) {
    date_default_timezone_set('UTC');
    $appTimezone = 'UTC';
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    http_response_code(500);
    error_log('Échec de connexion à la base de données : ' . mysqli_connect_error());
    die('La base de données est indisponible. Vérifiez la configuration du serveur.');
}

if (!mysqli_set_charset($conn, 'utf8mb4')) {
    error_log('Impossible de configurer l’encodage utf8mb4 : ' . mysqli_error($conn));
}

// Utilise le même fuseau pour les rendez-vous, les affichages et les dates SQL.
$sqlTimezoneOffset = date('P');
$conn->query("SET time_zone = '" . $conn->real_escape_string($sqlTimezoneOffset) . "'");
?>
