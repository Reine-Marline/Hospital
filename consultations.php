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

// Tri
$allowed_sorts = ['id', 'date_consultation', 'created_at'];
$sort_by = in_array($_GET['sort_by'] ?? '', $allowed_sorts) ? $_GET['sort_by'] : 'created_at';
$order = ($_GET['order'] ?? '') === 'asc' ? 'ASC' : 'DESC';
$next_order = $order === 'ASC' ? 'desc' : 'asc';
$searchTerm = sanitize($_GET['search'] ?? '');
$view_archive = (isset($_GET['status']) && $_GET['status'] === 'archived') ? 1 : 0;

// Archivage
if (isset($_GET['archive_id'])) {
    if (verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $aid = (int)$_GET['archive_id'];
        $stmt = $conn->prepare("UPDATE consultations SET is_archived = 1 WHERE id = ?");
        $stmt->bind_param("i", $aid); $stmt->execute(); $stmt->close();
        $success = 'Consultation archivée.';
        logActivity($user_id, 'ARCHIVE_CONSULTATION', 'Archivage consultation ID: '.$aid, $conn);
    }
    $action = 'list';
}

// Restauration
if (isset($_GET['restore_id'])) {
    if (verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $rid = (int)$_GET['restore_id'];
        $stmt = $conn->prepare("UPDATE consultations SET is_archived = 0 WHERE id = ?");
        $stmt->bind_param("i", $rid); $stmt->execute(); $stmt->close();
        $success = 'Consultation restaurée.';
        logActivity($user_id, 'RESTORE_CONSULTATION', 'Restauration consultation ID: '.$rid, $conn);
    }
    $action = 'list';
}

// Suppression
if (isset($_GET['delete_id'])) {
    if (verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $did = (int)$_GET['delete_id'];
        $stmt = $conn->prepare("DELETE FROM consultations WHERE id = ?");
        $stmt->bind_param("i", $did); $stmt->execute(); $stmt->close();
        $success = 'Consultation supprimée définitivement.';
        logActivity($user_id, 'DELETE_CONSULTATION', 'Suppression consultation ID: '.$did, $conn);
    }
    $action = 'list';
}

// Récupérer patients et médecins pour formulaire
$patients_list = [];
$medecins_list = [];
if (in_array($action, ['new', 'edit'])) {
    $r = $conn->query("SELECT id, nom, prenom FROM patients WHERE is_archived = 0 ORDER BY nom");
    if ($r) $patients_list = $r->fetch_all(MYSQLI_ASSOC);
    $r2 = $conn->query("SELECT id, nom, prenom, specialite FROM medecins WHERE is_archived = 0 ORDER BY nom");
    if ($r2) $medecins_list = $r2->fetch_all(MYSQLI_ASSOC);
}

// Traiter ajout/modification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['new', 'edit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $medecin_id = (int)($_POST['medecin_id'] ?? 0);
        $date_consultation = sanitize($_POST['date_consultation'] ?? '');
        $motif = sanitize($_POST['motif'] ?? '');
        $diagnostic = sanitize($_POST['diagnostic'] ?? '');
        $traitement = sanitize($_POST['traitement'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');

        if ($patient_id === 0 || $medecin_id === 0 || empty($date_consultation) || empty($motif)) {
            $error = 'Les champs obligatoires sont requis.';
        } else {
            if ($action === 'new') {
                $stmt = $conn->prepare("INSERT INTO consultations (patient_id, medecin_id, date_consultation, motif, diagnostic, traitement, notes, created_at, is_archived) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0)");
                $stmt->bind_param("iisssss", $patient_id, $medecin_id, $date_consultation, $motif, $diagnostic, $traitement, $notes);
                if ($stmt->execute()) {
                    $success = 'Consultation ajoutée avec succès.';
                    logActivity($user_id, 'ADD_CONSULTATION', 'Ajout consultation', $conn);
                    $action = 'list';
                } else { $error = 'Erreur lors de l\'ajout.'; }
                $stmt->close();
            } else {
                $cid = (int)$_GET['id'];
                $stmt = $conn->prepare("UPDATE consultations SET patient_id=?, medecin_id=?, date_consultation=?, motif=?, diagnostic=?, traitement=?, notes=? WHERE id=?");
                $stmt->bind_param("iisssssi", $patient_id, $medecin_id, $date_consultation, $motif, $diagnostic, $traitement, $notes, $cid);
                if ($stmt->execute()) {
                    $success = 'Consultation modifiée avec succès.';
                    logActivity($user_id, 'EDIT_CONSULTATION', 'Modification consultation ID: '.$cid, $conn);
                    $action = 'list';
                } else { $error = 'Erreur lors de la modification.'; }
                $stmt->close();
            }
        }
    }
}

// Récupérer consultation pour édition
$currentConsultation = null;
if (in_array($action, ['edit', 'view']) && isset($_GET['id'])) {
    $cid = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT c.*, p.nom as patient_nom, p.prenom as patient_prenom, m.nom as medecin_nom, m.prenom as medecin_prenom FROM consultations c LEFT JOIN patients p ON c.patient_id = p.id LEFT JOIN medecins m ON c.medecin_id = m.id WHERE c.id = ? LIMIT 1");
    $stmt->bind_param("i", $cid); $stmt->execute();
    $currentConsultation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Liste des consultations
$consultations = [];
if ($action === 'list') {
    $sql = "SELECT c.*, p.nom as patient_nom, p.prenom as patient_prenom, m.nom as medecin_nom, m.prenom as medecin_prenom FROM consultations c LEFT JOIN patients p ON c.patient_id = p.id LEFT JOIN medecins m ON c.medecin_id = m.id WHERE c.is_archived = ?";
    $params = [$view_archive]; $types = 'i';
    if (!empty($searchTerm)) {
        $sql .= " AND (p.nom LIKE ? OR p.prenom LIKE ? OR c.motif LIKE ?)";
        $sp = '%'.$searchTerm.'%';
        $params[] = $sp; $params[] = $sp; $params[] = $sp; $types .= 'sss';
    }
    $sql .= " ORDER BY $sort_by $order LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $consultations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$pageTitle = 'Gestion des Consultations';
require_once __DIR__ . '/includes/header.php';
?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/consultations.php" <?php echo ($action==='list' && !$view_archive)?'class="active"':''; ?>><i class="fas fa-clipboard-list"></i> Consultations</a></li>
            <li><a href="/consultations.php?status=archived" <?php echo $view_archive?'class="active"':''; ?>><i class="fas fa-archive"></i> Archivées</a></li>
            <li><a href="/consultations.php?action=new" <?php echo ($action==='new')?'class="active"':''; ?>><i class="fas fa-plus-circle"></i> Nouvelle</a></li>
            <li><a href="/patients.php"><i class="fas fa-users"></i> Patients</a></li>
            <li><a href="/medecins.php"><i class="fas fa-stethoscope"></i> Médecins</a></li>
        </ul>
    </div>

    <div class="main-content">
        <?php if ($action === 'list'): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas fa-clipboard-list"></i> <?php echo $view_archive ? 'Consultations Archivées' : 'Liste des Consultations'; ?></h2>

            <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

            <div style="margin-bottom:1.5rem; padding:1rem; background:var(--light); border:1px solid var(--border); border-radius:var(--radius-sm);">
                <form method="GET" style="display:flex; gap:1rem; align-items:end; flex-wrap:wrap;">
                    <?php if ($view_archive): ?><input type="hidden" name="status" value="archived"><?php endif; ?>
                    <div class="form-group" style="margin-bottom:0; flex:1; min-width:200px;">
                        <label for="search">Rechercher</label>
                        <input type="text" id="search" name="search" placeholder="Patient, motif..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Rechercher</button>
                    <a href="/consultations.php<?php echo $view_archive?'?status=archived':''; ?>" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><a href="/consultations.php?sort_by=id&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?><?php echo $view_archive?'&status=archived':''; ?>">ID <i class="fas fa-sort"></i></a></th>
                            <th>Patient</th>
                            <th>Médecin</th>
                            <th><a href="/consultations.php?sort_by=date_consultation&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?><?php echo $view_archive?'&status=archived':''; ?>">Date <i class="fas fa-sort"></i></a></th>
                            <th>Motif</th>
                            <th>Diagnostic</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consultations as $c): ?>
                        <tr>
                            <td><?php echo $c['id']; ?></td>
                            <td><?php echo htmlspecialchars(($c['patient_nom']??'').' '.($c['patient_prenom']??'')); ?></td>
                            <td>Dr. <?php echo htmlspecialchars(($c['medecin_nom']??'').' '.($c['medecin_prenom']??'')); ?></td>
                            <td><?php echo formatDate($c['date_consultation'], 'd/m/Y H:i'); ?></td>
                            <td><?php echo htmlspecialchars(substr($c['motif'],0,40)); ?></td>
                            <td><?php echo htmlspecialchars(substr($c['diagnostic']??'-',0,40)); ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="/consultations.php?action=view&id=<?php echo $c['id']; ?>" class="btn btn-secondary" style="padding:0.4rem 0.8rem;font-size:0.85rem;"><i class="fas fa-eye"></i></a>
                                    <?php if (!$view_archive): ?>
                                        <a href="/consultations.php?action=edit&id=<?php echo $c['id']; ?>" class="btn btn-warning" style="padding:0.4rem 0.8rem;font-size:0.85rem;"><i class="fas fa-edit"></i></a>
                                        <a href="/consultations.php?archive_id=<?php echo $c['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding:0.4rem 0.8rem;font-size:0.85rem;" onclick="return confirm('Archiver ?')"><i class="fas fa-archive"></i></a>
                                    <?php else: ?>
                                        <a href="/consultations.php?restore_id=<?php echo $c['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-success" style="padding:0.4rem 0.8rem;font-size:0.85rem;"><i class="fas fa-trash-restore"></i></a>
                                        <a href="/consultations.php?delete_id=<?php echo $c['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding:0.4rem 0.8rem;font-size:0.85rem;" onclick="return confirm('Supprimer définitivement ?')"><i class="fas fa-trash-alt"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($consultations)): ?><p style="text-align:center;margin-top:2rem;color:var(--text-light);">Aucune consultation trouvée.</p><?php endif; ?>
        </div>

        <?php elseif ($action === 'view' && $currentConsultation): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas fa-clipboard-list"></i> Détails de la Consultation</h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-bottom:2rem;">
                <div>
                    <p><strong>Patient :</strong> <?php echo htmlspecialchars($currentConsultation['patient_nom'].' '.$currentConsultation['patient_prenom']); ?></p>
                    <p><strong>Médecin :</strong> Dr. <?php echo htmlspecialchars($currentConsultation['medecin_nom'].' '.$currentConsultation['medecin_prenom']); ?></p>
                    <p><strong>Date :</strong> <?php echo formatDate($currentConsultation['date_consultation'], 'd/m/Y H:i'); ?></p>
                </div>
                <div>
                    <p><strong>Motif :</strong> <?php echo nl2br(htmlspecialchars($currentConsultation['motif'])); ?></p>
                    <p><strong>Diagnostic :</strong> <?php echo nl2br(htmlspecialchars($currentConsultation['diagnostic'] ?? '-')); ?></p>
                    <p><strong>Traitement :</strong> <?php echo nl2br(htmlspecialchars($currentConsultation['traitement'] ?? '-')); ?></p>
                    <p><strong>Notes :</strong> <?php echo nl2br(htmlspecialchars($currentConsultation['notes'] ?? '-')); ?></p>
                </div>
            </div>

            <!-- Commentaires sur la consultation -->
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
                if (verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                    $ctxt = sanitize($_POST['comment_text'] ?? '');
                    if (!empty($ctxt)) {
                        $stmt = $conn->prepare("INSERT INTO comments (consultation_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->bind_param("iis", $currentConsultation['id'], $user_id, $ctxt);
                        $stmt->execute(); $stmt->close();
                    }
                }
            }
            $comments = [];
            $cq = $conn->prepare("SELECT c.*, u.username FROM comments c JOIN users u ON c.user_id=u.id WHERE c.consultation_id=? ORDER BY c.created_at DESC");
            $cq->bind_param("i", $currentConsultation['id']); $cq->execute();
            $comments = $cq->get_result()->fetch_all(MYSQLI_ASSOC); $cq->close();
            ?>
            <h3 style="margin-top:2rem;"><i class="fas fa-comments"></i> Commentaires</h3>
            <form method="POST" style="margin:1rem 0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="add_comment" value="1">
                <div class="form-group"><textarea name="comment_text" rows="3" placeholder="Ajouter un commentaire..." required></textarea></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Commenter</button>
            </form>
            <?php foreach ($comments as $cm): ?>
                <div style="padding:1rem;border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:0.75rem;background:var(--light);">
                    <strong><?php echo htmlspecialchars($cm['username']); ?></strong> <span style="color:var(--text-light);font-size:0.85rem;"><?php echo formatDate($cm['created_at']); ?></span>
                    <p style="margin-top:0.5rem;"><?php echo nl2br(htmlspecialchars($cm['comment'])); ?></p>
                </div>
            <?php endforeach; ?>

            <div class="btn-group" style="margin-top:2rem;">
                <a href="/consultations.php?action=edit&id=<?php echo $currentConsultation['id']; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Modifier</a>
                <a href="/consultations.php" class="btn btn-primary"><i class="fas fa-chevron-left"></i> Retour</a>
            </div>
        </div>

        <?php elseif (in_array($action, ['new', 'edit'])): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas <?php echo $action==='new'?'fa-plus-circle':'fa-edit'; ?>"></i> <?php echo $action==='new'?'Nouvelle consultation':'Modifier la consultation'; ?></h2>
            <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="patient_id">Patient *</label>
                    <select id="patient_id" name="patient_id" required>
                        <option value="">Sélectionner un patient</option>
                        <?php foreach ($patients_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (isset($currentConsultation) && $currentConsultation['patient_id']==$p['id'])?'selected':''; ?>><?php echo htmlspecialchars($p['nom'].' '.$p['prenom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="medecin_id">Médecin *</label>
                    <select id="medecin_id" name="medecin_id" required>
                        <option value="">Sélectionner un médecin</option>
                        <?php foreach ($medecins_list as $m): ?>
                            <option value="<?php echo $m['id']; ?>" <?php echo (isset($currentConsultation) && $currentConsultation['medecin_id']==$m['id'])?'selected':''; ?>>Dr. <?php echo htmlspecialchars($m['nom'].' '.$m['prenom'].' ('.$m['specialite'].')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="date_consultation">Date de la consultation *</label>
                    <input type="datetime-local" id="date_consultation" name="date_consultation" value="<?php echo isset($currentConsultation)?htmlspecialchars($currentConsultation['date_consultation']):''; ?>" required>
                </div>
                <div class="form-group">
                    <label for="motif">Motif *</label>
                    <textarea id="motif" name="motif" rows="3" required><?php echo htmlspecialchars($currentConsultation['motif'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="diagnostic">Diagnostic</label>
                    <textarea id="diagnostic" name="diagnostic" rows="3"><?php echo htmlspecialchars($currentConsultation['diagnostic'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="traitement">Traitement</label>
                    <textarea id="traitement" name="traitement" rows="3"><?php echo htmlspecialchars($currentConsultation['traitement'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="2"><?php echo htmlspecialchars($currentConsultation['notes'] ?? ''); ?></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Enregistrer</button>
                    <a href="/consultations.php" class="btn btn-warning"><i class="fas fa-times"></i> Annuler</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
