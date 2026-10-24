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
        if ($formAction === 'add_medicine') {
            $name = trim($_POST['name'] ?? '');
            $form = trim($_POST['form'] ?? '');
            $strength = trim($_POST['strength'] ?? '');
            $unit = trim($_POST['unit'] ?? 'unité');
            $price = filter_var($_POST['unit_price'] ?? '', FILTER_VALIDATE_FLOAT);
            $stock = filter_var($_POST['stock_quantity'] ?? '', FILTER_VALIDATE_INT);
            $minimumStock = filter_var($_POST['minimum_stock'] ?? '0', FILTER_VALIDATE_INT);
            $expiryDate = trim($_POST['expiry_date'] ?? '');
            if ($name === '' || $form === '' || $unit === '' || $price === false || $price < 0 || $stock === false || $stock < 0 || $minimumStock === false || $minimumStock < 0) {
                $error = 'Vérifiez le nom, la forme, le prix et les quantités du médicament.';
            } else {
                $expiryDate = $expiryDate !== '' ? $expiryDate : null;
                $conn->begin_transaction();
                try {
                    $insert = $conn->prepare('INSERT INTO medicines (name, form, strength, unit, unit_price, stock_quantity, minimum_stock, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $insert->bind_param('ssssdiis', $name, $form, $strength, $unit, $price, $stock, $minimumStock, $expiryDate);
                    if (!$insert->execute()) { throw new RuntimeException('Ajout au catalogue impossible'); }
                    $medicineId = $conn->insert_id;
                    $insert->close();
                    if ($stock > 0) {
                        $movement = $conn->prepare("INSERT INTO stock_movements (medicine_id, user_id, movement_type, quantity, reason) VALUES (?, ?, 'in', ?, 'Stock initial')");
                        $movement->bind_param('iii', $medicineId, $userId, $stock);
                        if (!$movement->execute()) { throw new RuntimeException('Journal de stock indisponible'); }
                        $movement->close();
                    }
                    $conn->commit();
                    $success = 'Le médicament a été ajouté à l’inventaire.';
                    logActivity($userId, 'ADD_MEDICINE', 'Ajout médicament : ' . $name, $conn);
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'Le médicament n’a pas pu être ajouté. Vérifiez les données saisies.';
                }
            }
        } elseif ($formAction === 'restock') {
            $medicineId = (int)($_POST['medicine_id'] ?? 0);
            $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT);
            $reason = trim($_POST['reason'] ?? 'Réapprovisionnement');
            if ($medicineId < 1 || $quantity === false || $quantity < 1 || $reason === '') {
                $error = 'Indiquez un médicament, une quantité positive et un motif.';
            } else {
                $conn->begin_transaction();
                try {
                    $lock = $conn->prepare('SELECT id FROM medicines WHERE id = ? AND is_active = 1 FOR UPDATE');
                    $lock->bind_param('i', $medicineId);
                    if (!$lock->execute()) { throw new RuntimeException('Lecture du médicament impossible'); }
                    $exists = $lock->get_result()->num_rows === 1;
                    $lock->close();
                    if (!$exists) { throw new RuntimeException('Médicament introuvable'); }
                    $update = $conn->prepare('UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE id = ?');
                    $update->bind_param('ii', $quantity, $medicineId);
                    if (!$update->execute() || $update->affected_rows !== 1) { throw new RuntimeException('Mise à jour du stock impossible'); }
                    $update->close();
                    $movement = $conn->prepare("INSERT INTO stock_movements (medicine_id, user_id, movement_type, quantity, reason) VALUES (?, ?, 'in', ?, ?)");
                    $movement->bind_param('iiis', $medicineId, $userId, $quantity, $reason);
                    if (!$movement->execute()) { throw new RuntimeException('Journal de stock indisponible'); }
                    $movement->close();
                    $conn->commit();
                    $success = 'Le stock a été réapprovisionné.';
                    logActivity($userId, 'RESTOCK_MEDICINE', 'Médicament #' . $medicineId . ' : +' . $quantity, $conn);
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'Le réapprovisionnement n’a pas pu être enregistré.';
                }
            }
        } elseif ($formAction === 'create_prescription') {
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $medicineId = (int)($_POST['medicine_id'] ?? 0);
            $doctorId = (int)($_POST['medecin_id'] ?? 0);
            $dosage = trim($_POST['dosage'] ?? '');
            $quantity = filter_var($_POST['prescription_quantity'] ?? '', FILTER_VALIDATE_INT);
            $instructions = trim($_POST['instructions'] ?? '');
            $notes = trim($_POST['prescription_notes'] ?? '');
            if ($patientId < 1 || $medicineId < 1 || $dosage === '' || $quantity === false || $quantity < 1) {
                $error = 'Le patient, le médicament, la posologie et une quantité positive sont obligatoires.';
            } else {
                $conn->begin_transaction();
                try {
                    $medicineCheck = $conn->prepare('SELECT id FROM medicines WHERE id = ? AND is_active = 1 AND (expiry_date IS NULL OR expiry_date >= CURDATE()) FOR UPDATE');
                    $medicineCheck->bind_param('i', $medicineId);
                    if (!$medicineCheck->execute()) { throw new RuntimeException('Vérification du médicament impossible'); }
                    $medicineAvailable = $medicineCheck->get_result()->num_rows === 1;
                    $medicineCheck->close();
                    if (!$medicineAvailable) { throw new RuntimeException('Médicament périmé ou inactif'); }
                    $prescribedBy = $doctorId > 0 ? $doctorId : null;
                    $insert = $conn->prepare("INSERT INTO prescriptions (patient_id, prescribed_by, instructions, status) VALUES (?, ?, ?, 'issued')");
                    $insert->bind_param('iis', $patientId, $prescribedBy, $notes);
                    if (!$insert->execute()) { throw new RuntimeException('Création de la prescription impossible'); }
                    $prescriptionId = $conn->insert_id;
                    $insert->close();
                    $item = $conn->prepare('INSERT INTO prescription_items (prescription_id, medicine_id, dosage, quantity, instructions) VALUES (?, ?, ?, ?, ?)');
                    $item->bind_param('iisis', $prescriptionId, $medicineId, $dosage, $quantity, $instructions);
                    if (!$item->execute()) { throw new RuntimeException('Ajout du médicament à la prescription impossible'); }
                    $item->close();
                    $conn->commit();
                    $success = 'La prescription a été enregistrée. Elle attend sa délivrance.';
                    logActivity($userId, 'CREATE_PRESCRIPTION', 'Prescription #' . $prescriptionId, $conn);
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'La prescription n’a pas pu être enregistrée.';
                }
            }
        } elseif ($formAction === 'dispense') {
            $prescriptionId = (int)($_POST['prescription_id'] ?? 0);
            $conn->begin_transaction();
            try {
                $lock = $conn->prepare("SELECT p.status, pi.medicine_id, pi.quantity, m.name, m.expiry_date FROM prescriptions p JOIN prescription_items pi ON pi.prescription_id = p.id JOIN medicines m ON m.id = pi.medicine_id WHERE p.id = ? FOR UPDATE");
                $lock->bind_param('i', $prescriptionId);
                if (!$lock->execute()) { throw new RuntimeException('Lecture de la prescription impossible'); }
                $prescription = $lock->get_result()->fetch_assoc();
                $lock->close();
                if (!$prescription || $prescription['status'] !== 'issued') { throw new RuntimeException('Prescription non disponible'); }
                $medicineId = (int)$prescription['medicine_id'];
                $stockLock = $conn->prepare('SELECT stock_quantity FROM medicines WHERE id = ? AND is_active = 1 FOR UPDATE');
                $stockLock->bind_param('i', $medicineId);
                if (!$stockLock->execute()) { throw new RuntimeException('Lecture du stock impossible'); }
                $medicine = $stockLock->get_result()->fetch_assoc();
                $stockLock->close();
                $quantity = (int)$prescription['quantity'];
                if (!$medicine || (int)$medicine['stock_quantity'] < $quantity) {
                    $conn->rollback();
                    $error = 'Stock insuffisant pour délivrer cette prescription.';
                } elseif ($prescription['expiry_date'] && $prescription['expiry_date'] < date('Y-m-d')) {
                    $conn->rollback();
                    $error = 'Le médicament est périmé. Il ne peut pas être délivré.';
                } else {
                    $updateStock = $conn->prepare('UPDATE medicines SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
                    $updateStock->bind_param('iii', $quantity, $medicineId, $quantity);
                    $updateStock->execute();
                    if ($updateStock->affected_rows !== 1) { throw new RuntimeException('Stock modifié'); }
                    $updateStock->close();
                    $movement = $conn->prepare("INSERT INTO stock_movements (medicine_id, user_id, movement_type, quantity, reason) VALUES (?, ?, 'out', ?, ?)");
                    $reason = 'Délivrance prescription #' . $prescriptionId;
                    $movement->bind_param('iiis', $medicineId, $userId, $quantity, $reason);
                    if (!$movement->execute()) { throw new RuntimeException('Journal de stock indisponible'); }
                    $movement->close();
                    $updatePrescription = $conn->prepare("UPDATE prescriptions SET status = 'dispensed' WHERE id = ? AND status = 'issued'");
                    $updatePrescription->bind_param('i', $prescriptionId);
                    if (!$updatePrescription->execute() || $updatePrescription->affected_rows !== 1) { throw new RuntimeException('Mise à jour de prescription impossible'); }
                    $updatePrescription->close();
                    $conn->commit();
                    $success = 'Prescription délivrée. Le stock a été mis à jour.';
                    logActivity($userId, 'DISPENSE_PRESCRIPTION', 'Prescription #' . $prescriptionId . ' : ' . $prescription['name'], $conn);
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'La délivrance n’a pas pu être enregistrée. Vérifiez le stock disponible.';
            }
        }
    }
}

$patients = [];
$doctors = [];
$result = $conn->query('SELECT id, nom, prenom FROM patients WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $patients = $result->fetch_all(MYSQLI_ASSOC); }
$result = $conn->query('SELECT id, nom, prenom FROM medecins WHERE is_archived = 0 ORDER BY nom, prenom');
if ($result) { $doctors = $result->fetch_all(MYSQLI_ASSOC); }
$medicines = [];
$result = $conn->query('SELECT * FROM medicines WHERE is_active = 1 ORDER BY name');
if ($result) { $medicines = $result->fetch_all(MYSQLI_ASSOC); }
$prescribableMedicines = array_values(array_filter($medicines, static function ($medicine) {
    return empty($medicine['expiry_date']) || $medicine['expiry_date'] >= date('Y-m-d');
}));
$prescriptions = [];
$result = $conn->query("SELECT p.id, p.status, p.created_at, p.instructions AS notes, pa.nom AS patient_nom, pa.prenom AS patient_prenom, m.nom AS medecin_nom, m.prenom AS medecin_prenom, me.name AS medicine_name, me.unit, pi.dosage, pi.quantity, pi.instructions AS item_instructions FROM prescriptions p JOIN patients pa ON pa.id = p.patient_id JOIN prescription_items pi ON pi.prescription_id = p.id JOIN medicines me ON me.id = pi.medicine_id LEFT JOIN medecins m ON m.id = p.prescribed_by ORDER BY p.created_at DESC LIMIT 100");
if ($result) { $prescriptions = $result->fetch_all(MYSQLI_ASSOC); }
$stockMovements = [];
$result = $conn->query('SELECT sm.*, m.name AS medicine_name, m.unit, u.username FROM stock_movements sm JOIN medicines m ON m.id = sm.medicine_id LEFT JOIN users u ON u.id = sm.user_id ORDER BY sm.created_at DESC LIMIT 100');
if ($result) { $stockMovements = $result->fetch_all(MYSQLI_ASSOC); }
$pendingPrescriptions = 0;
$result = $conn->query("SELECT COUNT(*) AS pending_count FROM prescriptions WHERE status = 'issued'");
if ($result) { $pendingPrescriptions = (int)$result->fetch_assoc()['pending_count']; }
$lowStockCount = 0;
$expiredCount = 0;
foreach ($medicines as $medicine) {
    if ((int)$medicine['stock_quantity'] <= (int)$medicine['minimum_stock']) { $lowStockCount++; }
    if ($medicine['expiry_date'] && $medicine['expiry_date'] < date('Y-m-d')) { $expiredCount++; }
}

$pageTitle = 'Pharmacie et stocks';
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">Médicaments et délivrances</p><h1>Pharmacie</h1><p>Suivez l’inventaire, les dates de péremption et la délivrance des prescriptions.</p></div>
    <a class="btn btn-primary" href="#nouveau-medicament"><i class="fas fa-capsules"></i> Ajouter un médicament</a>
</section>
<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="summary-grid">
    <article class="summary-card"><span class="summary-icon tone-blue"><i class="fas fa-capsules"></i></span><div><strong><?php echo count($medicines); ?></strong><span>Médicaments actifs</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-amber"><i class="fas fa-boxes-stacked"></i></span><div><strong><?php echo $lowStockCount; ?></strong><span>Stocks à réapprovisionner</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-red"><i class="fas fa-calendar-xmark"></i></span><div><strong><?php echo $expiredCount; ?></strong><span>Produits périmés</span></div></article>
    <article class="summary-card"><span class="summary-icon tone-green"><i class="fas fa-prescription"></i></span><div><strong><?php echo $pendingPrescriptions; ?></strong><span>Prescriptions à délivrer</span></div></article>
</div>

<section class="card" id="nouveau-medicament">
    <div class="section-heading"><div><p class="eyebrow">Catalogue pharmacie</p><h2>Inventaire des médicaments</h2></div></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Médicament</th><th>Présentation</th><th>Stock</th><th>Prix unitaire</th><th>Péremption</th><th>Réapprovisionnement</th></tr></thead><tbody>
    <?php foreach ($medicines as $medicine): $isLow = (int)$medicine['stock_quantity'] <= (int)$medicine['minimum_stock']; $isExpired = $medicine['expiry_date'] && $medicine['expiry_date'] < date('Y-m-d'); ?>
        <tr><td><strong><?php echo htmlspecialchars($medicine['name']); ?></strong><small class="table-subtitle"><?php echo htmlspecialchars($medicine['strength'] ?: 'Dosage non précisé'); ?></small></td><td><?php echo htmlspecialchars($medicine['form']); ?></td><td><strong><?php echo (int)$medicine['stock_quantity']; ?> <?php echo htmlspecialchars($medicine['unit']); ?></strong><small class="table-subtitle">Seuil : <?php echo (int)$medicine['minimum_stock']; ?></small></td><td><?php echo number_format((float)$medicine['unit_price'], 0, ',', ' '); ?> BIF</td><td><?php if (!$medicine['expiry_date']): ?><span class="muted-text">Non renseignée</span><?php else: ?><span class="badge badge-<?php echo $isExpired ? 'danger' : ($isLow ? 'warning' : 'success'); ?>"><?php echo formatDate($medicine['expiry_date'], 'd/m/Y'); ?><?php echo $isExpired ? ' · Périmé' : ''; ?></span><?php endif; ?></td><td><form method="post" class="inline-action"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="restock"><input type="hidden" name="medicine_id" value="<?php echo (int)$medicine['id']; ?>"><input type="number" name="quantity" min="1" required placeholder="Quantité" aria-label="Quantité à ajouter pour <?php echo htmlspecialchars($medicine['name']); ?>"><input type="text" name="reason" maxlength="255" value="Réapprovisionnement" aria-label="Motif"><button type="submit" class="btn btn-secondary btn-small">Ajouter</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (empty($medicines)): ?><tr><td colspan="6" class="empty-table">L’inventaire est vide. Ajoutez un médicament pour commencer.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<div class="clinic-layout">
    <section class="card">
        <div class="section-heading"><div><p class="eyebrow">Réception des produits</p><h2>Ajouter à l’inventaire</h2></div></div>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="add_medicine">
            <div class="form-group"><label for="name">Nom commercial</label><input id="name" name="name" maxlength="150" required></div>
            <div class="form-grid"><div class="form-group"><label for="form">Forme</label><input id="form" name="form" maxlength="80" required placeholder="Comprimé, sirop…"></div><div class="form-group"><label for="strength">Dosage</label><input id="strength" name="strength" maxlength="80" placeholder="500 mg"></div></div>
            <div class="form-grid"><div class="form-group"><label for="unit">Unité de stock</label><input id="unit" name="unit" maxlength="40" value="unité" required></div><div class="form-group"><label for="unit_price">Prix unitaire (BIF)</label><input type="number" id="unit_price" name="unit_price" min="0" step="1" required value="0"></div></div>
            <div class="form-grid"><div class="form-group"><label for="stock_quantity">Stock reçu</label><input type="number" id="stock_quantity" name="stock_quantity" min="0" required value="0"></div><div class="form-group"><label for="minimum_stock">Seuil d’alerte</label><input type="number" id="minimum_stock" name="minimum_stock" min="0" value="0"></div></div>
            <div class="form-group"><label for="expiry_date">Date de péremption</label><input type="date" id="expiry_date" name="expiry_date"></div>
            <button class="btn btn-primary" type="submit">Enregistrer le médicament</button>
        </form>
    </section>

    <section class="card">
        <div class="section-heading"><div><p class="eyebrow">Ordonnances</p><h2>Créer une prescription</h2></div></div>
        <?php if (empty($patients) || empty($prescribableMedicines)): ?><p class="empty-state">Ajoutez des patients et des médicaments non périmés avant de créer une prescription.</p><?php else: ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="create_prescription">
            <div class="form-group"><label for="prescription_patient">Patient</label><select id="prescription_patient" name="patient_id" required><option value="">Sélectionner</option><?php foreach ($patients as $patient): ?><option value="<?php echo (int)$patient['id']; ?>"><?php echo htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="prescription_doctor">Prescrit par</label><select id="prescription_doctor" name="medecin_id"><option value="0">Non renseigné</option><?php foreach ($doctors as $doctor): ?><option value="<?php echo (int)$doctor['id']; ?>">Dr <?php echo htmlspecialchars($doctor['prenom'] . ' ' . $doctor['nom']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="medicine_id">Médicament</label><select id="medicine_id" name="medicine_id" required><option value="">Sélectionner</option><?php foreach ($prescribableMedicines as $medicine): ?><option value="<?php echo (int)$medicine['id']; ?>"><?php echo htmlspecialchars($medicine['name'] . ' · ' . $medicine['form'] . ' · stock ' . $medicine['stock_quantity']); ?></option><?php endforeach; ?></select></div>
            <div class="form-grid"><div class="form-group"><label for="dosage">Posologie</label><input id="dosage" name="dosage" required maxlength="120" placeholder="1 comprimé, 3 fois par jour"></div><div class="form-group"><label for="prescription_quantity">Quantité</label><input type="number" id="prescription_quantity" name="prescription_quantity" min="1" required></div></div>
            <div class="form-group"><label for="instructions">Instructions patient</label><input id="instructions" name="instructions" maxlength="255"></div>
            <div class="form-group"><label for="prescription_notes">Notes médicales</label><textarea id="prescription_notes" name="prescription_notes" rows="2"></textarea></div>
            <button class="btn btn-primary" type="submit">Enregistrer la prescription</button>
        </form>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <div class="section-heading"><div><p class="eyebrow">Ordonnances actives</p><h2>Historique des prescriptions</h2></div></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Patient</th><th>Médecin</th><th>Médicament</th><th>Posologie</th><th>Statut</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($prescriptions as $prescription): ?><tr><td><?php echo formatDate($prescription['created_at'], 'd/m/Y H:i'); ?></td><td><?php echo htmlspecialchars($prescription['patient_prenom'] . ' ' . $prescription['patient_nom']); ?></td><td><?php echo $prescription['medecin_nom'] ? 'Dr ' . htmlspecialchars($prescription['medecin_prenom'] . ' ' . $prescription['medecin_nom']) : '<span class="muted-text">—</span>'; ?></td><td><strong><?php echo htmlspecialchars($prescription['medicine_name']); ?></strong><small class="table-subtitle"><?php echo (int)$prescription['quantity']; ?> <?php echo htmlspecialchars($prescription['unit']); ?></small></td><td><?php echo htmlspecialchars($prescription['dosage']); ?><small class="table-subtitle"><?php echo htmlspecialchars($prescription['item_instructions'] ?? ''); ?></small></td><td><span class="badge badge-<?php echo $prescription['status'] === 'dispensed' ? 'success' : 'warning'; ?>"><?php echo $prescription['status'] === 'dispensed' ? 'Délivrée' : 'À délivrer'; ?></span></td><td><?php if ($prescription['status'] === 'issued'): ?><form method="post" onsubmit="return confirm('Confirmer la délivrance et la sortie du stock ?')"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="form_action" value="dispense"><input type="hidden" name="prescription_id" value="<?php echo (int)$prescription['id']; ?>"><button class="btn btn-success btn-small" type="submit">Délivrer</button></form><?php else: ?><span class="muted-text">Traitée</span><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (empty($prescriptions)): ?><tr><td colspan="7" class="empty-table">Aucune prescription n’a encore été enregistrée.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card">
    <div class="section-heading"><div><p class="eyebrow">Traçabilité de l’inventaire</p><h2>Mouvements de stock récents</h2></div></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Médicament</th><th>Mouvement</th><th>Quantité</th><th>Motif</th><th>Enregistré par</th></tr></thead><tbody>
    <?php foreach ($stockMovements as $movement): ?><tr><td><?php echo formatDate($movement['created_at'], 'd/m/Y H:i'); ?></td><td><?php echo htmlspecialchars($movement['medicine_name']); ?></td><td><span class="badge badge-<?php echo $movement['movement_type'] === 'in' ? 'success' : 'warning'; ?>"><?php echo $movement['movement_type'] === 'in' ? 'Entrée' : 'Sortie'; ?></span></td><td><strong><?php echo $movement['movement_type'] === 'in' ? '+' : '−'; ?><?php echo (int)$movement['quantity']; ?> <?php echo htmlspecialchars($movement['unit']); ?></strong></td><td><?php echo htmlspecialchars($movement['reason']); ?></td><td><?php echo htmlspecialchars($movement['username'] ?? 'Compte supprimé'); ?></td></tr><?php endforeach; ?>
    <?php if (empty($stockMovements)): ?><tr><td colspan="6" class="empty-table">Les entrées et sorties de stock apparaîtront ici.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
