<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

startSecureSession();
requireRole('admin');

$filename = $_GET['file'] ?? '';

// Valider le nom du fichier
if (empty($filename) || strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
    die('Fichier invalide.');
}

$backupDir = __DIR__ . '/../backups';
$filepath = $backupDir . '/' . $filename;

// Vérifier que le fichier existe et est dans le bon dossier
if (!file_exists($filepath) || realpath($filepath) !== realpath($filepath)) {
    http_response_code(404);
    die('Fichier non trouvé.');
}

// Télécharger le fichier
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));

readfile($filepath);
exit;
?>
