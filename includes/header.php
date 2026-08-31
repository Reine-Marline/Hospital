<?php
/**
 * En-tête de l'application - PHP pur, pas de JavaScript
 */
require_once __DIR__ . '/functions.php';
startSecureSession();

$loggedIn = isLoggedIn();
$username = $_SESSION['username'] ?? '';
$userRole = $_SESSION['user_role'] ?? '';
$userTheme = $_SESSION['theme'] ?? 'light';

// Compteur de notifications non lues
$unreadCount = 0;
if ($loggedIn && isset($conn)) {
    $countQuery = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($countQuery) {
        $countQuery->bind_param("i", $_SESSION['user_id']);
        $countQuery->execute();
        $countRes = $countQuery->get_result()->fetch_assoc();
        $unreadCount = $countRes['cnt'];
        $countQuery->close();
    }
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?php echo htmlspecialchars($userTheme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Gestion Hôpital'; ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <a href="/dashboard.php" class="navbar-logo">
                <i class="fas fa-hospital-user"></i> HôpitalWeb
            </a>
            <ul class="navbar-menu">
                <?php if ($loggedIn): ?>
                    <li><a href="/dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a></li>
                    <li><a href="/patients.php"><i class="fas fa-users"></i> Patients</a></li>
                    <li><a href="/medecins.php"><i class="fas fa-stethoscope"></i> Médecins</a></li>
                    <li><a href="/consultations.php"><i class="fas fa-clipboard-list"></i> Consultations</a></li>
                    <li><a href="/factures.php"><i class="fas fa-file-invoice-dollar"></i> Factures</a></li>
                    <li><a href="/documents.php"><i class="fas fa-folder-open"></i> Documents</a></li>
                    <li><a href="/chat.php"><i class="fas fa-comments"></i> Chat</a></li>
                    <li><a href="/reports.php"><i class="fas fa-chart-line"></i> Rapports</a></li>
                    <?php if ($userRole === 'admin'): ?>
                        <li><a href="/admin/index.php"><i class="fas fa-sliders-h"></i> Admin</a></li>
                    <?php endif; ?>
                    <li>
                        <a href="/notifications.php">
                            <i class="fas fa-bell"></i>
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge badge-danger"><?php echo $unreadCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="/profile.php"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($username); ?></a></li>
                    <li><a href="/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                <?php else: ?>
                    <li><a href="/login.php"><i class="fas fa-sign-in-alt"></i> Connexion</a></li>
                    <li><a href="/register.php"><i class="fas fa-user-plus"></i> Inscription</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <div class="container">
        <?php
        $flash = getFlashMessage();
        if ($flash):
            $class = 'alert-' . $flash['type'];
        ?>
            <div class="alert <?php echo htmlspecialchars($class); ?>">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>
