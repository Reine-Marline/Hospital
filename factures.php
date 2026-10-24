<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();
if (!checkSessionExpiration()) { redirect('/login.php'); }

$user_id = getUserId();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$csrf_token = generateCsrfToken();

$sort_by = in_array($_GET['sort_by'] ?? '', ['id','montant','status','created_at']) ? $_GET['sort_by'] : 'created_at';
$order = ($_GET['order'] ?? '') === 'asc' ? 'ASC' : 'DESC';
$next_order = $order === 'ASC' ? 'desc' : 'asc';
$searchTerm = sanitize($_GET['search'] ?? '');
$filter_status = sanitize($_GET['filter_status'] ?? '');
$view_archive = (isset($_GET['status']) && $_GET['status'] === 'archived') ? 1 : 0;

// Archivage
if (isset($_GET['archive_id']) && verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    $aid = (int)$_GET['archive_id'];
    $stmt = $conn->prepare("UPDATE factures SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $aid); $stmt->execute(); $stmt->close();
    $success = 'Facture archivée.'; $action = 'list';
    logActivity($user_id, 'ARCHIVE_INVOICE', 'Archivage facture ID: '.$aid, $conn);
}
// Restauration
if (isset($_GET['restore_id']) && verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    $rid = (int)$_GET['restore_id'];
    $stmt = $conn->prepare("UPDATE factures SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $rid); $stmt->execute(); $stmt->close();
    $success = 'Facture restaurée.'; $action = 'list';
    logActivity($user_id, 'RESTORE_INVOICE', 'Restauration facture ID: '.$rid, $conn);
}
// Suppression
if (isset($_GET['delete_id']) && verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    $did = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM factures WHERE id = ?");
    $stmt->bind_param("i", $did); $stmt->execute(); $stmt->close();
    $success = 'Facture supprimée.'; $action = 'list';
    logActivity($user_id, 'DELETE_INVOICE', 'Suppression facture ID: '.$did, $conn);
}

// Données pour formulaire
$patients_list = [];
$consultations_list = [];
if (in_array($action, ['new', 'edit'])) {
    $r = $conn->query("SELECT id, nom, prenom FROM patients WHERE is_archived=0 ORDER BY nom");
    if ($r) $patients_list = $r->fetch_all(MYSQLI_ASSOC);
    $r2 = $conn->query("SELECT c.id, c.date_consultation, p.nom, p.prenom FROM consultations c JOIN patients p ON c.patient_id=p.id WHERE c.is_archived=0 ORDER BY c.date_consultation DESC");
    if ($r2) $consultations_list = $r2->fetch_all(MYSQLI_ASSOC);
}

// Ajout/Modification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['new', 'edit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { $error = 'Erreur de sécurité.'; }
    else {
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $consultation_id = (int)($_POST['consultation_id'] ?? 0);
        $montant = (float)($_POST['montant'] ?? 0);
        $description = sanitize($_POST['description'] ?? '');
        $fstatus = sanitize($_POST['status'] ?? 'pending');
        if ($patient_id === 0 || $montant <= 0) { $error = 'Patient et montant requis.'; }
        else {
            if ($action === 'new') {
                $numero = 'FAC-' . date('YmdHis');
                $stmt = $conn->prepare("INSERT INTO factures (patient_id, consultation_id, numero_facture, montant, description, status, created_at, date_facturation, is_archived) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 0)");
                $stmt->bind_param("iisdss", $patient_id, $consultation_id, $numero, $montant, $description, $fstatus);
                if ($stmt->execute()) { $success = 'Facture créée.'; $action = 'list'; logActivity($user_id, 'ADD_INVOICE', 'Facture: '.$numero, $conn); }
                else { $error = 'Erreur.'; }
                $stmt->close();
            } else {
                $fid = (int)$_GET['id'];
                $stmt = $conn->prepare("UPDATE factures SET patient_id=?, consultation_id=?, montant=?, description=?, status=? WHERE id=?");
                $stmt->bind_param("iidssi", $patient_id, $consultation_id, $montant, $description, $fstatus, $fid);
                if ($stmt->execute()) { $success = 'Facture modifiée.'; $action = 'list'; logActivity($user_id, 'EDIT_INVOICE', 'Modification facture ID: '.$fid, $conn); }
                else { $error = 'Erreur.'; }
                $stmt->close();
            }
        }
    }
}

// Récupérer facture pour édition
$currentFacture = null;
if (in_array($action, ['edit', 'view']) && isset($_GET['id'])) {
    $fid = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT f.*, p.nom as patient_nom, p.prenom as patient_prenom FROM factures f LEFT JOIN patients p ON f.patient_id=p.id WHERE f.id=? LIMIT 1");
    $stmt->bind_param("i", $fid); $stmt->execute();
    $currentFacture = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = 'factures_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Numéro', 'Patient', 'Montant', 'Status', 'Date']);
    $exResult = $conn->query("SELECT f.*, p.nom as patient_nom, p.prenom as patient_prenom FROM factures f LEFT JOIN patients p ON f.patient_id=p.id ORDER BY f.created_at DESC");
    if ($exResult) {
        while ($row = $exResult->fetch_assoc()) {
            fputcsv($output, [$row['id'], $row['numero_facture'], $row['patient_nom'].' '.$row['patient_prenom'], $row['montant'], $row['status'], formatDate($row['created_at'])]);
        }
    }
    fclose($output); exit;
}

// Liste des factures
$factures = [];
if ($action === 'list') {
    $sql = "SELECT f.*, p.nom as patient_nom, p.prenom as patient_prenom FROM factures f LEFT JOIN patients p ON f.patient_id=p.id WHERE f.is_archived=?";
    $params = [$view_archive]; $types = 'i';
    if (!empty($searchTerm)) {
        $sql .= " AND (p.nom LIKE ? OR p.prenom LIKE ? OR f.numero_facture LIKE ?)";
        $sp = '%'.$searchTerm.'%'; $params[]=$sp; $params[]=$sp; $params[]=$sp; $types.='sss';
    }
    if (!empty($filter_status)) {
        $sql .= " AND f.status = ?"; $params[] = $filter_status; $types .= 's';
    }
    $sql .= " ORDER BY $sort_by $order LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($stmt) { $stmt->bind_param($types, ...$params); $stmt->execute(); $factures = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); }
}

$pageTitle = 'Gestion des Factures';
require_once __DIR__ . '/includes/header.php';
?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/factures.php" <?php echo ($action==='list'&&!$view_archive)?'class="active"':''; ?>><i class="fas fa-file-invoice-dollar"></i> Factures</a></li>
            <li><a href="/factures.php?status=archived" <?php echo $view_archive?'class="active"':''; ?>><i class="fas fa-archive"></i> Archivées</a></li>
            <li><a href="/factures.php?action=new" <?php echo ($action==='new')?'class="active"':''; ?>><i class="fas fa-plus-circle"></i> Nouvelle</a></li>
            <li><a href="/factures.php?export=excel"><i class="fas fa-file-excel"></i> Exporter CSV</a></li>
            <li><a href="/patients.php"><i class="fas fa-users"></i> Patients</a></li>
            <li><a href="/consultations.php"><i class="fas fa-clipboard-list"></i> Consultations</a></li>
        </ul>
    </div>

    <div class="main-content">
        <?php if ($action === 'list'): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas fa-file-invoice-dollar"></i> <?php echo $view_archive?'Factures Archivées':'Liste des Factures'; ?></h2>
            <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

            <div style="margin-bottom:1.5rem;padding:1rem;background:var(--light);border:1px solid var(--border);border-radius:var(--radius-sm);">
                <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end;">
                    <?php if ($view_archive): ?><input type="hidden" name="status" value="archived"><?php endif; ?>
                    <div class="form-group" style="margin-bottom:0;flex:1;min-width:200px;">
                        <label for="search">Rechercher</label>
                        <input type="text" id="search" name="search" placeholder="Patient, numéro..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;min-width:150px;">
                        <label for="filter_status">Statut</label>
                        <select id="filter_status" name="filter_status">
                            <option value="">Tous</option>
                            <option value="pending" <?php echo $filter_status==='pending'?'selected':''; ?>>En attente</option>
                            <option value="paid" <?php echo $filter_status==='paid'?'selected':''; ?>>Payée</option>
                            <option value="cancelled" <?php echo $filter_status==='cancelled'?'selected':''; ?>>Annulée</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrer</button>
                    <a href="/factures.php<?php echo $view_archive?'?status=archived':''; ?>" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr>
                        <th><a href="/factures.php?sort_by=id&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&filter_status=<?php echo urlencode($filter_status); ?><?php echo $view_archive?'&status=archived':''; ?>">ID <i class="fas fa-sort"></i></a></th>
                        <th>Numéro</th><th>Patient</th>
                        <th><a href="/factures.php?sort_by=montant&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&filter_status=<?php echo urlencode($filter_status); ?><?php echo $view_archive?'&status=archived':''; ?>">Montant <i class="fas fa-sort"></i></a></th>
                        <th><a href="/factures.php?sort_by=status&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&filter_status=<?php echo urlencode($filter_status); ?><?php echo $view_archive?'&status=archived':''; ?>">Statut <i class="fas fa-sort"></i></a></th>
                        <th>Date</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($factures as $f): ?>
                        <tr>
                            <td><?php echo $f['id']; ?></td>
                            <td><code><?php echo htmlspecialchars($f['numero_facture']); ?></code></td>
                            <td><?php echo htmlspecialchars(($f['patient_nom']??'').' '.($f['patient_prenom']??'')); ?></td>
                            <td><strong><?php echo number_format($f['montant'],0,',',' '); ?> BIF</strong></td>
                            <td><span class="badge badge-<?php echo $f['status']==='paid'?'success':($f['status']==='pending'?'warning':'danger'); ?>"><?php echo ucfirst($f['status']); ?></span></td>
                            <td><?php echo formatDate($f['created_at'],'d/m/Y'); ?></td>
                            <td>
                                <div class="btn-group">
                                    <?php if (!$view_archive): ?>
                                        <a href="/factures.php?action=edit&id=<?php echo $f['id']; ?>" class="btn btn-warning" style="padding:0.4rem 0.8rem;font-size:0.85rem;"><i class="fas fa-edit"></i></a>
                                        <a href="/factures.php?archive_id=<?php echo $f['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding:0.4rem 0.8rem;font-size:0.85rem;" onclick="return confirm('Archiver ?')"><i class="fas fa-archive"></i></a>
                                    <?php else: ?>
                                        <a href="/factures.php?restore_id=<?php echo $f['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-success" style="padding:0.4rem 0.8rem;font-size:0.85rem;"><i class="fas fa-trash-restore"></i></a>
                                        <a href="/factures.php?delete_id=<?php echo $f['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding:0.4rem 0.8rem;font-size:0.85rem;" onclick="return confirm('Supprimer ?')"><i class="fas fa-trash-alt"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($factures)): ?><p style="text-align:center;margin-top:2rem;color:var(--text-light);">Aucune facture trouvée.</p><?php endif; ?>
        </div>

        <?php elseif (in_array($action, ['new','edit'])): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas <?php echo $action==='new'?'fa-plus-circle':'fa-edit'; ?>"></i> <?php echo $action==='new'?'Nouvelle facture':'Modifier la facture'; ?></h2>
            <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="patient_id">Patient *</label>
                    <select id="patient_id" name="patient_id" required>
                        <option value="">Sélectionner</option>
                        <?php foreach ($patients_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (isset($currentFacture)&&$currentFacture['patient_id']==$p['id'])?'selected':''; ?>><?php echo htmlspecialchars($p['nom'].' '.$p['prenom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="consultation_id">Consultation (optionnel)</label>
                    <select id="consultation_id" name="consultation_id">
                        <option value="">Aucune</option>
                        <?php foreach ($consultations_list as $cl): ?>
                            <option value="<?php echo $cl['id']; ?>" <?php echo (isset($currentFacture)&&($currentFacture['consultation_id']??0)==$cl['id'])?'selected':''; ?>><?php echo htmlspecialchars($cl['nom'].' '.$cl['prenom'].' - '.formatDate($cl['date_consultation'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                    <div class="form-group">
                        <label for="montant">Montant (BIF) *</label>
                        <input type="number" id="montant" name="montant" step="0.01" min="0" value="<?php echo htmlspecialchars($currentFacture['montant'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="status">Statut *</label>
                        <select id="status" name="status" required>
                            <option value="pending" <?php echo (isset($currentFacture)&&$currentFacture['status']==='pending')?'selected':''; ?>>En attente</option>
                            <option value="paid" <?php echo (isset($currentFacture)&&$currentFacture['status']==='paid')?'selected':''; ?>>Payée</option>
                            <option value="cancelled" <?php echo (isset($currentFacture)&&$currentFacture['status']==='cancelled')?'selected':''; ?>>Annulée</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($currentFacture['description'] ?? ''); ?></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Enregistrer</button>
                    <a href="/factures.php" class="btn btn-warning"><i class="fas fa-times"></i> Annuler</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
