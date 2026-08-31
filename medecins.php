<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();

if (!checkSessionExpiration()) {
    redirect('/login.php');
}

$user_id = getUserId();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$csrf_token = generateCsrfToken();

// Paramètres de tri
$allowed_sorts = ['id', 'nom', 'specialite', 'numero_licence', 'created_at'];
$sort_by = in_array($_GET['sort_by'] ?? '', $allowed_sorts) ? $_GET['sort_by'] : 'created_at';
$order = ($_GET['order'] ?? '') === 'asc' ? 'ASC' : 'DESC';
$next_order = $order === 'ASC' ? 'desc' : 'asc';

// Paramètres de filtrage
$filter_specialite = sanitize($_GET['specialite'] ?? '');
$view_archive = (isset($_GET['status']) && $_GET['status'] === 'archived') ? 1 : 0;
$searchTerm = sanitize($_GET['search'] ?? '');

// Traiter l'archivage
if (isset($_GET['archive_id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $archive_id = (int)$_GET['archive_id'];
        $query = "UPDATE medecins SET is_archived = 1 WHERE id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $archive_id);
            if ($stmt->execute()) {
                $success = 'Médecin archivé avec succès.';
                logActivity($user_id, 'ARCHIVE_DOCTOR', 'Archivage du médecin ID: ' . $archive_id, $conn);
            }
            $stmt->close();
        }
    }
    $action = 'list';
}

// Traiter la restauration
if (isset($_GET['restore_id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $restore_id = (int)$_GET['restore_id'];
        $query = "UPDATE medecins SET is_archived = 0 WHERE id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $restore_id);
            if ($stmt->execute()) {
                $success = 'Médecin restauré avec succès.';
                logActivity($user_id, 'RESTORE_DOCTOR', 'Restauration du médecin ID: ' . $restore_id, $conn);
            }
            $stmt->close();
        }
    }
    $action = 'list';
}

// Traiter la suppression
if (isset($_GET['delete_id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $delete_id = (int)$_GET['delete_id'];
        $query = "DELETE FROM medecins WHERE id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            if ($stmt->execute()) {
                $success = 'Médecin supprimé définitivement.';
                logActivity($user_id, 'DELETE_DOCTOR', 'Suppression définitive du médecin ID: ' . $delete_id, $conn);
            } else {
                $error = 'Erreur de base de données.';
            }
            $stmt->close();
        }
    }
    $action = 'list';
}

// Traiter l'ajout/modification d'un médecin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['new', 'edit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $nom = sanitize($_POST['nom'] ?? '');
        $prenom = sanitize($_POST['prenom'] ?? '');
        $specialite = sanitize($_POST['specialite'] ?? '');
        $numero_licence = sanitize($_POST['numero_licence'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        
        if (empty($nom) || empty($prenom) || empty($specialite)) {
            $error = 'Les champs obligatoires sont: nom, prénom, spécialité';
        } else {
            if ($action === 'new') {
                $query = "INSERT INTO medecins (nom, prenom, specialite, numero_licence, phone, email, created_at, is_archived) 
                          VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)";
                $stmt = $conn->prepare($query);
                if ($stmt) {
                    $stmt->bind_param("ssssss", $nom, $prenom, $specialite, $numero_licence, $phone, $email);
                    if ($stmt->execute()) {
                        $success = 'Médecin ajouté avec succès.';
                        logActivity($user_id, 'ADD_DOCTOR', 'Ajout du médecin: ' . $nom . ' ' . $prenom, $conn);
                        $action = 'list';
                    } else {
                        $error = 'Erreur lors de l\'ajout.';
                    }
                    $stmt->close();
                }
            } else {
                $medecin_id = (int)$_GET['id'];
                $query = "UPDATE medecins SET nom = ?, prenom = ?, specialite = ?, numero_licence = ?, phone = ?, email = ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                if ($stmt) {
                    $stmt->bind_param("ssssssi", $nom, $prenom, $specialite, $numero_licence, $phone, $email, $medecin_id);
                    if ($stmt->execute()) {
                        $success = 'Médecin modifié avec succès.';
                        logActivity($user_id, 'EDIT_DOCTOR', 'Modification du médecin: ' . $nom . ' ' . $prenom . ' (ID: ' . $medecin_id . ')', $conn);
                        $action = 'list';
                    } else {
                        $error = 'Erreur lors de la modification.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Récupérer un médecin pour l'édition/consultation
$currentMedecin = null;
if (in_array($action, ['edit', 'view']) && isset($_GET['id'])) {
    $medecin_id = (int)$_GET['id'];
    $query = "SELECT * FROM medecins WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $medecin_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $currentMedecin = $result->fetch_assoc();
        $stmt->close();
    }
}

// Récupérer les spécialités pour le filtre
$specialites = [];
$spResult = $conn->query("SELECT DISTINCT specialite FROM medecins ORDER BY specialite");
if ($spResult) {
    while ($spRow = $spResult->fetch_assoc()) {
        $specialites[] = $spRow['specialite'];
    }
}

// Récupérer la liste des médecins
$medecins = [];
$queryParams = [];
$types = '';
$sql = "SELECT * FROM medecins WHERE is_archived = ?";
$queryParams[] = $view_archive;
$types .= 'i';

if (!empty($searchTerm)) {
    $sql .= " AND (nom LIKE ? OR prenom LIKE ? OR numero_licence LIKE ?)";
    $searchPattern = '%' . $searchTerm . '%';
    $queryParams[] = $searchPattern;
    $queryParams[] = $searchPattern;
    $queryParams[] = $searchPattern;
    $types .= 'sss';
}

if (!empty($filter_specialite)) {
    $sql .= " AND specialite = ?";
    $queryParams[] = $filter_specialite;
    $types .= 's';
}

$sql .= " ORDER BY $sort_by $order LIMIT 100";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($queryParams)) {
        $stmt->bind_param($types, ...$queryParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $medecins = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pageTitle = 'Gestion des Médecins';
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li class="<?php echo ($action === 'list' && !$view_archive) ? 'active' : ''; ?>"><a href="/medecins.php"><i class="fas fa-stethoscope"></i> Médecins Actifs</a></li>
            <li class="<?php echo ($action === 'list' && $view_archive) ? 'active' : ''; ?>"><a href="/medecins.php?status=archived"><i class="fas fa-archive"></i> Médecins Archivés</a></li>
            <li class="<?php echo ($action === 'new') ? 'active' : ''; ?>"><a href="/medecins.php?action=new"><i class="fas fa-user-md"></i> Ajouter un Médecin</a></li>
            <li><a href="/patients.php"><i class="fas fa-users"></i> Patients</a></li>
            <li><a href="/consultations.php"><i class="fas fa-clipboard-list"></i> Consultations</a></li>
            <li><a href="/factures.php"><i class="fas fa-file-invoice-dollar"></i> Factures</a></li>
        </ul>
    </div>

    <div class="main-content">
        <?php if ($action === 'list'): ?>
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-stethoscope"></i>
                <?php echo $view_archive ? 'Médecins Archivés' : 'Liste des Médecins'; ?>
            </h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- Filtres -->
            <div style="margin-bottom: 2rem; padding: 1.5rem; background-color: var(--light); border: 1px solid var(--border); border-radius: var(--radius-sm);">
                <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: end;">
                    <?php if ($view_archive): ?>
                        <input type="hidden" name="status" value="archived">
                    <?php endif; ?>
                    <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
                        <label for="search">Rechercher</label>
                        <input type="text" id="search" name="search" placeholder="Nom, licence..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
                        <label for="specialite">Spécialité</label>
                        <select id="specialite" name="specialite">
                            <option value="">Toutes</option>
                            <?php foreach ($specialites as $sp): ?>
                                <option value="<?php echo htmlspecialchars($sp); ?>" <?php echo $filter_specialite === $sp ? 'selected' : ''; ?>><?php echo htmlspecialchars($sp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrer</button>
                        <a href="/medecins.php<?php echo $view_archive ? '?status=archived' : ''; ?>" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
                    </div>
                </form>
            </div>

            <!-- Liste des médecins -->
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><a href="/medecins.php?sort_by=id&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&specialite=<?php echo urlencode($filter_specialite); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">ID <i class="fas fa-sort"></i></a></th>
                            <th><a href="/medecins.php?sort_by=nom&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&specialite=<?php echo urlencode($filter_specialite); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">Nom / Prénom <i class="fas fa-sort"></i></a></th>
                            <th><a href="/medecins.php?sort_by=specialite&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&specialite=<?php echo urlencode($filter_specialite); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">Spécialité <i class="fas fa-sort"></i></a></th>
                            <th><a href="/medecins.php?sort_by=numero_licence&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&specialite=<?php echo urlencode($filter_specialite); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">Licence <i class="fas fa-sort"></i></a></th>
                            <th>Téléphone</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medecins as $medecin): ?>
                        <tr>
                            <td><?php echo $medecin['id']; ?></td>
                            <td><strong>Dr. <?php echo htmlspecialchars($medecin['nom'] . ' ' . $medecin['prenom']); ?></strong></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($medecin['specialite']); ?></span></td>
                            <td><code><?php echo htmlspecialchars($medecin['numero_licence']); ?></code></td>
                            <td><?php echo htmlspecialchars($medecin['phone']); ?></td>
                            <td><?php echo htmlspecialchars($medecin['email']); ?></td>
                            <td>
                                <div class="btn-group">
                                    <?php if (!$view_archive): ?>
                                        <a href="/medecins.php?action=edit&id=<?php echo $medecin['id']; ?>" class="btn btn-warning" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" title="Modifier"><i class="fas fa-edit"></i></a>
                                        <a href="/medecins.php?archive_id=<?php echo $medecin['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="return confirm('Archiver ce médecin ?')" title="Archiver"><i class="fas fa-archive"></i></a>
                                    <?php else: ?>
                                        <a href="/medecins.php?restore_id=<?php echo $medecin['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-success" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="return confirm('Restaurer ce médecin ?')" title="Restaurer"><i class="fas fa-trash-restore"></i></a>
                                        <a href="/medecins.php?delete_id=<?php echo $medecin['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="return confirm('Supprimer définitivement ?')" title="Supprimer définitivement"><i class="fas fa-trash-alt"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (empty($medecins)): ?>
                <p style="text-align: center; margin-top: 2rem; color: var(--text-light);">Aucun médecin trouvé.</p>
            <?php endif; ?>
        </div>

        <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <div class="card">
            <h2 class="card-title">
                <i class="fas <?php echo $action === 'new' ? 'fa-user-md' : 'fa-edit'; ?>"></i>
                <?php echo $action === 'new' ? 'Ajouter un nouveau médecin' : 'Modifier les informations du médecin'; ?>
            </h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label for="nom">Nom *</label>
                        <input type="text" id="nom" name="nom" value="<?php echo htmlspecialchars($currentMedecin['nom'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="prenom">Prénom *</label>
                        <input type="text" id="prenom" name="prenom" value="<?php echo htmlspecialchars($currentMedecin['prenom'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="specialite">Spécialité *</label>
                        <input type="text" id="specialite" name="specialite" value="<?php echo htmlspecialchars($currentMedecin['specialite'] ?? ''); ?>" placeholder="Ex: Cardiologue, Pédiatre..." required>
                    </div>

                    <div class="form-group">
                        <label for="numero_licence">Numéro de licence</label>
                        <input type="text" id="numero_licence" name="numero_licence" value="<?php echo htmlspecialchars($currentMedecin['numero_licence'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="phone">Téléphone</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($currentMedecin['phone'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($currentMedecin['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="btn-group" style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> <?php echo $action === 'new' ? 'Ajouter le médecin' : 'Enregistrer les modifications'; ?>
                    </button>
                    <a href="/medecins.php" class="btn btn-warning"><i class="fas fa-times"></i> Annuler</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
