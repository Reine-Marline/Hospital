<?php
/**
 * Système d'authentification
 */

require_once __DIR__ . '/functions.php';

// Authentifier l'utilisateur
function authenticateUser($username, $password, $conn) {
    $query = "SELECT id, username, email, password, role, status, last_login, 
              password_changed_at FROM users WHERE username = ? AND status = 'active' LIMIT 1";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Erreur de base de données'];
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Vérifier si l'utilisateur existe
    if (!$user) {
        return ['success' => false, 'error' => 'Identifiants invalides'];
    }
    
    // Vérifier le mot de passe
    if (!verifyPassword($password, $user['password'])) {
        // Enregistrer la tentative échouée
        logFailedLogin($user['id'], $conn);
        return ['success' => false, 'error' => 'Identifiants invalides'];
    }
    
    // Vérifier si le mot de passe a expiré (après 2 heures)
    $passwordAge = strtotime('now') - strtotime($user['password_changed_at']);
    if ($passwordAge > 7200) { // 2 heures
        return ['success' => false, 'error' => 'Votre mot de passe a expiré. Veuillez le réinitialiser.', 'expired' => true, 'user_id' => $user['id']];
    }
    
    // Mettre à jour la dernière connexion
    $updateQuery = "UPDATE users SET last_login = NOW(), failed_login_attempts = 0 WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    if ($updateStmt) {
        $updateStmt->bind_param("i", $user['id']);
        $updateStmt->execute();
        $updateStmt->close();
    }
    
    // Créer la session
    startSecureSession();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    
    // Charger les permissions de l'utilisateur
    loadUserPermissions($user['id'], $conn);
    
    // Enregistrer l'activité
    logActivity($user['id'], 'LOGIN', 'Connexion réussie', $conn);
    
    return ['success' => true, 'user' => $user];
}

// Enregistrer une tentative de connexion échouée
function logFailedLogin($user_id, $conn) {
    $query = "UPDATE users SET failed_login_attempts = failed_login_attempts + 1, 
              last_failed_login = NOW() WHERE id = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // Vérifier si le compte doit être verrouillé
    $checkQuery = "SELECT failed_login_attempts FROM users WHERE id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    if ($checkStmt) {
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $userData = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($userData['failed_login_attempts'] >= 5) {
            lockAccount($user_id, $conn);
        }
    }
}

// Verrouiller un compte
function lockAccount($user_id, $conn) {
    $query = "UPDATE users SET status = 'locked', locked_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    // Enregistrer l'activité
    logActivity($user_id, 'ACCOUNT_LOCKED', 'Compte verrouillé après trop de tentatives', $conn);
}

// Déverrouiller un compte
function unlockAccount($user_id, $conn) {
    $query = "UPDATE users SET status = 'active', failed_login_attempts = 0, locked_at = NULL WHERE id = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Charger les permissions de l'utilisateur
function loadUserPermissions($user_id, $conn) {
    $query = "SELECT DISTINCT p.permission_name 
              FROM user_permissions up
              JOIN permissions p ON up.permission_id = p.id
              WHERE up.user_id = ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row['permission_name'];
        }
        
        $_SESSION['permissions'] = $permissions;
        $stmt->close();
    }
}

// Enregistrer un nouvel utilisateur
function registerUser($username, $email, $password, $role, $conn) {
    // Vérifier si l'utilisateur existe déjà
    $checkQuery = "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    if ($checkStmt) {
        $checkStmt->bind_param("ss", $username, $email);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        if ($result->num_rows > 0) {
            $checkStmt->close();
            return ['success' => false, 'error' => 'Cet utilisateur ou email existe déjà'];
        }
        $checkStmt->close();
    }
    
    $hashedPassword = hashPassword($password);
    $query = "INSERT INTO users (username, email, password, role, status, created_at, password_changed_at) 
              VALUES (?, ?, ?, ?, 'active', NOW(), NOW())";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Erreur de base de données'];
    }
    
    $stmt->bind_param("ssss", $username, $email, $hashedPassword, $role);
    if ($stmt->execute()) {
        $user_id = $conn->insert_id;
        $stmt->close();
        
        // Assigner les permissions par défaut du rôle
        assignDefaultRolePermissions($user_id, $role, $conn);
        
        return ['success' => true, 'user_id' => $user_id];
    } else {
        $stmt->close();
        return ['success' => false, 'error' => 'Erreur lors de l\'enregistrement'];
    }
}

// Assigner les permissions par défaut du rôle
function assignDefaultRolePermissions($user_id, $role, $conn) {
    $query = "SELECT id FROM permissions WHERE role = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($permission = $result->fetch_assoc()) {
            $insertQuery = "INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)";
            $insertStmt = $conn->prepare($insertQuery);
            if ($insertStmt) {
                $insertStmt->bind_param("ii", $user_id, $permission['id']);
                $insertStmt->execute();
                $insertStmt->close();
            }
        }
        $stmt->close();
    }
}

// Réinitialiser le mot de passe
function resetPassword($user_id, $newPassword, $conn) {
    $hashedPassword = hashPassword($newPassword);
    $query = "UPDATE users SET password = ?, password_changed_at = NOW(), 
              failed_login_attempts = 0 WHERE id = ?";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Erreur de base de données'];
    }
    
    $stmt->bind_param("si", $hashedPassword, $user_id);
    if ($stmt->execute()) {
        $stmt->close();
        logActivity($user_id, 'PASSWORD_RESET', 'Mot de passe réinitialisé', $conn);
        return ['success' => true];
    } else {
        $stmt->close();
        return ['success' => false, 'error' => 'Erreur lors de la réinitialisation'];
    }
}

// Demander une réinitialisation de mot de passe
function requestPasswordReset($email, $conn) {
    $query = "SELECT id FROM users WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            return ['success' => false, 'error' => 'Email non trouvé'];
        }
        
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // Générer un token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        
        // Sauvegarder le token
        $insertQuery = "INSERT INTO password_resets (user_id, token_hash, created_at, expires_at) 
                       VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
        $insertStmt = $conn->prepare($insertQuery);
        if ($insertStmt) {
            $insertStmt->bind_param("is", $user['id'], $tokenHash);
            if ($insertStmt->execute()) {
                $insertStmt->close();
                
                // Envoyer l'email
                $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/reset-password.php?token=" . urlencode($token);
                $message = "Cliquez sur ce lien pour réinitialiser votre mot de passe: <a href='" . $resetLink . "'>Réinitialiser</a>";
                sendEmail($email, "Réinitialisation de mot de passe", $message);
                
                return ['success' => true];
            }
            $insertStmt->close();
        }
    }
    
    return ['success' => false, 'error' => 'Erreur lors de la demande'];
}

// Vérifier et utiliser un token de réinitialisation
function verifyPasswordResetToken($token, $conn) {
    $tokenHash = hash('sha256', $token);
    $query = "SELECT user_id FROM password_resets 
              WHERE token_hash = ? AND expires_at > NOW() AND used = 0 LIMIT 1";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
    }
    
    return null;
}

// Marquer le token comme utilisé
function markTokenAsUsed($token, $conn) {
    $tokenHash = hash('sha256', $token);
    $query = "UPDATE password_resets SET used = 1 WHERE token_hash = ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $stmt->close();
    }
}

// Déconnexion
function logout($conn = null) {
    if (isLoggedIn() && $conn) {
        logActivity(getUserId(), 'LOGOUT', 'Déconnexion', $conn);
    }
    
    $_SESSION = [];
    if (session_status() !== PHP_SESSION_NONE) {
        session_destroy();
    }
}

// Vérifier si on doit se reconnecter forcément après expiration
function requireLogin() {
    startSecureSession();
    if (!isLoggedIn() || !checkSessionExpiration()) {
        redirect('/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    }
}

// Vérifier l'autorisation basée sur le rôle
function requireRole($roles) {
    startSecureSession();
    requireLogin();
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    $userRole = getUserRole();
    if (!in_array($userRole, $roles)) {
        http_response_code(403);
        die('Accès refusé');
    }
}

// Vérifier l'autorisation basée sur une permission
function requirePermission($permission) {
    startSecureSession();
    requireLogin();
    
    if (!hasPermission($permission)) {
        http_response_code(403);
        die('Accès refusé');
    }
}

// Vérifier si le 2FA est activé pour un utilisateur
function check2FAEnabled($user_id, $conn) {
    $stmt = $conn->prepare("SELECT two_factor_enabled FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && $row['two_factor_enabled'];
}

// Générer un code 2FA à 6 chiffres et le sauvegarder
function generate2FACode($user_id, $conn) {
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("UPDATE users SET two_factor_code = ?, two_factor_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?");
    $stmt->bind_param("si", $code, $user_id);
    $stmt->execute();
    $stmt->close();

    // Envoyer le code par email (logué dans email_log)
    $emailStmt = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $emailStmt->bind_param("i", $user_id);
    $emailStmt->execute();
    $emailRow = $emailStmt->get_result()->fetch_assoc();
    $emailStmt->close();
    if ($emailRow) {
        sendEmail($emailRow['email'], 'Code de vérification 2FA', 'Votre code de vérification est : ' . $code . '. Il expire dans 10 minutes.');
    }
    return $code;
}

// Vérifier un code 2FA
function verify2FACode($user_id, $code, $conn) {
    $stmt = $conn->prepare("SELECT two_factor_code FROM users WHERE id = ? AND two_factor_code = ? AND two_factor_expires_at > NOW() LIMIT 1");
    $stmt->bind_param("is", $user_id, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        // Invalider le code après utilisation
        $upd = $conn->prepare("UPDATE users SET two_factor_code = NULL, two_factor_expires_at = NULL WHERE id = ?");
        $upd->bind_param("i", $user_id);
        $upd->execute();
        $upd->close();
        return true;
    }
    return false;
}

// Activer/Désactiver le 2FA
function toggle2FA($user_id, $enable, $conn) {
    $val = $enable ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET two_factor_enabled = ? WHERE id = ?");
    $stmt->bind_param("ii", $val, $user_id);
    $stmt->execute();
    $stmt->close();
}
?>
