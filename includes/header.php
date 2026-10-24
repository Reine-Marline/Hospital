<?php
require_once __DIR__ . '/functions.php';
startSecureSession();

$loggedIn = isLoggedIn();
$username = $_SESSION['username'] ?? '';
$userRole = $_SESSION['user_role'] ?? '';
$userTheme = $_SESSION['theme'] ?? 'light';
$unreadCount = 0;

if ($loggedIn && isset($conn)) {
    $countQuery = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0');
    if ($countQuery) {
        $countQuery->bind_param('i', $_SESSION['user_id']);
        $countQuery->execute();
        $countResult = $countQuery->get_result()->fetch_assoc();
        $unreadCount = (int)($countResult['cnt'] ?? 0);
        $countQuery->close();
    }
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$navLink = static function (string $path, string $label, string $icon) use ($currentPath): string {
    $active = $currentPath === $path ? ' aria-current="page"' : '';
    return '<a href="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"' . $active . '><i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
};
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?php echo htmlspecialchars($userTheme, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f8fafc">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Gestion Hôpital', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<header class="app-header">
    <nav class="navbar" aria-label="Navigation principale">
        <div class="navbar-container">
            <a href="/dashboard.php" class="navbar-logo" aria-label="HôpitalWeb, accueil">
                <i class="fas fa-hospital-user" aria-hidden="true"></i><span>HôpitalWeb</span>
            </a>
            <?php if ($loggedIn): ?>
                <button class="nav-toggle" type="button" aria-controls="primary-navigation" aria-expanded="false" aria-label="Ouvrir le menu">
                    <span></span><span></span><span></span>
                </button>
            <?php endif; ?>
            <div class="navbar-menu" id="primary-navigation">
                <?php if ($loggedIn): ?>
                    <div class="nav-links">
                        <?php echo $navLink('/dashboard.php', 'Accueil', 'fas fa-house'); ?>
                        <details class="nav-group <?php echo in_array($currentPath, ['/patients.php', '/consultations.php', '/appointments.php', '/admissions.php', '/medecins.php'], true) ? 'has-current' : ''; ?>">
                            <summary><i class="fas fa-heart-pulse" aria-hidden="true"></i><span>Soins</span><i class="fas fa-chevron-down nav-chevron" aria-hidden="true"></i></summary>
                            <div class="nav-dropdown">
                                <?php echo $navLink('/patients.php', 'Patients', 'fas fa-users'); ?>
                                <?php echo $navLink('/consultations.php', 'Consultations', 'fas fa-stethoscope'); ?>
                                <?php echo $navLink('/appointments.php', 'Rendez-vous', 'fas fa-calendar-check'); ?>
                                <?php echo $navLink('/admissions.php', 'Hospitalisations', 'fas fa-bed-pulse'); ?>
                                <?php echo $navLink('/medecins.php', 'Médecins', 'fas fa-user-doctor'); ?>
                            </div>
                        </details>
                        <details class="nav-group <?php echo in_array($currentPath, ['/pharmacy.php', '/laboratory.php', '/factures.php', '/documents.php', '/reports.php'], true) ? 'has-current' : ''; ?>">
                            <summary><i class="fas fa-briefcase-medical" aria-hidden="true"></i><span>Services</span><i class="fas fa-chevron-down nav-chevron" aria-hidden="true"></i></summary>
                            <div class="nav-dropdown">
                                <?php echo $navLink('/pharmacy.php', 'Pharmacie', 'fas fa-capsules'); ?>
                                <?php echo $navLink('/laboratory.php', 'Laboratoire', 'fas fa-flask'); ?>
                                <?php echo $navLink('/factures.php', 'Facturation', 'fas fa-file-invoice-dollar'); ?>
                                <?php echo $navLink('/documents.php', 'Documents', 'fas fa-folder-open'); ?>
                                <?php echo $navLink('/reports.php', 'Rapports', 'fas fa-chart-line'); ?>
                            </div>
                        </details>
                        <details class="nav-group <?php echo in_array($currentPath, ['/chat.php', '/notifications.php'], true) ? 'has-current' : ''; ?>">
                            <summary><i class="fas fa-comments" aria-hidden="true"></i><span>Communication</span><i class="fas fa-chevron-down nav-chevron" aria-hidden="true"></i></summary>
                            <div class="nav-dropdown">
                                <?php echo $navLink('/chat.php', 'Messagerie', 'fas fa-comment-dots'); ?>
                                <?php echo $navLink('/notifications.php', 'Notifications', 'fas fa-bell'); ?>
                            </div>
                        </details>
                    </div>
                    <div class="nav-account">
                        <?php if ($userRole === 'admin'): ?>
                            <?php echo $navLink('/admin/index.php', 'Administration', 'fas fa-sliders'); ?>
                        <?php endif; ?>
                        <a class="nav-user" href="/profile.php"><i class="fas fa-user-circle" aria-hidden="true"></i><span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span></a>
                        <a class="nav-logout" href="/logout.php" aria-label="Déconnexion"><i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Quitter</span></a>
                    </div>
                <?php else: ?>
                    <div class="nav-account nav-account-public">
                        <?php echo $navLink('/login.php', 'Connexion', 'fas fa-right-to-bracket'); ?>
                        <?php echo $navLink('/register.php', 'Inscription', 'fas fa-user-plus'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>
</header>
<main class="container">
    <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?>" role="status">
            <?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
