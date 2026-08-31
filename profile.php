<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();

if (!checkSessionExpiration()) {
    redirect('/login.php');
}

$user_id = getUserId();
$username = $_SESSION['username'];
$error = '';
$success = '';

// Récupérer les informations de l'utilisateur
$currentUser = getCurrentUser($conn);

// Traiter la modification du profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        
        if (empty($email)) {
            $error = 'L\'email est obligatoire.';
        } else {
            // Vérifier que l'email n'existe pas chez un autre utilisateur
            $checkQuery = "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("si", $email, $user_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $checkStmt->close();
            
            if ($result->num_rows > 0) {
                $error = 'Cet email est déjà utilisé.';
            } else {
                // Mettre à jour le profil
                $updateQuery = "UPDATE users SET email = ?, phone = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("ssi", $email, $phone, $user_id);
                
                if ($updateStmt->execute()) {
                    $success = 'Profil mis à jour avec succès.';
                    $_SESSION['email'] = $email;
                    $currentUser = getCurrentUser($conn);
                } else {
                    $error = 'Erreur lors de la mise à jour.';
                }
                $updateStmt->close();
            }
        }
    }
}

$csrf_token = generateCsrfToken();
$pageTitle = 'Mon profil';
$loggedIn = true;
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/dashboard.php">Tableau de bord</a></li>
            <li><a href="/profile.php" class="active">Mon profil</a></li>
            <li><a href="/change-password.php">Changer le mot de passe</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title">Mon profil</h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label>Nom d'utilisateur</label>
                    <input type="text" value="<?php echo htmlspecialchars($currentUser['username']); ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($currentUser['email']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Rôle</label>
                    <input type="text" value="<?php echo htmlspecialchars($currentUser['role']); ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="phone">Téléphone</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Inscrit le</label>
                    <input type="text" value="<?php echo formatDate($currentUser['created_at']); ?>" disabled>
                </div>

                <div class="form-group">
                    <label>Dernière connexion</label>
                    <input type="text" value="<?php echo formatDate($currentUser['last_login']); ?>" disabled>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
                    <a href="/dashboard.php" class="btn btn-warning">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
