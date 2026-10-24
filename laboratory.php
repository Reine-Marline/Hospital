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
$statusLabels = ['requested' => 'Demandé', 'collected' => 'Prélèvement reçu', 'in_progress' => 'En cours', 'completed' => 'Résultat disponible', 'cancelled' => 'Annulé'];
$statusClasses = ['requested' => 'info', 'collected' => 'warning', 'in_progress' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Votre session a expiré. Rechargez la page puis réessayez.';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        if ($formAction === 'add_test') {
            $name = trim($_POST['test_name'] ?? '');
            $category = trim($_POST['test_category'] ?? 'Général');
            $description = trim($_POST['test_description'] ?? '');
            $price = filter_var($_POST['test_price'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($name === '' || $category === '' || $price === false || $price < 0) {
                $error = 'Le nom, la catégorie et un prix valide sont obligatoires.';
            } else {
                $insert = $conn->prepare('INSERT INTO lab_tests (name, category, description, price) VALUES (?, ?, ?, ?)');
                $insert->bind_param('sssd', $name, $category, $description, $price);
                if ($insert->execute()) { $success = 'L’analyse a été ajoutée au catalogue.'; }
                else { $error = 'Cette analyse existe déjà dans le catalogue.'; }
                $insert->close();
            }
        } elseif ($formAction === 'order_test') {
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $testId = (int)($_POST['test_id'] ?? 0);
            $doctorId = (int)($_POST['medecin_id'] ?? 0);
            if ($patientId < 1 || $testId < 1) {
                $error = 'Sélectionnez le patient et l’analyse à prescrire.';
            } else {
                $orderedBy = $doctorId > 0 ? $doctorId : null;
                $insert = $conn->prepare("INSERT INTO lab_orders (patient_id, test_id, ordered_by, ordered_at, status) SELECT ?, id, ?, NOW(), 'requested' FROM lab_tests WHERE id = ? AND is_active = 1");
                $insert->bind_param('iii', $patientId, $orderedBy, $testId);
                if ($insert->execute() && $insert->affected_rows === 1) {
                    $success = 'La demande d’analyse a été transmise au laboratoire.';
                    logActivity($userId, 'ORDER_LAB_TEST', 'Patient #' . $patientId . ', analyse #' . $testId, $conn);
                } else { $error = 'La demande n’a pas été créée. Vérifiez les données puis réessayez.'; }
                $insert->close();
            }
        } elseif ($formAction === 'update_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $nextStatus = $_POST['status'] ?? '';
            $resultText = trim($_POST['result'] ?? '');
            $resultNotes = trim($_POST['result_notes'] ?? '');
            $transitions = [
                'requested' => ['collected', 'cancelled'],
                'collected' => ['in_progress', 'completed', 'cancelled'],
                'in_progress' => ['completed', 'cancelled'],
            ];
            $currentQuery = $conn->prepare('SELECT status FROM lab_orders WHERE id = ? LIMIT 1');
            $currentQuery->bind_param('i', $orderId);
            $currentQuery->execute();
            $currentStatus = $currentQuery->get_result()->fetch_assoc()['status'] ?? '';
            $currentQuery->close();
            if (!isset($transitions[$currentStatus]) || !in_array($nextStatus, $transitions[$currentStatus], true)) {
                $error = 'Cette mise à jour de l’analyse n’est pas autorisée.';
            } elseif ($nextStatus === 'completed' && $resultText === '') {
                $error = 'Saisissez le résultat avant de clôturer l’analyse.';
            } else {
                $conn->begin_transaction();
                try {
                    $nowField = $nextStatus === 'collected' ? ', collected_at = NOW()' : ($nextStatus === 'completed' ? ', completed_at = NOW()' : '');
                    $update = $conn->prepare("UPDATE lab_orders SET status = ?, result = ?, result_notes = ?" . $nowField . ' WHERE id = ? AND status = ?');
                    $update->bind_param('sssis', $nextStatus, $resultText, $resultNotes, $orderId, $currentStatus);
                    $update->execute();
                    if ($update->affected_rows !== 1) { throw new RuntimeException('Analyse modifiée en parallèle'); }
                    $update->close();
                    $conn->commit();
                    $success = 'Le suivi de l’analyse a été mis à jour.';
                    logActivity($userId, 'UPDATE_LAB_ORDER', 'Analyse #' . $orderId . ' : ' . $nextStatus, $conn);
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'L’analyse n’a pas pu être mise à jour. Rechargez la page et réessayez.';
                }
            }
        }
    }
}

$patients = [];
$doctors = [];
$tests = [];
$result = $conn->query('SELECT id, nom, prenom FROM patients WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $patients = $result->fetch_all(MYSQLI_ASSOC); }
$result = $conn->query('SELECT id, nom, prenom FROM medecins WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $doctors = $result->fetch_all(MYSQLI_ASSOC); }
$result = $conn->query('SELECT * FROM lab_tests WHERE is_active = 1 ORDER BY category, name');
if ($result) { $tests = $result->fetch_all(MYSQLI_ASSOC); }
$orders = [];
$result = $conn->query('SELECT lo.*, p.nom AS patient_nom, p.prenom AS patient_prenom, lt.name AS test_name, lt.category, m.nom AS medecin_nom, m.prenom AS medecin_prenom FROM lab_orders lo JOIN patients p ON p.id = lo.patient_id JOIN lab_tests lt ON lt.id = lo.test_id LEFT JOIN medecins m ON m.id = lo.ordered_by ORDER BY lo.ordered_at DESC LIMIT 150');
if ($result) { $orders = $result->fetch_all(MYSQLI_ASSOC); }
$labStats = ['pending_count' => 0, 'completed_count' => 0];
$result = $conn->query("SELECT COALESCE(SUM(status IN ('requested','collected','in_progress')), 0) AS pending_count, COALESCE(SUM(status = 'completed'), 0) AS completed_count FROM lab_orders");
if ($result) { $labStats = $result->fetch_assoc(); }

$pageTitle = 'Laboratoire';
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">Analyses et résultats</p><h1>Laboratoire</h1><p>Prescrivez les analyses et suivez le prélèvement jusqu’à la validation du résultat.</p></div>
    <a class="btn btn-primary" href="#nouvelle-demande"><i class="fas fa-flask"></i> Prescrire une analyse</a>
</section>
<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="summary-grid">
    <article class="summary-card"><span class="summary-icon tone-blue"><i class="fas fa-flask"></i></span><div><strong><?php echo count($tests); ?></strong><span>Analyses au catalogue</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-amber"><i class="fas fa-hourglass-half"></i></span><div><strong><?php echo (int)$labStats['pending_count']; ?></strong><span>Demandes en cours</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-green"><i class="fas fa-circle-check"></i></span><div><strong><?php echo (int)$labStats['completed_count']; ?></strong><span>Analyses clôturées</span></div></article>
</div>

<section class="card">
    <div class="section-heading"><div><p class="eyebrow">Demandes et résultats</p><h2>Suivi des analyses</h2></div></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Demandé le</th><th>Patient</th><th>Analyse</th><th>Médecin</th><th>Résultat</th><th>Statut</th><th>Suivi</th></tr></thead><tbody>
    <?php foreach ($orders as $order): ?><tr>
        <td><?php echo formatDate($order['ordered_at'], 'd/m/Y H:i'); ?></td>
        <td><strong><?php echo htmlspecialchars($order['patient_prenom'] . ' ' . $order['patient_nom']); ?></strong></td>
        <td><?php echo htmlspecialchars($order['test_name']); ?><small class="table-subtitle"><?php echo htmlspecialchars($order['category']); ?></small></td>
        <td><?php echo $order['medecin_nom'] ? 'Dr ' . htmlspecialchars($order['medecin_prenom'] . ' ' . $order['medecin_nom']) : '<span class="muted-text">—</span>'; ?></td>
        <td><?php echo $order['result'] ? '<details class="row-details"><summary class="link-button">Voir le résultat</summary><div class="result-panel"><strong>Résultat</strong><p>' . nl2br(htmlspecialchars($order['result'])) . '</p>' . ($order['result_notes'] ? '<small>' . nl2br(htmlspecialchars($order['result_notes'])) . '</small>' : '') . '</div></details>' : '<span class="muted-text">En attente</span>'; ?></td>
        <td><span class="badge badge-<?php echo $statusClasses[$order['status']] ?? 'info'; ?>"><?php echo htmlspecialchars($statusLabels[$order['status']] ?? $order['status']); ?></span></td>
        <td><?php $transitions = ['requested' => ['collected', 'cancelled'], 'collected' => ['in_progress', 'completed', 'cancelled'], 'in_progress' => ['completed', 'cancelled']][$order['status']] ?? []; ?>
            <?php if ($transitions): ?><details class="row-details"><summary class="btn btn-secondary btn-small">Mettre à jour</summary><form method="post" class="details-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="update_order"><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><div class="form-group"><label for="lab-status-<?php echo (int)$order['id']; ?>">Étape suivante</label><select name="status" id="lab-status-<?php echo (int)$order['id']; ?>"><?php foreach ($transitions as $transition): ?><option value="<?php echo $transition; ?>"><?php echo htmlspecialchars($statusLabels[$transition]); ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="lab-result-<?php echo (int)$order['id']; ?>">Résultat</label><textarea name="result" id="lab-result-<?php echo (int)$order['id']; ?>" rows="2" placeholder="Obligatoire pour valider le résultat"><?php echo htmlspecialchars($order['result'] ?? ''); ?></textarea></div><div class="form-group"><label for="lab-notes-<?php echo (int)$order['id']; ?>">Commentaire</label><textarea name="result_notes" id="lab-notes-<?php echo (int)$order['id']; ?>" rows="2"><?php echo htmlspecialchars($order['result_notes'] ?? ''); ?></textarea></div><button type="submit" class="btn btn-primary btn-small">Enregistrer</button></form></details><?php else: ?><span class="muted-text">Clôturé</span><?php endif; ?>
        </td>
    </tr><?php endforeach; ?>
    <?php if (empty($orders)): ?><tr><td colspan="7" class="empty-table">Aucune analyse n’a été prescrite.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<div class="clinic-layout">
    <section class="card" id="nouvelle-demande">
        <div class="section-heading"><div><p class="eyebrow">Prescription</p><h2>Nouvelle demande d’analyse</h2></div></div>
        <?php if (empty($patients) || empty($tests)): ?><p class="empty-state">Ajoutez au moins un patient et une analyse au catalogue pour créer une demande.</p><?php else: ?>
        <form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="order_test">
            <div class="form-group"><label for="patient_id">Patient</label><select name="patient_id" id="patient_id" required><option value="">Sélectionner</option><?php foreach ($patients as $patient): ?><option value="<?php echo (int)$patient['id']; ?>"><?php echo htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="test_id">Analyse</label><select name="test_id" id="test_id" required><option value="">Sélectionner une analyse</option><?php foreach ($tests as $test): ?><option value="<?php echo (int)$test['id']; ?>"><?php echo htmlspecialchars($test['category'] . ' · ' . $test['name'] . ' · ' . number_format((float)$test['price'], 0, ',', ' ') . ' BIF'); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="medecin_id">Prescrit par <span class="muted-text">(facultatif)</span></label><select name="medecin_id" id="medecin_id"><option value="0">Non renseigné</option><?php foreach ($doctors as $doctor): ?><option value="<?php echo (int)$doctor['id']; ?>">Dr <?php echo htmlspecialchars($doctor['prenom'] . ' ' . $doctor['nom']); ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-primary" type="submit">Transmettre au laboratoire</button>
        </form><?php endif; ?>
    </section>
    <section class="card">
        <div class="section-heading"><div><p class="eyebrow">Catalogue des analyses</p><h2>Ajouter une analyse</h2></div></div>
        <form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="add_test">
            <div class="form-group"><label for="test_name">Nom de l’analyse</label><input name="test_name" id="test_name" maxlength="150" required></div>
            <div class="form-grid"><div class="form-group"><label for="test_category">Catégorie</label><input name="test_category" id="test_category" maxlength="100" value="Général" required></div><div class="form-group"><label for="test_price">Tarif (BIF)</label><input type="number" name="test_price" id="test_price" min="0" step="1" value="0" required></div></div>
            <div class="form-group"><label for="test_description">Description</label><textarea name="test_description" id="test_description" rows="2" maxlength="255"></textarea></div>
            <button class="btn btn-secondary" type="submit">Ajouter au catalogue</button>
        </form>
        <div class="catalog-list"><?php foreach ($tests as $test): ?><div><span><?php echo htmlspecialchars($test['name']); ?><small><?php echo htmlspecialchars($test['category']); ?></small></span><strong><?php echo number_format((float)$test['price'], 0, ',', ' '); ?> BIF</strong></div><?php endforeach; ?><?php if (empty($tests)): ?><p class="muted-text">Aucune analyse au catalogue.</p><?php endif; ?></div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
