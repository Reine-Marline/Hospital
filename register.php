<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

// Si déjà connecté, rediriger
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error = '';
$success = '';
$csrf_token = generateCsrfToken();

// Traiter l'inscription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $role = 'user'; // Rôle par défaut
        
        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Tous les champs sont obligatoires.';
        } elseif (strlen($password) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            // Enregistrer l'utilisateur
            $result = registerUser($username, $email, $password, $role, $conn);
            if ($result['success']) {
                $success = 'Inscription réussie. Vous pouvez maintenant vous connecter.';
                // Rediriger après 2 secondes
                header('Refresh: 2; url=/login.php');
            } else {
                $error = $result['error'];
            }
        }
    }
}

$pageTitle = 'Inscription - Gestion Hôpital';
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
            padding: 1rem;
        }

        .register-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .register-header h1 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .register-header p {
            color: #666;
            font-size: 0.9rem;
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

        .register-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: #666;
            font-size: 0.9rem;
        }

        .register-footer a {
            color: #667eea;
            text-decoration: none;
        }

        .password-requirements {
            background-color: #f0f0f0;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: #666;
        }

        .password-requirements ul {
            margin-left: 1.5rem;
        }

        .password-requirements li {
            margin: 0.5rem 0;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1><i class="fas fa-hospital"></i> Gestion Hôpital</h1>
            <p>Créer un nouveau compte</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
                <p>Redirection en cours...</p>
            </div>
        <?php endif; ?>

        <?php if (empty($success)): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="form-group">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" required>
            </div>

            <div class="form-group">
                <label for="email">Adresse email</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="password-requirements">
                <strong>Exigences du mot de passe:</strong>
                <ul>
                    <li>Au moins 8 caractères</li>
                    <li>Peut contenir des lettres, chiffres et caractères spéciaux</li>
                </ul>
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirmer le mot de passe</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn">S'inscrire</button>
        </form>
        <?php endif; ?>

        <div class="register-footer">
            <p>Vous avez déjà un compte ? <a href="/login.php">Se connecter</a></p>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</body>
</html>
