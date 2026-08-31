<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();
if (!checkSessionExpiration()) { redirect('/login.php'); }

$user_id = getUserId();
$type = $_GET['type'] ?? 'dashboard';
$format = $_GET['format'] ?? 'html';
$date_from = sanitize($_GET['date_from'] ?? '');
$date_to = sanitize($_GET['date_to'] ?? '');

$reportData = [];
$reportTitle = 'Rapport';

// --- Rapport Patients ---
if ($type === 'patients') {
    $reportTitle = 'Rapport des Patients';
    $sql = "SELECT id, nom, prenom, cin, date_naissance, sexe, groupe_sanguin, phone, email, created_at FROM patients WHERE is_archived = 0";
    if (!empty($date_from)) { $sql .= " AND created_at >= '" . $conn->real_escape_string($date_from) . "'"; }
    if (!empty($date_to)) { $sql .= " AND created_at <= '" . $conn->real_escape_string($date_to) . " 23:59:59'"; }
    $sql .= " ORDER BY created_at DESC";
    $result = $conn->query($sql);
    if ($result) { $reportData = $result->fetch_all(MYSQLI_ASSOC); }
}
// --- Rapport Consultations ---
elseif ($type === 'consultations') {
    $reportTitle = 'Rapport des Consultations';
    $sql = "SELECT c.id, p.nom as patient_nom, p.prenom as patient_prenom, m.nom as medecin_nom, m.prenom as medecin_prenom, m.specialite, c.date_consultation, c.motif, c.diagnostic, c.traitement FROM consultations c LEFT JOIN patients p ON c.patient_id=p.id LEFT JOIN medecins m ON c.medecin_id=m.id WHERE c.is_archived=0";
    if (!empty($date_from)) { $sql .= " AND c.date_consultation >= '" . $conn->real_escape_string($date_from) . "'"; }
    if (!empty($date_to)) { $sql .= " AND c.date_consultation <= '" . $conn->real_escape_string($date_to) . " 23:59:59'"; }
    $sql .= " ORDER BY c.date_consultation DESC";
    $result = $conn->query($sql);
    if ($result) { $reportData = $result->fetch_all(MYSQLI_ASSOC); }
}
// --- Rapport Factures ---
elseif ($type === 'factures') {
    $reportTitle = 'Rapport des Factures';
    $sql = "SELECT f.id, f.numero_facture, p.nom as patient_nom, p.prenom as patient_prenom, f.montant, f.status, f.created_at FROM factures f LEFT JOIN patients p ON f.patient_id=p.id WHERE f.is_archived=0";
    if (!empty($date_from)) { $sql .= " AND f.created_at >= '" . $conn->real_escape_string($date_from) . "'"; }
    if (!empty($date_to)) { $sql .= " AND f.created_at <= '" . $conn->real_escape_string($date_to) . " 23:59:59'"; }
    $sql .= " ORDER BY f.created_at DESC";
    $result = $conn->query($sql);
    if ($result) { $reportData = $result->fetch_all(MYSQLI_ASSOC); }
}
// --- Tableau de Bord Général ---
elseif ($type === 'dashboard') {
    $reportTitle = 'Rapport Général';
    $stats = [];
    $r = $conn->query("SELECT COUNT(*) as c FROM patients WHERE is_archived=0"); $stats['total_patients'] = $r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) as c FROM medecins WHERE is_archived=0"); $stats['total_medecins'] = $r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) as c FROM consultations WHERE is_archived=0"); $stats['total_consultations'] = $r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COALESCE(SUM(montant),0) as t FROM factures WHERE status='paid' AND is_archived=0"); $stats['revenu_total'] = $r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COALESCE(SUM(montant),0) as t FROM factures WHERE status='pending' AND is_archived=0"); $stats['factures_en_attente'] = $r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) as c FROM documents WHERE is_archived=0"); $stats['total_documents'] = $r->fetch_assoc()['c'];
    $reportData = $stats;
}
// --- Analyse Comparative (consultations par mois) ---
elseif ($type === 'comparative') {
    $reportTitle = 'Analyse Comparative — Consultations par Mois';
    $sql = "SELECT DATE_FORMAT(date_consultation, '%Y-%m') as mois, COUNT(*) as total, COUNT(DISTINCT patient_id) as patients_uniques, COUNT(DISTINCT medecin_id) as medecins_actifs FROM consultations WHERE is_archived=0 GROUP BY DATE_FORMAT(date_consultation, '%Y-%m') ORDER BY mois DESC LIMIT 12";
    $result = $conn->query($sql);
    if ($result) { $reportData = $result->fetch_all(MYSQLI_ASSOC); }
}
// --- Prévisions Statistiques ---
elseif ($type === 'previsions') {
    $reportTitle = 'Prévisions Statistiques';
    // Tendance des patients inscrits par mois (12 derniers mois)
    $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') as mois, COUNT(*) as nouveaux_patients FROM patients WHERE is_archived=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY mois ASC";
    $result = $conn->query($sql);
    $patientsTrend = [];
    if ($result) { $patientsTrend = $result->fetch_all(MYSQLI_ASSOC); }
    
    // Tendance revenus par mois
    $sql2 = "SELECT DATE_FORMAT(created_at, '%Y-%m') as mois, COALESCE(SUM(montant),0) as revenu FROM factures WHERE status='paid' AND is_archived=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY mois ASC";
    $result2 = $conn->query($sql2);
    $revenuTrend = [];
    if ($result2) { $revenuTrend = $result2->fetch_all(MYSQLI_ASSOC); }
    
    // Moyenne mensuelle pour prévision
    $avgPatients = 0;
    $avgRevenu = 0;
    if (count($patientsTrend) > 0) {
        $total = 0;
        foreach ($patientsTrend as $pt) { $total += $pt['nouveaux_patients']; }
        $avgPatients = round($total / count($patientsTrend));
    }
    if (count($revenuTrend) > 0) {
        $total = 0;
        foreach ($revenuTrend as $rt) { $total += $rt['revenu']; }
        $avgRevenu = round($total / count($revenuTrend), 2);
    }
    
    $reportData = [
        'patients_trend' => $patientsTrend,
        'revenu_trend' => $revenuTrend,
        'prevision_patients' => $avgPatients,
        'prevision_revenu' => $avgRevenu
    ];
}

// --- Export CSV ---
if ($format === 'csv' && $type !== 'dashboard' && $type !== 'previsions') {
    $filename = str_replace(' ', '_', $reportTitle) . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    if (!empty($reportData)) {
        fputcsv($output, array_keys($reportData[0]));
        foreach ($reportData as $row) { fputcsv($output, array_values($row)); }
    }
    fclose($output);
    exit;
}

// --- Export PDF (HTML pour impression navigateur) ---
if ($format === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($reportTitle) . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;margin:2cm;} table{width:100%;border-collapse:collapse;margin-top:1rem;} th,td{border:1px solid #ccc;padding:8px;text-align:left;font-size:0.9rem;} th{background:#f0f0f0;font-weight:bold;} h1{color:#2c3e50;} .meta{color:#666;margin-bottom:1rem;}</style>';
    echo '</head><body>';
    echo '<h1>' . htmlspecialchars($reportTitle) . '</h1>';
    echo '<p class="meta">Généré le ' . date('d/m/Y H:i:s') . '</p>';
    
    if ($type === 'dashboard' && is_array($reportData)) {
        echo '<table>';
        foreach ($reportData as $key => $val) {
            echo '<tr><td><strong>' . ucfirst(str_replace('_', ' ', $key)) . '</strong></td><td>' . htmlspecialchars($val) . '</td></tr>';
        }
        echo '</table>';
    } elseif (!empty($reportData) && isset($reportData[0])) {
        echo '<table><thead><tr>';
        foreach (array_keys($reportData[0]) as $h) { echo '<th>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $h))) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($reportData as $row) {
            echo '<tr>';
            foreach ($row as $val) { echo '<td>' . htmlspecialchars($val) . '</td>'; }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</body></html>';
    exit;
}

$pageTitle = $reportTitle;
require_once __DIR__ . '/includes/header.php';
?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/reports.php?type=dashboard" <?php echo $type==='dashboard'?'class="active"':''; ?>><i class="fas fa-tachometer-alt"></i> Rapport Général</a></li>
            <li><a href="/reports.php?type=patients" <?php echo $type==='patients'?'class="active"':''; ?>><i class="fas fa-users"></i> Patients</a></li>
            <li><a href="/reports.php?type=consultations" <?php echo $type==='consultations'?'class="active"':''; ?>><i class="fas fa-clipboard-list"></i> Consultations</a></li>
            <li><a href="/reports.php?type=factures" <?php echo $type==='factures'?'class="active"':''; ?>><i class="fas fa-file-invoice-dollar"></i> Factures</a></li>
            <li><a href="/reports.php?type=comparative" <?php echo $type==='comparative'?'class="active"':''; ?>><i class="fas fa-chart-bar"></i> Analyse Comparative</a></li>
            <li><a href="/reports.php?type=previsions" <?php echo $type==='previsions'?'class="active"':''; ?>><i class="fas fa-chart-line"></i> Prévisions</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
                <h2 class="card-title" style="margin-bottom:0;"><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars($reportTitle); ?></h2>
                <div class="btn-group">
                    <?php if (!in_array($type, ['dashboard', 'previsions'])): ?>
                        <a href="/reports.php?type=<?php echo urlencode($type); ?>&format=csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel/CSV</a>
                    <?php endif; ?>
                    <a href="/reports.php?type=<?php echo urlencode($type); ?>&format=pdf&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="btn btn-danger"><i class="fas fa-file-pdf"></i> PDF</a>
                </div>
            </div>
            <p style="color:var(--text-light);margin-bottom:1.5rem;">Généré le <?php echo date('d/m/Y H:i:s'); ?></p>

            <!-- Filtre par période (rapports personnalisés) -->
            <?php if (in_array($type, ['patients', 'consultations', 'factures'])): ?>
            <div style="margin-bottom:2rem;padding:1rem;background:var(--light);border:1px solid var(--border);border-radius:var(--radius-sm);">
                <form method="GET" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap;">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_from">Du</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_to">Au</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrer</button>
                    <a href="/reports.php?type=<?php echo htmlspecialchars($type); ?>" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
                </form>
            </div>
            <?php endif; ?>

            <!-- Contenu du rapport -->
            <?php if ($type === 'dashboard'): ?>
                <div class="dashboard-grid">
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-card-info"><h3><?php echo $reportData['total_patients']; ?></h3><p>Patients</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(16,185,129,0.1);color:var(--secondary);"><i class="fas fa-stethoscope"></i></div>
                        <div class="stat-card-info"><h3><?php echo $reportData['total_medecins']; ?></h3><p>Médecins</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(245,158,11,0.1);color:var(--warning);"><i class="fas fa-clipboard-list"></i></div>
                        <div class="stat-card-info"><h3><?php echo $reportData['total_consultations']; ?></h3><p>Consultations</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(16,185,129,0.1);color:#059669;"><i class="fas fa-euro-sign"></i></div>
                        <div class="stat-card-info"><h3><?php echo number_format($reportData['revenu_total'], 2); ?> €</h3><p>Revenu Total</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:rgba(239,68,68,0.1);color:var(--danger);"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-card-info"><h3><?php echo number_format($reportData['factures_en_attente'], 2); ?> €</h3><p>En attente</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-folder-open"></i></div>
                        <div class="stat-card-info"><h3><?php echo $reportData['total_documents']; ?></h3><p>Documents</p></div>
                    </div>
                </div>

            <?php elseif ($type === 'previsions'): ?>
                <h3 style="margin-bottom:1rem;"><i class="fas fa-users"></i> Tendance Patients (12 derniers mois)</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Mois</th><th>Nouveaux patients</th></tr></thead>
                        <tbody>
                            <?php foreach ($reportData['patients_trend'] as $pt): ?>
                            <tr><td><?php echo htmlspecialchars($pt['mois']); ?></td><td><?php echo $pt['nouveaux_patients']; ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($reportData['patients_trend'])): ?><tr><td colspan="2" style="text-align:center;">Pas de données.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info" style="margin-top:1rem;">
                    <strong>Prévision :</strong> En moyenne, <strong><?php echo $reportData['prevision_patients']; ?></strong> nouveaux patients par mois.
                </div>

                <h3 style="margin-top:2rem;margin-bottom:1rem;"><i class="fas fa-euro-sign"></i> Tendance Revenus (12 derniers mois)</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Mois</th><th>Revenu (€)</th></tr></thead>
                        <tbody>
                            <?php foreach ($reportData['revenu_trend'] as $rt): ?>
                            <tr><td><?php echo htmlspecialchars($rt['mois']); ?></td><td><?php echo number_format($rt['revenu'], 2); ?> €</td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($reportData['revenu_trend'])): ?><tr><td colspan="2" style="text-align:center;">Pas de données.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info" style="margin-top:1rem;">
                    <strong>Prévision :</strong> Revenu mensuel moyen estimé : <strong><?php echo number_format($reportData['prevision_revenu'], 2); ?> €</strong>.
                </div>

            <?php elseif ($type === 'comparative'): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Mois</th><th>Total consultations</th><th>Patients uniques</th><th>Médecins actifs</th></tr></thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['mois']); ?></td>
                                <td><strong><?php echo $row['total']; ?></strong></td>
                                <td><?php echo $row['patients_uniques']; ?></td>
                                <td><?php echo $row['medecins_actifs']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($reportData)): ?><tr><td colspan="4" style="text-align:center;">Aucune donnée.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif (!empty($reportData)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr>
                            <?php foreach (array_keys($reportData[0]) as $h): ?>
                                <th><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $h))); ?></th>
                            <?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <?php foreach ($row as $key => $val): ?>
                                    <td>
                                        <?php
                                        if (strpos($key, 'montant') !== false) echo number_format($val, 2) . ' €';
                                        elseif (strpos($key, 'date') !== false || strpos($key, 'created') !== false) echo formatDate($val);
                                        else echo htmlspecialchars($val);
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align:center;color:var(--text-light);margin-top:2rem;">Aucune donnée pour ce rapport.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
