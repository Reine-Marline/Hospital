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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Votre session a expiré. Rechargez la page puis réessayez.';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        if ($formAction === 'add_ward') {
            $wardName = trim($_POST['ward_name'] ?? '');
            $description = trim($_POST['ward_description'] ?? '');
            if ($wardName === '' || strlen($wardName) > 100) {
                $error = 'Le nom du service est requis (100 caractères maximum).';
            } else {
                $insert = $conn->prepare('INSERT INTO wards (name, description) VALUES (?, ?)');
                $insert->bind_param('ss', $wardName, $description);
                if ($insert->execute()) { $success = 'Le service a été ajouté.'; }
                else { $error = 'Ce nom de service existe déjà.'; }
                $insert->close();
            }
        } elseif ($formAction === 'add_bed') {
            $wardId = (int)($_POST['ward_id'] ?? 0);
            $bedNumber = trim($_POST['bed_number'] ?? '');
            if ($wardId < 1 || strlen($bedNumber) > 30) {
                $error = 'Sélectionnez un service et indiquez un numéro de lit valide.';
            } else {
                $insert = $conn->prepare('INSERT INTO beds (ward_id, bed_number, status) SELECT id, ?, \'available\' FROM wards WHERE id = ? AND is_active = 1');
                $insert->bind_param('si', $bedNumber, $wardId);
                if ($insert->execute() && $insert->affected_rows === 1) { $success = 'Le lit a été ajouté au service.'; }
                else { $error = 'Le lit n’a pas été ajouté. Vérifiez le service et le numéro.'; }
                $insert->close();
            }
        } elseif ($formAction === 'admit') {
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $bedId = (int)($_POST['bed_id'] ?? 0);
            $doctorId = (int)($_POST['medecin_id'] ?? 0);
            $reason = trim($_POST['admission_reason'] ?? '');
            if ($patientId < 1 || $bedId < 1 || $reason === '') {
                $error = 'Le patient, le lit et le motif d’admission sont obligatoires.';
            } else {
                $conn->begin_transaction();
                try {
                    $bedQuery = $conn->prepare('SELECT status FROM beds WHERE id = ? FOR UPDATE');
                    $bedQuery->bind_param('i', $bedId);
                    if (!$bedQuery->execute()) { throw new RuntimeException('Lecture du lit impossible'); }
                    $bed = $bedQuery->get_result()->fetch_assoc();
                    $bedQuery->close();
                    $patientQuery = $conn->prepare("SELECT id FROM admissions WHERE patient_id = ? AND status = 'admitted' LIMIT 1 FOR UPDATE");
                    $patientQuery->bind_param('i', $patientId);
                    if (!$patientQuery->execute()) { throw new RuntimeException('Vérification du séjour impossible'); }
                    $alreadyAdmitted = $patientQuery->get_result()->num_rows > 0;
                    $patientQuery->close();

                    if (!$bed || $bed['status'] !== 'available') {
                        $conn->rollback();
                        $error = 'Ce lit n’est pas disponible. Actualisez la liste des lits.';
                    } elseif ($alreadyAdmitted) {
                        $conn->rollback();
                        $error = 'Ce patient a déjà un séjour en cours.';
                    } else {
                        $doctorId = $doctorId > 0 ? $doctorId : null;
                        $insert = $conn->prepare("INSERT INTO admissions (patient_id, bed_id, attending_medecin_id, admitted_at, admission_reason, status, created_by) VALUES (?, ?, ?, NOW(), ?, 'admitted', ?)");
                        $insert->bind_param('iiisi', $patientId, $bedId, $doctorId, $reason, $userId);
                        if (!$insert->execute()) { throw new RuntimeException('Création de l’admission impossible'); }
                        $admissionId = $conn->insert_id;
                        $insert->close();
                        $updateBed = $conn->prepare("UPDATE beds SET status = 'occupied' WHERE id = ? AND status = 'available'");
                        $updateBed->bind_param('i', $bedId);
                        if (!$updateBed->execute()) { throw new RuntimeException('Mise à jour du lit impossible'); }
                        $bedUpdated = $updateBed->affected_rows === 1;
                        $updateBed->close();
                        if (!$bedUpdated) { throw new RuntimeException('Lit modifié pendant l’admission'); }
                        $conn->commit();
                        logActivity($userId, 'ADMIT_PATIENT', 'Admission #' . $admissionId . ' créée', $conn);
                        $success = 'Le patient a été admis.';
                    }
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'L’admission n’a pas pu être enregistrée. Vérifiez les informations et réessayez.';
                }
            }
        } elseif ($formAction === 'discharge') {
            $admissionId = (int)($_POST['admission_id'] ?? 0);
            $summary = trim($_POST['discharge_summary'] ?? '');
            $conn->begin_transaction();
            try {
                $admissionQuery = $conn->prepare("SELECT bed_id FROM admissions WHERE id = ? AND status = 'admitted' FOR UPDATE");
                $admissionQuery->bind_param('i', $admissionId);
                if (!$admissionQuery->execute()) { throw new RuntimeException('Lecture du séjour impossible'); }
                $admission = $admissionQuery->get_result()->fetch_assoc();
                $admissionQuery->close();
                if (!$admission) {
                    $conn->rollback();
                    $error = 'Ce séjour est déjà clôturé ou introuvable.';
                } else {
                    $updateAdmission = $conn->prepare("UPDATE admissions SET status = 'discharged', discharged_at = NOW(), discharge_summary = ? WHERE id = ? AND status = 'admitted'");
                    $updateAdmission->bind_param('si', $summary, $admissionId);
                    if (!$updateAdmission->execute() || $updateAdmission->affected_rows !== 1) { throw new RuntimeException('Clôture du séjour impossible'); }
                    $updateAdmission->close();
                    $bedId = (int)$admission['bed_id'];
                    $updateBed = $conn->prepare("UPDATE beds SET status = 'available' WHERE id = ? AND status = 'occupied'");
                    $updateBed->bind_param('i', $bedId);
                    if (!$updateBed->execute() || $updateBed->affected_rows !== 1) { throw new RuntimeException('Libération du lit impossible'); }
                    $updateBed->close();
                    $conn->commit();
                    logActivity($userId, 'DISCHARGE_PATIENT', 'Séjour #' . $admissionId . ' clôturé', $conn);
                    $success = 'La sortie du patient a été enregistrée et le lit est de nouveau disponible.';
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'La sortie n’a pas pu être enregistrée. Réessayez.';
            }
        } elseif ($formAction === 'set_bed_status') {
            $bedId = (int)($_POST['bed_id'] ?? 0);
            $nextStatus = $_POST['bed_status'] ?? '';
            if (!in_array($nextStatus, ['available', 'maintenance'], true)) {
                $error = 'Statut de lit non autorisé.';
            } else {
                $update = $conn->prepare("UPDATE beds SET status = ? WHERE id = ? AND status <> 'occupied' AND NOT EXISTS (SELECT 1 FROM admissions WHERE admissions.bed_id = beds.id AND admissions.status = 'admitted')");
                $update->bind_param('si', $nextStatus, $bedId);
                if ($update->execute() && $update->affected_rows === 1) { $success = 'Le statut du lit a été mis à jour.'; }
                else { $error = 'Un lit occupé ne peut pas être rendu indisponible.'; }
                $update->close();
            }
        }
    }
}

$patients = [];
$doctors = [];
$wards = [];
$result = $conn->query('SELECT id, nom, prenom FROM patients WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $patients = $result->fetch_all(MYSQLI_ASSOC); }
$result = $conn->query('SELECT id, nom, prenom, specialite FROM medecins WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $doctors = $result->fetch_all(MYSQLI_ASSOC); }
$result = $conn->query('SELECT id, name FROM wards WHERE is_active = 1 ORDER BY name');
if ($result) { $wards = $result->fetch_all(MYSQLI_ASSOC); }

$availableBeds = [];
$result = $conn->query("SELECT b.id, b.bed_number, w.name AS ward_name FROM beds b JOIN wards w ON w.id = b.ward_id WHERE b.status = 'available' AND w.is_active = 1 ORDER BY w.name, b.bed_number");
if ($result) { $availableBeds = $result->fetch_all(MYSQLI_ASSOC); }
$activeAdmissions = [];
$result = $conn->query("SELECT a.*, p.nom AS patient_nom, p.prenom AS patient_prenom, b.bed_number, w.name AS ward_name, m.nom AS doctor_name, m.prenom AS doctor_first_name FROM admissions a JOIN patients p ON p.id = a.patient_id JOIN beds b ON b.id = a.bed_id JOIN wards w ON w.id = b.ward_id LEFT JOIN medecins m ON m.id = a.attending_medecin_id WHERE a.status = 'admitted' ORDER BY a.admitted_at ASC");
if ($result) { $activeAdmissions = $result->fetch_all(MYSQLI_ASSOC); }
$beds = [];
$result = $conn->query('SELECT b.*, w.name AS ward_name FROM beds b JOIN wards w ON w.id = b.ward_id ORDER BY w.name, b.bed_number');
if ($result) { $beds = $result->fetch_all(MYSQLI_ASSOC); }
$occupiedCount = 0; $availableCount = 0; $maintenanceCount = 0;
foreach ($beds as $bed) {
    if ($bed['status'] === 'occupied') { $occupiedCount++; }
    elseif ($bed['status'] === 'maintenance') { $maintenanceCount++; }
    else { $availableCount++; }
}

$pageTitle = 'Hospitalisations et lits';
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">Services hospitaliers</p><h1>Hospitalisations</h1><p>Gérez les admissions, les sorties et la disponibilité des lits par service.</p></div>
    <a class="btn btn-primary" href="#nouvelle-admission"><i class="fas fa-bed-pulse"></i> Admettre un patient</a>
</section>
<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="summary-grid">
    <article class="summary-card"><span class="summary-icon tone-blue"><i class="fas fa-bed"></i></span><div><strong><?php echo count($activeAdmissions); ?></strong><span>Patients hospitalisés</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-green"><i class="fas fa-door-open"></i></span><div><strong><?php echo $availableCount; ?></strong><span>Lits disponibles</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-amber"><i class="fas fa-bed-pulse"></i></span><div><strong><?php echo $occupiedCount; ?></strong><span>Lits occupés</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-red"><i class="fas fa-screwdriver-wrench"></i></span><div><strong><?php echo $maintenanceCount; ?></strong><span>En maintenance</span></div></article>
</div>

<div class="clinic-layout">
    <section class="card" id="nouvelle-admission">
        <div class="section-heading"><div><p class="eyebrow">Nouvelle entrée</p><h2>Admettre un patient</h2></div><span class="section-icon"><i class="fas fa-hospital-user"></i></span></div>
        <?php if (empty($patients) || empty($availableBeds)): ?><p class="empty-state">Ajoutez un patient et créez un lit disponible avant d’enregistrer une admission.</p><?php else: ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="admit">
            <div class="form-group"><label for="patient_id">Patient</label><select id="patient_id" name="patient_id" required><option value="">Sélectionner un patient</option><?php foreach ($patients as $patient): ?><option value="<?php echo (int)$patient['id']; ?>"><?php echo htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="bed_id">Lit disponible</label><select id="bed_id" name="bed_id" required><option value="">Sélectionner un lit</option><?php foreach ($availableBeds as $bed): ?><option value="<?php echo (int)$bed['id']; ?>"><?php echo htmlspecialchars($bed['ward_name'] . ' · Lit ' . $bed['bed_number']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="medecin_id">Médecin référent <span class="muted-text">(facultatif)</span></label><select id="medecin_id" name="medecin_id"><option value="0">Non renseigné</option><?php foreach ($doctors as $doctor): ?><option value="<?php echo (int)$doctor['id']; ?>">Dr <?php echo htmlspecialchars($doctor['prenom'] . ' ' . $doctor['nom']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="admission_reason">Motif d’admission</label><textarea name="admission_reason" id="admission_reason" rows="3" required></textarea></div>
            <button class="btn btn-primary btn-block" type="submit">Enregistrer l’admission</button>
        </form>
        <?php endif; ?>
    </section>

    <section class="card clinic-main-panel">
        <div class="section-heading"><div><p class="eyebrow">Occupation en cours</p><h2>Patients hospitalisés</h2></div><span class="count-chip"><?php echo count($activeAdmissions); ?> séjour(s)</span></div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Patient</th><th>Service / lit</th><th>Admission</th><th>Médecin</th><th>Motif</th><th>Sortie</th></tr></thead><tbody>
        <?php foreach ($activeAdmissions as $admission): ?><tr>
            <td><strong><?php echo htmlspecialchars($admission['patient_prenom'] . ' ' . $admission['patient_nom']); ?></strong></td>
            <td><?php echo htmlspecialchars($admission['ward_name']); ?><small class="table-subtitle">Lit <?php echo htmlspecialchars($admission['bed_number']); ?></small></td>
            <td><?php echo formatDate($admission['admitted_at'], 'd/m/Y H:i'); ?></td>
            <td><?php echo $admission['doctor_name'] ? 'Dr ' . htmlspecialchars($admission['doctor_first_name'] . ' ' . $admission['doctor_name']) : '<span class="muted-text">—</span>'; ?></td>
            <td><?php echo htmlspecialchars($admission['admission_reason']); ?></td>
            <td><details class="row-details"><summary class="btn btn-secondary btn-small">Clôturer</summary><form method="post" class="details-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="discharge"><input type="hidden" name="admission_id" value="<?php echo (int)$admission['id']; ?>"><label for="discharge-<?php echo (int)$admission['id']; ?>">Résumé de sortie</label><textarea id="discharge-<?php echo (int)$admission['id']; ?>" name="discharge_summary" rows="2"></textarea><button type="submit" class="btn btn-primary btn-small">Confirmer la sortie</button></form></details></td>
        </tr><?php endforeach; ?>
        <?php if (empty($activeAdmissions)): ?><tr><td colspan="6" class="empty-table">Aucun patient hospitalisé actuellement.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>

<section class="card">
    <div class="section-heading"><div><p class="eyebrow">Gestion des capacités</p><h2>Services et lits</h2></div></div>
    <div class="admin-forms-grid">
        <form method="post" class="compact-form"><h3>Ajouter un service</h3><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="add_ward"><div class="form-group"><label for="ward_name">Nom du service</label><input id="ward_name" name="ward_name" maxlength="100" required placeholder="Ex. Médecine interne"></div><div class="form-group"><label for="ward_description">Note</label><input id="ward_description" name="ward_description" maxlength="255"></div><button class="btn btn-secondary" type="submit">Créer le service</button></form>
        <form method="post" class="compact-form"><h3>Ajouter un lit</h3><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="add_bed"><div class="form-group"><label for="bed_ward_id">Service</label><select id="bed_ward_id" name="ward_id" required><option value="">Sélectionner</option><?php foreach ($wards as $ward): ?><option value="<?php echo (int)$ward['id']; ?>"><?php echo htmlspecialchars($ward['name']); ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="bed_number">Numéro du lit</label><input id="bed_number" name="bed_number" maxlength="30" required placeholder="Ex. A-12"></div><button class="btn btn-secondary" type="submit" <?php echo empty($wards) ? 'disabled' : ''; ?>>Ajouter le lit</button></form>
    </div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Service</th><th>Lit</th><th>Statut</th><th>Disponibilité</th></tr></thead><tbody>
    <?php foreach ($beds as $bed): ?><tr><td><?php echo htmlspecialchars($bed['ward_name']); ?></td><td><?php echo htmlspecialchars($bed['bed_number']); ?></td><td><span class="badge badge-<?php echo $bed['status'] === 'available' ? 'success' : ($bed['status'] === 'occupied' ? 'warning' : 'danger'); ?>"><?php echo $bed['status'] === 'available' ? 'Disponible' : ($bed['status'] === 'occupied' ? 'Occupé' : 'Maintenance'); ?></span></td><td><?php if ($bed['status'] === 'occupied'): ?><span class="muted-text">Séjour en cours</span><?php else: ?><form method="post" class="inline-action"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="set_bed_status"><input type="hidden" name="bed_id" value="<?php echo (int)$bed['id']; ?>"><select name="bed_status" aria-label="Disponibilité du lit <?php echo htmlspecialchars($bed['bed_number']); ?>"><option value="available" <?php echo $bed['status'] === 'available' ? 'selected' : ''; ?>>Disponible</option><option value="maintenance" <?php echo $bed['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option></select><button class="btn btn-secondary btn-small" type="submit">Valider</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (empty($beds)): ?><tr><td colspan="4" class="empty-table">Aucun lit n’a encore été enregistré.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
