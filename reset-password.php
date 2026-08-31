<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

$error = '';
$success = '';

// Récupérer le token
$token = $_GET['token'] ?? '';
if (empty($token)) {
    $error = 'Token invalide.';
}

// Vérifier le token
if (empty($error)) {
    $resetUser = verifyPasswordResetToken($token, $conn);
    if (!$resetUser) {
        $error = 'Le lien de réinitialisation a expiré ou est invalide.';
    }
}

// Traiter la réinitialisation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = 'Tous les champs sont obligatoires.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Le mot de passe doit faire au moins 8 caractères.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        // Réinitialiser le mot de passe
        $result = resetPassword($resetUser['user_id'], $newPassword, $conn);
        if ($result['success']) {
            // Marquer le token comme utilisé
            markTokenAsUsed($token, $conn);
            $success = 'Mot de passe réinitialisé avec succès. Vous pouvez maintenant vous connecter.';
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Réinitialiser le mot de passe';
$loggedIn = false;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .container-reset {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .reset-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .reset-header h1 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
        }

        .btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .footer-link {
            text-align: center;
            margin-top: 1.5rem;
            color: #666;
            font-size: 0.9rem;
        }

        .footer-link a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container-reset">
        <div class="reset-header">
            <h1>Réinitialiser le mot de passe</h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <div class="footer-link">
                <a href="/login.php">Retour à la connexion</a>
            </div>
        <?php elseif (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <div class="footer-link">
                <a href="/login.php">Se connecter</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="password">Nouveau mot de passe (min. 8 caractères)</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirmer le mot de passe</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button type="submit" class="btn">Réinitialiser le mot de passe</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
