<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

startSecureSession();
requireRole('admin');

if (!checkSessionExpiration()) {
    redirect('/login.php');
}

$user_id = getUserId();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$csrf_token = generateCsrfToken();

// Récupérer la liste des utilisateurs
$users = [];

if ($action === 'list') {
    $query = "SELECT id, username, email, role, status, created_at, last_login FROM users ORDER BY created_at DESC LIMIT 100";
    $result = $conn->query($query);
    if ($result) {
        $users = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Traiter le déblocage d'un compte
if (isset($_GET['unlock_id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $unlock_id = (int)$_GET['unlock_id'];
        unlockAccount($unlock_id, $conn);
        $success = 'Compte déverrouillé avec succès.';
        logActivity($user_id, 'UNLOCK_ACCOUNT', 'Déverrouillage d\'un compte utilisateur', $conn);
    }
    $action = 'list';
}

// Traiter la suppression d'un utilisateur
if (isset($_GET['delete_id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $delete_id = (int)$_GET['delete_id'];
        if ($delete_id === $user_id) {
            $error = 'Vous ne pouvez pas supprimer votre propre compte.';
        } else {
            $query = "DELETE FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                if ($stmt->execute()) {
                    $success = 'Utilisateur supprimé avec succès.';
                    logActivity($user_id, 'DELETE_USER', 'Suppression d\'un utilisateur', $conn);
                }
                $stmt->close();
            }
        }
    }
    $action = 'list';
}

$pageTitle = 'Gestion des Utilisateurs';
$loggedIn = true;
$username = $_SESSION['username'];
$userRole = 'admin';
?>

<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/admin/users.php" class="active">Utilisateurs</a></li>
            <li><a href="/admin/activity.php">Activités</a></li>
            <li><a href="/admin/backup.php">Sauvegardes</a></li>
            <li><a href="/admin/index.php">Tableau de bord admin</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title">Gestion des Utilisateurs</h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom d'utilisateur</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Statut</th>
                        <th>Inscrit le</th>
                        <th>Dernière connexion</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $usr): ?>
                    <tr>
                        <td><?php echo $usr['id']; ?></td>
                        <td><?php echo htmlspecialchars($usr['username']); ?></td>
                        <td><?php echo htmlspecialchars($usr['email']); ?></td>
                        <td><span class="badge badge-info"><?php echo ucfirst($usr['role']); ?></span></td>
                        <td>
                            <span class="badge badge-<?php echo $usr['status'] === 'active' ? 'success' : ($usr['status'] === 'locked' ? 'danger' : 'warning'); ?>">
                                <?php echo ucfirst($usr['status']); ?>
                            </span>
                        </td>
                        <td><?php echo formatDate($usr['created_at']); ?></td>
                        <td><?php echo formatDate($usr['last_login']); ?></td>
                        <td>
                            <?php if ($usr['status'] === 'locked'): ?>
                                <a href="/admin/users.php?unlock_id=<?php echo $usr['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-warning" style="padding: 0.5rem 1rem; font-size: 0.9rem;">Débloquer</a>
                            <?php endif; ?>
                            <?php if ($usr['id'] !== $user_id): ?>
                                <a href="/admin/users.php?delete_id=<?php echo $usr['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding: 0.5rem 1rem; font-size: 0.9rem;" onclick="return confirm('Êtes-vous sûr ?')">Supprimer</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (empty($users)): ?>
                <p style="text-align: center; margin-top: 2rem; color: #666;">Aucun utilisateur trouvé.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
