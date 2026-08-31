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
$error = '';
$success = '';
$csrf_token = generateCsrfToken();

// Traiter le backup manuel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        // Créer un backup
        $backupDir = __DIR__ . '/../backups';
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Utiliser mysqldump si disponible
        $command = "mysqldump -h localhost -u admin_hopital -pAdmin@123 hopital_db > " . escapeshellarg($backupFile);
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0) {
            $success = 'Sauvegarde créée avec succès.';
            logActivity($user_id, 'BACKUP_CREATED', 'Création d\'une sauvegarde manuelle', $conn);
        } else {
            $error = 'Erreur lors de la création de la sauvegarde.';
        }
    }
}

// Récupérer la liste des backups
$backups = [];
$backupDir = __DIR__ . '/../backups';
if (file_exists($backupDir)) {
    $files = scandir($backupDir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && strpos($file, 'backup_') === 0) {
            $backups[] = [
                'name' => $file,
                'size' => filesize($backupDir . '/' . $file),
                'date' => filemtime($backupDir . '/' . $file)
            ];
        }
    }
}

$pageTitle = 'Gestion des Sauvegardes';
$loggedIn = true;
$username = $_SESSION['username'];
$userRole = 'admin';
?>

<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/admin/users.php">Utilisateurs</a></li>
            <li><a href="/admin/activity.php">Activités</a></li>
            <li><a href="/admin/backup.php" class="active">Sauvegardes</a></li>
            <li><a href="/admin/index.php">Tableau de bord admin</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="card">
            <h2 class="card-title">Gestion des Sauvegardes</h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" style="margin-bottom: 2rem;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="backup" value="1">
                <button type="submit" class="btn btn-primary">Créer une sauvegarde maintenant</button>
            </form>

            <h3>Sauvegardes disponibles</h3>

            <?php if (!empty($backups)): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Nom du fichier</th>
                        <th>Date</th>
                        <th>Taille</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($backup['name']); ?></td>
                        <td><?php echo date('d/m/Y H:i:s', $backup['date']); ?></td>
                        <td><?php echo round($backup['size'] / 1024 / 1024, 2); ?> MB</td>
                        <td>
                            <a href="/admin/download-backup.php?file=<?php echo urlencode($backup['name']); ?>" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.9rem;">Télécharger</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color: #666;">Aucune sauvegarde trouvée.</p>
            <?php endif; ?>

            <div style="margin-top: 2rem; padding: 1rem; background-color: #f0f0f0; border-radius: 4px;">
                <h4>Information</h4>
                <p>Les sauvegardes sont automatiquement créées selon la planification définie dans la base de données.</p>
                <p>Vous pouvez télécharger les fichiers de sauvegarde pour les stocker hors ligne.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
