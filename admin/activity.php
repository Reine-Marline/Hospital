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

// Exporter en CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'journal_activites_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, ['Date/Heure', 'Utilisateur', 'Action', 'Details', 'Adresse IP']);
    
    // Data
    $query = "SELECT al.*, u.username 
              FROM activity_log al
              LEFT JOIN users u ON al.user_id = u.id
              ORDER BY al.timestamp DESC";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                formatDate($row['timestamp']),
                $row['username'] ?? 'Anonyme',
                $row['action'],
                $row['details'],
                $row['ip_address']
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// Récupérer le journal des activités
$activities = [];
$query = "SELECT al.*, u.username 
          FROM activity_log al
          LEFT JOIN users u ON al.user_id = u.id
          ORDER BY al.timestamp DESC LIMIT 500";
$result = $conn->query($query);
if ($result) {
    $activities = $result->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = 'Journal des Activités';
$loggedIn = true;
$username = $_SESSION['username'];
$userRole = 'admin';
?>

<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/admin/users.php">Utilisateurs</a></li>
            <li><a href="/admin/activity.php" class="active">Activités</a></li>
            <li><a href="/admin/backup.php">Sauvegardes</a></li>
            <li><a href="/admin/index.php">Tableau de bord admin</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title">Journal des Activités</h2>

            <div style="margin-bottom: 1rem;">
                <a href="/admin/activity.php?export=csv" class="btn btn-warning">Exporter en CSV</a>
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th>Date/Heure</th>
                        <th>Utilisateur</th>
                        <th>Action</th>
                        <th>Détails</th>
                        <th>Adresse IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td><?php echo formatDate($activity['timestamp']); ?></td>
                        <td><?php echo htmlspecialchars($activity['username'] ?? 'Anonyme'); ?></td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($activity['action']); ?></span></td>
                        <td><?php echo htmlspecialchars(substr($activity['details'], 0, 50)); ?></td>
                        <td><?php echo htmlspecialchars($activity['ip_address']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (empty($activities)): ?>
                <p style="text-align: center; margin-top: 2rem; color: #666;">Aucune activité enregistrée.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
