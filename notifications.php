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
$csrf_token = generateCsrfToken();

// Récupérer les notifications
$query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Marquer comme lue
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $notification_id = (int)$_GET['id'];
    $query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $notification_id, $user_id);
    $stmt->execute();
    $stmt->close();
    redirect('/notifications.php');
}

// Effacer toutes les notifications
if (isset($_GET['clear_all'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $query = "DELETE FROM notifications WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        redirect('/notifications.php');
    }
}

// Supprimer une notification
if (isset($_GET['delete_id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $notification_id = (int)$_GET['delete_id'];
        $query = "DELETE FROM notifications WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $notification_id, $user_id);
        $stmt->execute();
        $stmt->close();
        redirect('/notifications.php');
    }
}

$pageTitle = 'Notifications';
$loggedIn = true;
$username = $_SESSION['username'];
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/dashboard.php">Tableau de bord</a></li>
            <li><a href="/notifications.php" class="active">Notifications</a></li>
            <li><a href="/profile.php">Mon profil</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title">Mes Notifications</h2>

            <?php if (!empty($notifications)): ?>
            <div style="margin-bottom: 1rem;">
                <a href="/notifications.php?clear_all=1&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-warning" onclick="return confirm('Êtes-vous sûr ?')">Effacer tout</a>
            </div>

            <?php foreach ($notifications as $notif): ?>
            <div style="padding: 1rem; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 1rem; background-color: <?php echo $notif['is_read'] ? '#fff' : '#f9f9f9'; ?>;">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 0.5rem 0; color: #2c3e50;">
                            <?php echo htmlspecialchars($notif['title']); ?>
                            <?php if (!$notif['is_read']): ?>
                                <span class="badge badge-warning">Nouveau</span>
                            <?php endif; ?>
                        </h3>
                        <p style="margin: 0 0 0.5rem 0; color: #666;">
                            <?php echo htmlspecialchars($notif['message']); ?>
                        </p>
                        <p style="margin: 0; font-size: 0.9rem; color: #999;">
                            <?php echo formatDate($notif['created_at']); ?>
                        </p>
                    </div>
                    <div style="margin-left: 1rem;">
                        <?php if (!$notif['is_read']): ?>
                            <a href="/notifications.php?mark_read=1&id=<?php echo $notif['id']; ?>" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.9rem;">Marquer comme lue</a>
                        <?php endif; ?>
                        <a href="/notifications.php?delete_id=<?php echo $notif['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding: 0.5rem 1rem; font-size: 0.9rem; margin-left: 0.5rem;" onclick="return confirm('Êtes-vous sûr ?')">Supprimer</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php else: ?>
            <p style="text-align: center; color: #666; padding: 2rem;">Vous n'avez pas de notifications.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
