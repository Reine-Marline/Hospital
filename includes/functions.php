<?php
/**
 * Fonctions utilitaires du système
 */

// Démarrer la session de manière sécurisée
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 7200, // 2 heures
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

// Vérifier l'authentification
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Vérifier l'expiration de la session (2 heures)
function checkSessionExpiration() {
    $timeout = 2 * 60 * 60; // 2 heures en secondes
    
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        
        if ($elapsed > $timeout) {
            session_destroy();
            return false;
        }
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

// Obtenir l'ID utilisateur
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Obtenir le rôle de l'utilisateur
function getUserRole() {
    return $_SESSION['user_role'] ?? null;
}

// Vérifier si l'utilisateur a un certain rôle
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

// Vérifier si l'utilisateur a une permission
function hasPermission($permission) {
    return isset($_SESSION['permissions']) && in_array($permission, $_SESSION['permissions']);
}

// Hacher un mot de passe
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Vérifier un mot de passe
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Générer un token CSRF
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Vérifier le token CSRF
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Rediriger vers une page
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Afficher un message flash
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'error', 'warning', 'info'
        'message' => $message
    ];
}

// Obtenir et supprimer le message flash
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Afficher le message flash en HTML
function displayFlashMessage() {
    $flash = getFlashMessage();
    if ($flash) {
        $class = 'alert-' . $flash['type'];
        echo '<div class="alert ' . htmlspecialchars($class) . '" role="alert">';
        echo htmlspecialchars($flash['message']);
        echo '</div>';
    }
}

// Nettoyer l'entrée
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Enregistrer une activité utilisateur
function logActivity($user_id, $action, $details, $conn) {
    $query = "INSERT INTO activity_log (user_id, action, details, ip_address, user_agent, timestamp) 
              VALUES (?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $stmt->bind_param("issss", $user_id, $action, $details, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }
}

// Obtenir les informations de l'utilisateur connecté
function getCurrentUser($conn) {
    if (!isLoggedIn()) {
        return null;
    }
    
    $user_id = getUserId();
    $query = "SELECT * FROM users WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user;
    }
    
    return null;
}

// Formater une date
function formatDate($date, $format = 'd/m/Y H:i:s') {
    if (empty($date)) {
        return '';
    }
    return date($format, strtotime($date));
}

// Vérifier si un fichier uploadé est valide
function validateUploadedFile($file, $maxSize = 5242880, $allowedTypes = []) {
    // Vérifier si le fichier existe dans $_FILES
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'Erreur lors du téléchargement'];
    }
    
    // Vérifier la taille
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'Le fichier est trop volumineux'];
    }
    
    // Vérifier le type MIME
    if (!empty($allowedTypes)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return ['valid' => false, 'error' => 'Type de fichier non autorisé'];
        }
    }
    
    return ['valid' => true, 'error' => null];
}

// Générer un nom de fichier sécurisé
function generateSafeFileName($originalName) {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $name = pathinfo($originalName, PATHINFO_FILENAME);
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    return $name . '_' . uniqid() . '.' . $ext;
}

// Clé d'environnement pour les nouvelles données sensibles.
function getDataEncryptionKey() {
    $configuredKey = getenv('HOSPITAL_DATA_KEY');
    if ($configuredKey === false || strlen($configuredKey) < 32) {
        static $warningLogged = false;
        if (!$warningLogged) {
            error_log('HOSPITAL_DATA_KEY absent ou trop court; la clé de compatibilité locale est utilisée.');
            $warningLogged = true;
        }
        $configuredKey = 'hospital_secret_key_1234567890abcdef';
    }
    return hash('sha256', $configuredKey, true);
}

// Chiffrement authentifié avec nonce aléatoire (format v2).
function encryptData($data) {
    if ($data === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($data, 'aes-256-gcm', getDataEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Le chiffrement de la donnée a échoué.');
    }
    return 'v2:' . base64_encode($iv . $tag . $ciphertext);
}

// Empreinte stable pour détecter un doublon sans stocker le CIN en clair.
function patientIdentityFingerprint($identity) {
    $key = hash_hmac('sha256', 'patient-identity-index', getDataEncryptionKey(), true);
    return hash_hmac('sha256', strtoupper(trim($identity)), $key);
}

// Chiffrement déterministe utilisé uniquement pour reconnaître les anciens CIN.
function encryptLegacyData($data) {
    if ($data === '') return '';
    $key = hash('sha256', 'hospital_secret_key_1234567890abcdef');
    $iv = substr(hash('sha256', 'hospital_secret_iv_1234567890'), 0, 16);
    return base64_encode(openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv));
}

// Compatibilité avec les CIN historiques en AES-CBC; les nouvelles données sont en AES-GCM.
function decryptData($data) {
    if ($data === '') return '';
    if (strncmp($data, 'v2:', 3) === 0) {
        $payload = base64_decode(substr($data, 3), true);
        if ($payload === false || strlen($payload) < 29) return '';
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', getDataEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
    $key = hash('sha256', 'hospital_secret_key_1234567890abcdef');
    $iv = substr(hash('sha256', 'hospital_secret_iv_1234567890'), 0, 16);
    return openssl_decrypt(base64_decode($data), 'AES-256-CBC', $key, 0, $iv);
}

// Envoyer un email
function sendEmail($to, $subject, $message, $headers = '') {
    global $conn;
    if (isset($conn)) {
        $stmt = $conn->prepare("INSERT INTO email_log (recipient, subject, body, sent_at) VALUES (?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("sss", $to, $subject, $message);
            $stmt->execute();
            $stmt->close();
        }
    }
    return true;
}

// Créer une notification
function createNotification($user_id, $type, $title, $message, $conn) {
    $query = "INSERT INTO notifications (user_id, type, title, message, created_at, is_read) 
              VALUES (?, ?, ?, ?, NOW(), 0)";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("isss", $user_id, $type, $title, $message);
        return $stmt->execute();
    }
    return false;
}

// Obtenir les notifications non lues
function getUnreadNotifications($user_id, $conn) {
    $query = "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 
              ORDER BY created_at DESC LIMIT 10";
    $stmt = $conn->prepare($query);
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $notifications;
    }
    
    return [];
}

// Générer un PDF (utiliser fpdf ou une solution simple)
function generatePDF($title, $content) {
    // Pour une solution simple sans librairie externe
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . date('Y-m-d_H-i-s') . '.pdf"');
    
    // Retourner du contenu HTML/texte pour affichage
    return $content;
}

// Exporter en Excel
function exportToExcel($filename, $data, $headers) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen("php://output", "w");
    
    // Ajouter les en-têtes
    fputcsv($output, $headers);
    
    // Ajouter les données
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// Vérifier si la base de données est accessible
function databaseConnected($conn) {
    return $conn && !mysqli_connect_error();
}

// Obtenir le statut de la session
function getSessionStatus() {
    return [
        'logged_in' => isLoggedIn(),
        'user_id' => getUserId(),
        'user_role' => getUserRole(),
        'last_activity' => $_SESSION['last_activity'] ?? null
    ];
}
?>
