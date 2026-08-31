<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();
if (!checkSessionExpiration()) { redirect('/login.php'); }

$user_id = getUserId();
$username = $_SESSION['username'];
$userRole = $_SESSION['user_role'] ?? '';

// Statistiques principales
$r = $conn->query("SELECT COUNT(*) as c FROM patients WHERE is_archived=0"); $patientCount = $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) as c FROM medecins WHERE is_archived=0"); $doctorCount = $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) as c FROM consultations WHERE is_archived=0"); $consultationCount = $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COALESCE(SUM(montant),0) as t FROM factures WHERE status='paid' AND is_archived=0"); $revenuTotal = $r->fetch_assoc()['t'];
$r = $conn->query("SELECT COUNT(*) as c FROM factures WHERE status='pending' AND is_archived=0"); $pendingCount = $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) as c FROM documents WHERE is_archived=0"); $docCount = $r->fetch_assoc()['c'];

// Dernières consultations
$lastConsultations = [];
$r = $conn->query("SELECT c.date_consultation, p.nom as pnom, p.prenom as pprenom, m.nom as mnom, m.prenom as mprenom, c.motif FROM consultations c LEFT JOIN patients p ON c.patient_id=p.id LEFT JOIN medecins m ON c.medecin_id=m.id WHERE c.is_archived=0 ORDER BY c.date_consultation DESC LIMIT 5");
if ($r) $lastConsultations = $r->fetch_all(MYSQLI_ASSOC);

// Dernières factures
$lastFactures = [];
$r = $conn->query("SELECT f.numero_facture, f.montant, f.status, f.created_at, p.nom, p.prenom FROM factures f LEFT JOIN patients p ON f.patient_id=p.id WHERE f.is_archived=0 ORDER BY f.created_at DESC LIMIT 5");
if ($r) $lastFactures = $r->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Tableau de bord';
require_once __DIR__ . '/includes/header.php';
?>

<h1 style="margin-bottom:2rem;"><i class="fas fa-tachometer-alt"></i> Bienvenue, <?php echo htmlspecialchars($username); ?> !</h1>

<!-- Cartes de statistiques -->
<div class="dashboard-grid">
    <div class="stat-card">
        <div class="stat-card-icon"><i class="fas fa-users"></i></div>
        <div class="stat-card-info"><h3><?php echo $patientCount; ?></h3><p>Patients</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background:rgba(16,185,129,0.1);color:#059669;"><i class="fas fa-stethoscope"></i></div>
        <div class="stat-card-info"><h3><?php echo $doctorCount; ?></h3><p>Médecins</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background:rgba(245,158,11,0.1);color:#d97706;"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-card-info"><h3><?php echo $consultationCount; ?></h3><p>Consultations</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background:rgba(16,185,129,0.1);color:#059669;"><i class="fas fa-euro-sign"></i></div>
        <div class="stat-card-info"><h3><?php echo number_format($revenuTotal,0,',',' '); ?> €</h3><p>Revenus</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background:rgba(239,68,68,0.1);color:#dc2626;"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-card-info"><h3><?php echo $pendingCount; ?></h3><p>Factures en attente</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;"><i class="fas fa-folder-open"></i></div>
        <div class="stat-card-info"><h3><?php echo $docCount; ?></h3><p>Documents</p></div>
    </div>
</div>

<!-- Actions rapides -->
<div class="card">
    <h2 class="card-title"><i class="fas fa-bolt"></i> Actions rapides</h2>
    <div class="btn-group" style="flex-wrap:wrap;">
        <a href="/patients.php?action=new" class="btn btn-primary"><i class="fas fa-user-plus"></i> Nouveau patient</a>
        <a href="/medecins.php?action=new" class="btn btn-primary"><i class="fas fa-user-md"></i> Nouveau médecin</a>
        <a href="/consultations.php?action=new" class="btn btn-success"><i class="fas fa-plus-circle"></i> Nouvelle consultation</a>
        <a href="/factures.php?action=new" class="btn btn-warning"><i class="fas fa-file-invoice"></i> Nouvelle facture</a>
        <a href="/documents.php?action=upload" class="btn btn-secondary"><i class="fas fa-cloud-upload-alt"></i> Téléverser document</a>
        <a href="/reports.php" class="btn btn-danger"><i class="fas fa-chart-line"></i> Rapports</a>
    </div>
</div>

<!-- Grille des dernières données -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;">
    <!-- Dernières consultations -->
    <div class="card">
        <h2 class="card-title"><i class="fas fa-clipboard-list"></i> Dernières consultations</h2>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Patient</th><th>Médecin</th><th>Motif</th></tr></thead>
                <tbody>
                    <?php foreach ($lastConsultations as $lc): ?>
                    <tr>
                        <td><?php echo formatDate($lc['date_consultation'],'d/m/Y'); ?></td>
                        <td><?php echo htmlspecialchars(($lc['pnom']??'').' '.($lc['pprenom']??'')); ?></td>
                        <td>Dr. <?php echo htmlspecialchars(($lc['mnom']??'').' '.($lc['mprenom']??'')); ?></td>
                        <td><?php echo htmlspecialchars(substr($lc['motif'],0,25)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lastConsultations)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-light);">Aucune consultation.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <a href="/consultations.php" class="btn btn-secondary" style="margin-top:1rem;">Voir tout</a>
    </div>

    <!-- Dernières factures -->
    <div class="card">
        <h2 class="card-title"><i class="fas fa-file-invoice-dollar"></i> Dernières factures</h2>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Numéro</th><th>Patient</th><th>Montant</th><th>Statut</th></tr></thead>
                <tbody>
                    <?php foreach ($lastFactures as $lf): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($lf['numero_facture']); ?></code></td>
                        <td><?php echo htmlspecialchars(($lf['nom']??'').' '.($lf['prenom']??'')); ?></td>
                        <td><?php echo number_format($lf['montant'],2); ?> €</td>
                        <td><span class="badge badge-<?php echo $lf['status']==='paid'?'success':($lf['status']==='pending'?'warning':'danger'); ?>"><?php echo ucfirst($lf['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lastFactures)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-light);">Aucune facture.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <a href="/factures.php" class="btn btn-secondary" style="margin-top:1rem;">Voir tout</a>
    </div>
</div>

<?php if ($userRole === 'admin'): ?>
<div class="card" style="margin-top:2rem;">
    <h2 class="card-title"><i class="fas fa-sliders-h"></i> Administration</h2>
    <div class="btn-group" style="flex-wrap:wrap;">
        <a href="/admin/users.php" class="btn btn-warning"><i class="fas fa-users-cog"></i> Utilisateurs</a>
        <a href="/admin/activity.php" class="btn btn-warning"><i class="fas fa-history"></i> Journal activités</a>
        <a href="/admin/login-history.php" class="btn btn-warning"><i class="fas fa-sign-in-alt"></i> Historique connexions</a>
        <a href="/admin/settings.php" class="btn btn-warning"><i class="fas fa-cogs"></i> Paramètres</a>
        <a href="/admin/backup.php" class="btn btn-danger"><i class="fas fa-database"></i> Sauvegardes</a>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
