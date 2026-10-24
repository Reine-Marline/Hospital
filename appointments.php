<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();
if (!checkSessionExpiration()) { redirect('/login.php'); }

$userId = (int)getUserId();
$csrfToken = generateCsrfToken();
$error = '';
$success = '';
$statusLabels = [
    'scheduled' => 'Planifié',
    'confirmed' => 'Confirmé',
    'arrived' => 'Arrivé',
    'completed' => 'Terminé',
    'cancelled' => 'Annulé',
    'no_show' => 'Absent',
];
$statusClasses = [
    'scheduled' => 'info', 'confirmed' => 'success', 'arrived' => 'warning',
    'completed' => 'success', 'cancelled' => 'danger', 'no_show' => 'danger',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Votre session a expiré. Rechargez la page puis réessayez.';
    } elseif (($_POST['form_action'] ?? '') === 'create') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $doctorId = (int)($_POST['medecin_id'] ?? 0);
        $startsAtInput = trim($_POST['starts_at'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 30);
        $reason = trim($_POST['reason'] ?? '');
        $startsAt = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtInput);
        $now = new DateTime('now');

        if ($patientId < 1 || $doctorId < 1 || !$startsAt || $startsAt < $now || $duration < 10 || $duration > 240 || $reason === '' || strlen($reason) > 500) {
            $error = 'Vérifiez le patient, le médecin, la date, la durée et le motif du rendez-vous.';
        } else {
            $startSql = $startsAt->format('Y-m-d H:i:s');
            $endSql = (clone $startsAt)->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s');
            $conn->begin_transaction();
            try {
                $conflict = $conn->prepare("SELECT id FROM appointments WHERE medecin_id = ? AND status IN ('scheduled','confirmed','arrived') AND starts_at < ? AND DATE_ADD(starts_at, INTERVAL duration_minutes MINUTE) > ? LIMIT 1 FOR UPDATE");
                $conflict->bind_param('iss', $doctorId, $endSql, $startSql);
                if (!$conflict->execute()) { throw new RuntimeException('Vérification de disponibilité impossible'); }
                $hasConflict = $conflict->get_result()->num_rows > 0;
                $conflict->close();

                if ($hasConflict) {
                    $conn->rollback();
                    $error = 'Ce médecin a déjà un rendez-vous sur ce créneau.';
                } else {
                    $insert = $conn->prepare("INSERT INTO appointments (patient_id, medecin_id, starts_at, duration_minutes, reason, status, created_by) VALUES (?, ?, ?, ?, ?, 'scheduled', ?)");
                    $insert->bind_param('iisisi', $patientId, $doctorId, $startSql, $duration, $reason, $userId);
                    if (!$insert->execute()) { throw new RuntimeException('Création du rendez-vous impossible'); }
                    $appointmentId = $conn->insert_id;
                    $insert->close();
                    $conn->commit();
                    logActivity($userId, 'CREATE_APPOINTMENT', 'Rendez-vous #' . $appointmentId . ' planifié', $conn);
                    $success = 'Le rendez-vous a été planifié.';
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'Impossible de planifier ce rendez-vous. Vérifiez les informations puis réessayez.';
            }
        }
    } elseif (($_POST['form_action'] ?? '') === 'change_status') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $nextStatus = $_POST['status'] ?? '';
        $transitions = [
            'scheduled' => ['confirmed', 'cancelled', 'no_show'],
            'confirmed' => ['arrived', 'cancelled', 'no_show', 'completed'],
            'arrived' => ['completed'],
        ];
        $currentQuery = $conn->prepare('SELECT status FROM appointments WHERE id = ? LIMIT 1');
        $currentQuery->bind_param('i', $appointmentId);
        $currentQuery->execute();
        $currentStatus = $currentQuery->get_result()->fetch_assoc()['status'] ?? '';
        $currentQuery->close();
        if (!isset($transitions[$currentStatus]) || !in_array($nextStatus, $transitions[$currentStatus], true)) {
            $error = 'Cette mise à jour du rendez-vous n’est pas autorisée.';
        } else {
            $update = $conn->prepare('UPDATE appointments SET status = ? WHERE id = ? AND status = ?');
            $update->bind_param('sis', $nextStatus, $appointmentId, $currentStatus);
            if ($update->execute() && $update->affected_rows === 1) {
                $success = 'Le statut du rendez-vous a été mis à jour.';
                logActivity($userId, 'UPDATE_APPOINTMENT', 'Rendez-vous #' . $appointmentId . ' : ' . $nextStatus, $conn);
            } else {
                $error = 'Le rendez-vous a été modifié entre-temps. Rechargez la page.';
            }
            $update->close();
        }
    }
}

$patients = [];
$doctors = [];
$result = $conn->query('SELECT id, nom, prenom FROM patients WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $patients = $result->fetch_all(MYSQLI_ASSOC); }
$result = $conn->query('SELECT id, nom, prenom, specialite FROM medecins WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $doctors = $result->fetch_all(MYSQLI_ASSOC); }

$filterDate = trim($_GET['date'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$hasDateFilter = $filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate);
$hasStatusFilter = isset($statusLabels[$filterStatus]);
$sql = 'SELECT a.*, p.nom AS patient_nom, p.prenom AS patient_prenom, m.nom AS medecin_nom, m.prenom AS medecin_prenom, m.specialite FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN medecins m ON m.id = a.medecin_id WHERE 1=1';
if ($hasDateFilter) { $sql .= ' AND DATE(a.starts_at) = ?'; }
if ($hasStatusFilter) { $sql .= ' AND a.status = ?'; }
$sql .= ' ORDER BY a.starts_at DESC LIMIT 200';
$stmt = $conn->prepare($sql);
if ($hasDateFilter && $hasStatusFilter) { $stmt->bind_param('ss', $filterDate, $filterStatus); }
elseif ($hasDateFilter) { $stmt->bind_param('s', $filterDate); }
elseif ($hasStatusFilter) { $stmt->bind_param('s', $filterStatus); }
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Rendez-vous';
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">Organisation des soins</p><h1>Rendez-vous</h1><p>Planifiez les consultations et suivez chaque étape de la prise en charge.</p></div>
    <a class="btn btn-primary" href="#nouveau-rendez-vous"><i class="fas fa-plus"></i> Planifier un rendez-vous</a>
</section>

<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="clinic-layout">
    <section class="card" id="nouveau-rendez-vous">
        <div class="section-heading"><div><p class="eyebrow">Nouvelle réservation</p><h2>Planifier</h2></div><span class="section-icon"><i class="fas fa-calendar-plus"></i></span></div>
        <?php if (empty($patients) || empty($doctors)): ?>
            <p class="empty-state">Ajoutez au moins un patient et un médecin avant de planifier un rendez-vous.</p>
        <?php else: ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="form_action" value="create">
            <div class="form-group"><label for="patient_id">Patient</label><select name="patient_id" id="patient_id" required><option value="">Sélectionner un patient</option><?php foreach ($patients as $patient): ?><option value="<?php echo (int)$patient['id']; ?>"><?php echo htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="medecin_id">Médecin</label><select name="medecin_id" id="medecin_id" required><option value="">Sélectionner un médecin</option><?php foreach ($doctors as $doctor): ?><option value="<?php echo (int)$doctor['id']; ?>">Dr <?php echo htmlspecialchars($doctor['prenom'] . ' ' . $doctor['nom'] . ' · ' . $doctor['specialite']); ?></option><?php endforeach; ?></select></div>
            <div class="form-grid">
                <div class="form-group"><label for="starts_at">Date et heure</label><input type="datetime-local" id="starts_at" name="starts_at" min="<?php echo date('Y-m-d\TH:i'); ?>" required></div>
                <div class="form-group"><label for="duration_minutes">Durée</label><select id="duration_minutes" name="duration_minutes"><option value="15">15 minutes</option><option value="30" selected>30 minutes</option><option value="45">45 minutes</option><option value="60">1 heure</option><option value="90">1 h 30</option></select></div>
            </div>
            <div class="form-group"><label for="reason">Motif</label><textarea id="reason" name="reason" rows="3" maxlength="500" required placeholder="Motif de la consultation"></textarea></div>
            <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-calendar-check"></i> Confirmer le rendez-vous</button>
        </form>
        <?php endif; ?>
    </section>

    <section class="card clinic-main-panel">
        <div class="section-heading"><div><p class="eyebrow">Agenda médical</p><h2>Suivi des rendez-vous</h2></div><span class="count-chip"><?php echo count($appointments); ?> affiché(s)</span></div>
        <form method="get" class="filter-bar">
            <div class="form-group"><label for="filter-date">Date</label><input type="date" name="date" id="filter-date" value="<?php echo htmlspecialchars($filterDate); ?>"></div>
            <div class="form-group"><label for="filter-status">Statut</label><select name="status" id="filter-status"><option value="">Tous les statuts</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $filterStatus === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-secondary" type="submit"><i class="fas fa-filter"></i> Filtrer</button>
            <a class="btn btn-ghost" href="/appointments.php">Réinitialiser</a>
        </form>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Patient</th><th>Médecin</th><th>Motif</th><th>Statut</th><th>Mettre à jour</th></tr></thead>
                <tbody>
                <?php foreach ($appointments as $appointment): ?>
                    <tr>
                        <td><strong><?php echo formatDate($appointment['starts_at'], 'd/m/Y'); ?></strong><small class="table-subtitle"><?php echo formatDate($appointment['starts_at'], 'H:i'); ?> · <?php echo (int)$appointment['duration_minutes']; ?> min</small></td>
                        <td><?php echo htmlspecialchars($appointment['patient_prenom'] . ' ' . $appointment['patient_nom']); ?></td>
                        <td>Dr <?php echo htmlspecialchars($appointment['medecin_prenom'] . ' ' . $appointment['medecin_nom']); ?><small class="table-subtitle"><?php echo htmlspecialchars($appointment['specialite']); ?></small></td>
                        <td><?php echo htmlspecialchars($appointment['reason']); ?></td>
                        <td><span class="badge badge-<?php echo $statusClasses[$appointment['status']] ?? 'info'; ?>"><?php echo htmlspecialchars($statusLabels[$appointment['status']] ?? $appointment['status']); ?></span></td>
                        <td><?php $availableTransitions = [
                            'scheduled' => ['confirmed', 'cancelled', 'no_show'],
                            'confirmed' => ['arrived', 'completed', 'cancelled', 'no_show'],
                            'arrived' => ['completed'],
                        ][$appointment['status']] ?? []; ?>
                            <?php if ($availableTransitions): ?><form method="post" class="inline-action"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="change_status"><input type="hidden" name="appointment_id" value="<?php echo (int)$appointment['id']; ?>"><select name="status" aria-label="Nouveau statut pour le rendez-vous #<?php echo (int)$appointment['id']; ?>"><?php foreach ($availableTransitions as $next): ?><option value="<?php echo $next; ?>"><?php echo htmlspecialchars($statusLabels[$next]); ?></option><?php endforeach; ?></select><button class="btn btn-secondary btn-small" type="submit">Valider</button></form><?php else: ?><span class="muted-text">Terminé</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($appointments)): ?><tr><td colspan="6" class="empty-table">Aucun rendez-vous ne correspond à ces filtres.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
