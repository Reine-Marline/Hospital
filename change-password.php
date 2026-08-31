<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

$is_expired = isset($_GET['expired']) && $_GET['expired'] === 'true' && isset($_SESSION['reset_user_id']);
$error = '';
$success = '';

if (!$is_expired) {
    requireLogin();
    $user_id = getUserId();
} else {
    $user_id = $_SESSION['reset_user_id'];
}

$csrf_token = generateCsrfToken();

// Traiter le changement de mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Valider les entrées
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'Tous les champs sont obligatoires.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            // Vérifier le mot de passe actuel
            $query = "SELECT password FROM users WHERE id = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || !verifyPassword($currentPassword, $user['password'])) {
                $error = 'Le mot de passe actuel est incorrect.';
            } else {
                // Réinitialiser le mot de passe
                $resetResult = resetPassword($user_id, $newPassword, $conn);
                if ($resetResult['success']) {
                    $success = 'Mot de passe changé avec succès.';
                    if ($is_expired) {
                        unset($_SESSION['reset_user_id']);
                        setFlashMessage('success', 'Votre mot de passe a été changé. Veuillez vous connecter avec votre nouveau mot de passe.');
                        redirect('/login.php');
                    }
                } else {
                    $error = $resetResult['error'];
                }
            }
        }
    }
}

$pageTitle = 'Changer le mot de passe';
$loggedIn = !$is_expired;
$username = $_SESSION['username'] ?? 'Utilisateur';
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <?php if (!$is_expired): ?>
                <li><a href="/dashboard.php">Tableau de bord</a></li>
                <li><a href="/profile.php">Mon profil</a></li>
                <li><a href="/change-password.php" class="active">Changer le mot de passe</a></li>
            <?php else: ?>
                <li><a href="/login.php">Retour à la connexion</a></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title"><i class="fas fa-key"></i> Changer le mot de passe</h2>

            <?php if ($is_expired): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> Votre mot de passe a expiré (durée maximale : 2 heures). Vous devez le changer avant de continuer.
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label for="current_password">Mot de passe actuel</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">Nouveau mot de passe (min. 8 caractères)</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Changer le mot de passe</button>
                    <?php if (!$is_expired): ?>
                        <a href="/dashboard.php" class="btn btn-warning">Annuler</a>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-warning">Retour</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
