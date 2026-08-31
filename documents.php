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
$searchTerm = sanitize($_GET['search'] ?? '');
$view_archive = (isset($_GET['status']) && $_GET['status'] === 'archived') ? 1 : 0;

// Upload de fichier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Aucun fichier sélectionné ou erreur de téléversement.';
    } else {
        $file = $_FILES['file'];
        $validation = validateUploadedFile($file, 10485760, ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
        if (!$validation['valid']) {
            $error = $validation['error'];
        } else {
            $uploadsDir = __DIR__ . '/uploads';
            if (!file_exists($uploadsDir)) { mkdir($uploadsDir, 0755, true); }
            $safeFilename = generateSafeFileName($file['name']);
            $destination = $uploadsDir . '/' . $safeFilename;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $patient_id = (int)($_POST['patient_id'] ?? 0);
                $document_title = sanitize($_POST['title'] ?? $file['name']);
                $fileType = mime_content_type($destination);
                $fileSize = filesize($destination);
                
                // Vérifier si c'est une nouvelle version d'un document existant
                $version = 1;
                $parent_doc_id = (int)($_POST['parent_doc_id'] ?? 0);
                if ($parent_doc_id > 0) {
                    $vStmt = $conn->prepare("SELECT MAX(version) as max_v FROM documents WHERE id = ? OR id = ?");
                    $vStmt->bind_param("ii", $parent_doc_id, $parent_doc_id);
                    $vStmt->execute();
                    $vResult = $vStmt->get_result()->fetch_assoc();
                    $version = ($vResult['max_v'] ?? 0) + 1;
                    $vStmt->close();
                }

                $stmt = $conn->prepare("INSERT INTO documents (filename, original_name, file_type, file_size, uploaded_by, patient_id, title, version, is_archived, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())");
                $stmt->bind_param("sssiiisi", $safeFilename, $file['name'], $fileType, $fileSize, $user_id, $patient_id, $document_title, $version);
                if ($stmt->execute()) {
                    $success = 'Document téléversé avec succès (version ' . $version . ').';
                    logActivity($user_id, 'UPLOAD_DOCUMENT', 'Upload: ' . $file['name'] . ' v' . $version, $conn);
                    $action = 'list';
                } else {
                    $error = 'Erreur base de données.';
                    unlink($destination);
                }
                $stmt->close();
            } else {
                $error = 'Erreur de téléversement du fichier.';
            }
        }
    }
}

// Téléchargement
if (isset($_GET['download_id'])) {
    $doc_id = (int)$_GET['download_id'];
    $stmt = $conn->prepare("SELECT filename, original_name FROM documents WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $doc_id); $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($doc) {
        $filePath = __DIR__ . '/uploads/' . $doc['filename'];
        if (file_exists($filePath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $doc['original_name'] . '"');
            readfile($filePath);
            exit;
        }
    }
}

// Partage — générer un token de partage
if (isset($_GET['share_id']) && verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    $sid = (int)$_GET['share_id'];
    $shareToken = bin2hex(random_bytes(16));
    $stmt = $conn->prepare("UPDATE documents SET sharing_token = ? WHERE id = ?");
    $stmt->bind_param("si", $shareToken, $sid); $stmt->execute(); $stmt->close();
    $success = 'Lien de partage généré : ' . $_SERVER['HTTP_HOST'] . '/documents.php?shared=' . $shareToken;
    logActivity($user_id, 'SHARE_DOCUMENT', 'Partage document ID: '.$sid, $conn);
    $action = 'list';
}

// Accès fichier partagé (pas besoin de login)
if (isset($_GET['shared'])) {
    $shareToken = sanitize($_GET['shared']);
    $stmt = $conn->prepare("SELECT filename, original_name FROM documents WHERE sharing_token = ? LIMIT 1");
    $stmt->bind_param("s", $shareToken); $stmt->execute();
    $sharedDoc = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($sharedDoc) {
        $filePath = __DIR__ . '/uploads/' . $sharedDoc['filename'];
        if (file_exists($filePath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $sharedDoc['original_name'] . '"');
            readfile($filePath);
            exit;
        }
    }
}

// Archivage
if (isset($_GET['archive_id']) && verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    $aid = (int)$_GET['archive_id'];
    $stmt = $conn->prepare("UPDATE documents SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $aid); $stmt->execute(); $stmt->close();
    $success = 'Document archivé.'; $action = 'list';
    logActivity($user_id, 'ARCHIVE_DOCUMENT', 'Archivage document ID: '.$aid, $conn);
}
// Restauration
if (isset($_GET['restore_id']) && verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    $rid = (int)$_GET['restore_id'];
    $stmt = $conn->prepare("UPDATE documents SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $rid); $stmt->execute(); $stmt->close();
    $success = 'Document restauré.'; $action = 'list';
}
// Suppression
if (isset($_GET['delete_id']) && verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    $did = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("SELECT filename FROM documents WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $did); $stmt->execute();
    $delDoc = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($delDoc) {
        $fp = __DIR__ . '/uploads/' . $delDoc['filename'];
        if (file_exists($fp)) { unlink($fp); }
        $stmt = $conn->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->bind_param("i", $did); $stmt->execute(); $stmt->close();
        $success = 'Document supprimé.';
        logActivity($user_id, 'DELETE_DOCUMENT', 'Suppression document ID: '.$did, $conn);
    }
    $action = 'list';
}

// Patients pour formulaire
$patients_list = [];
if ($action === 'upload') {
    $r = $conn->query("SELECT id, nom, prenom FROM patients WHERE is_archived=0 ORDER BY nom");
    if ($r) $patients_list = $r->fetch_all(MYSQLI_ASSOC);
}

// Liste des documents
$documents = [];
if ($action === 'list') {
    $sql = "SELECT d.*, u.username as uploader_name FROM documents d LEFT JOIN users u ON d.uploaded_by = u.id WHERE d.is_archived = ?";
    $params = [$view_archive]; $types = 'i';
    if (!empty($searchTerm)) {
        $sql .= " AND (d.original_name LIKE ? OR d.title LIKE ?)";
        $sp = '%'.$searchTerm.'%'; $params[]=$sp; $params[]=$sp; $types.='ss';
    }
    $sql .= " ORDER BY d.uploaded_at DESC LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($stmt) { $stmt->bind_param($types, ...$params); $stmt->execute(); $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); }
}

$pageTitle = 'Gestion Documentaire';
require_once __DIR__ . '/includes/header.php';
?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li><a href="/documents.php" <?php echo ($action==='list'&&!$view_archive)?'class="active"':''; ?>><i class="fas fa-folder-open"></i> Documents</a></li>
            <li><a href="/documents.php?status=archived" <?php echo $view_archive?'class="active"':''; ?>><i class="fas fa-archive"></i> Archivés</a></li>
            <li><a href="/documents.php?action=upload" <?php echo ($action==='upload')?'class="active"':''; ?>><i class="fas fa-cloud-upload-alt"></i> Téléverser</a></li>
            <li><a href="/dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a></li>
        </ul>
    </div>

    <div class="main-content">
        <?php if ($action === 'list'): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas fa-folder-open"></i> <?php echo $view_archive?'Documents Archivés':'Gestion Documentaire'; ?></h2>
            <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

            <div style="margin-bottom:1.5rem;padding:1rem;background:var(--light);border:1px solid var(--border);border-radius:var(--radius-sm);">
                <form method="GET" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap;">
                    <?php if ($view_archive): ?><input type="hidden" name="status" value="archived"><?php endif; ?>
                    <div class="form-group" style="margin-bottom:0;flex:1;min-width:250px;">
                        <label for="search">Recherche documentaire</label>
                        <input type="text" id="search" name="search" placeholder="Nom de fichier, titre..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Chercher</button>
                    <a href="/documents.php<?php echo $view_archive?'?status=archived':''; ?>" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr>
                        <th>ID</th><th>Titre / Nom</th><th>Type</th><th>Taille</th><th>Version</th><th>Uploadé par</th><th>Date</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td><?php echo $doc['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($doc['title'] ?? $doc['original_name']); ?></strong>
                                <br><small style="color:var(--text-light);"><?php echo htmlspecialchars($doc['original_name']); ?></small>
                            </td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($doc['file_type']); ?></span></td>
                            <td><?php echo round(($doc['file_size'] ?? 0) / 1024, 1); ?> KB</td>
                            <td><span class="badge badge-warning">v<?php echo $doc['version'] ?? 1; ?></span></td>
                            <td><?php echo htmlspecialchars($doc['uploader_name'] ?? 'N/A'); ?></td>
                            <td><?php echo formatDate($doc['uploaded_at']); ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="/documents.php?download_id=<?php echo $doc['id']; ?>" class="btn btn-primary" style="padding:0.4rem 0.8rem;font-size:0.85rem;" title="Télécharger"><i class="fas fa-download"></i></a>
                                    <?php if (!$view_archive): ?>
                                        <a href="/documents.php?share_id=<?php echo $doc['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-success" style="padding:0.4rem 0.8rem;font-size:0.85rem;" title="Partager"><i class="fas fa-share-alt"></i></a>
                                        <a href="/documents.php?archive_id=<?php echo $doc['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-warning" style="padding:0.4rem 0.8rem;font-size:0.85rem;" title="Archiver"><i class="fas fa-archive"></i></a>
                                    <?php else: ?>
                                        <a href="/documents.php?restore_id=<?php echo $doc['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-success" style="padding:0.4rem 0.8rem;font-size:0.85rem;"><i class="fas fa-trash-restore"></i></a>
                                        <a href="/documents.php?delete_id=<?php echo $doc['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding:0.4rem 0.8rem;font-size:0.85rem;" onclick="return confirm('Supprimer définitivement ?')"><i class="fas fa-trash-alt"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($documents)): ?><p style="text-align:center;margin-top:2rem;color:var(--text-light);">Aucun document trouvé.</p><?php endif; ?>
        </div>

        <?php elseif ($action === 'upload'): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas fa-cloud-upload-alt"></i> Téléverser un document</h2>
            <?php if (!empty($error)): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="title">Titre du document</label>
                    <input type="text" id="title" name="title" placeholder="Titre descriptif">
                </div>
                <div class="form-group">
                    <label for="patient_id">Patient associé (optionnel)</label>
                    <select id="patient_id" name="patient_id">
                        <option value="0">Aucun</option>
                        <?php foreach ($patients_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nom'].' '.$p['prenom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="parent_doc_id">Nouvelle version de (optionnel — ID du document existant)</label>
                    <input type="number" id="parent_doc_id" name="parent_doc_id" min="0" value="0" placeholder="0 = nouveau document">
                </div>
                <div class="form-group">
                    <label for="file">Fichier * (PDF, DOC, DOCX, JPG, PNG — max 10 MB)</label>
                    <input type="file" id="file" name="file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Téléverser</button>
                    <a href="/documents.php" class="btn btn-warning"><i class="fas fa-times"></i> Annuler</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
