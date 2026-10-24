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
$csrf_token = generateCsrfToken();

// Récupérer les statistiques
$totalUsers = 0;
$totalPatients = 0;
$totalDoctors = 0;
$totalConsultations = 0;

$query = "SELECT COUNT(*) as count FROM users";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $totalUsers = $row['count'];
}

$query = "SELECT COUNT(*) as count FROM patients";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $totalPatients = $row['count'];
}

$query = "SELECT COUNT(*) as count FROM medecins";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $totalDoctors = $row['count'];
}

$query = "SELECT COUNT(*) as count FROM consultations";
$result = $conn->query($query);
if ($result) {
    $row = $result->fetch_assoc();
    $totalConsultations = $row['count'];
}

$pageTitle = 'Panneau d\'Administration';
$loggedIn = true;
$username = $_SESSION['username'];
$userRole = 'admin';
?>

<?php require_once __DIR__ . '/../includes/header.php'; ?>

<section class="page-heading">
    <div><p class="eyebrow">Gestion de la plateforme</p><h1>Administration</h1><p>Comptes, activités et maintenance du système.</p></div>
</section>
    
    <div class="dashboard-grid" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <h3><?php echo $totalUsers; ?></h3>
            <p>Utilisateurs</p>
        </div>
        <div class="stat-card">
            <h3><?php echo $totalPatients; ?></h3>
            <p>Patients</p>
        </div>
        <div class="stat-card">
            <h3><?php echo $totalDoctors; ?></h3>
            <p>Médecins</p>
        </div>
        <div class="stat-card">
            <h3><?php echo $totalConsultations; ?></h3>
            <p>Consultations</p>
        </div>
    </div>

    <div class="sidebar">
        <div class="sidebar-menu">
            <ul>
                <li><a href="/admin/index.php" class="active">Tableau de bord</a></li>
                <li><a href="/admin/users.php">Gestion des utilisateurs</a></li>
                <li><a href="/admin/activity.php">Journal des activités</a></li>
                <li><a href="/admin/backup.php">Sauvegardes</a></li>
                <li><a href="/tables.php">Tables de données</a></li>
                <li><a href="/dashboard.php">Retour au tableau de bord</a></li>
            </ul>
        </div>

        <div class="main-content">
            <div class="card">
                <h2 class="card-title">Actions d'administration</h2>
                <div class="btn-group">
                    <a href="/admin/users.php" class="btn btn-primary">Gérer les utilisateurs</a>
                    <a href="/admin/activity.php" class="btn btn-primary">Voir les activités</a>
                    <a href="/admin/backup.php" class="btn btn-warning">Gérer les sauvegardes</a>
                    <a href="/tables.php" class="btn btn-secondary">Voir les tables de données</a>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">Système</h2>
                <p>
                    <strong>Version PHP:</strong> <?php echo phpversion(); ?><br>
                    <strong>Base de données:</strong> MySQL<br>
                    <strong>Serveur:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?>
                </p>
            </div>
        </div>
    </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
