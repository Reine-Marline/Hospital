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
$allowed_sorts = ['id', 'nom', 'prenom', 'cin', 'created_at'];
$sort_by = in_array($_GET['sort_by'] ?? '', $allowed_sorts) ? $_GET['sort_by'] : 'created_at';
$order = ($_GET['order'] ?? '') === 'asc' ? 'ASC' : 'DESC';
$next_order = $order === 'ASC' ? 'desc' : 'asc';

// Paramètres de filtrage
$filter_sexe = sanitize($_GET['sexe'] ?? '');
$filter_groupe = sanitize($_GET['groupe_sanguin'] ?? '');
$view_archive = (isset($_GET['status']) && $_GET['status'] === 'archived') ? 1 : 0;
$searchTerm = sanitize($_GET['search'] ?? '');

// Traiter l'archivage
if (isset($_GET['archive_id'])) {
    if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $archive_id = (int)$_GET['archive_id'];
        $query = "UPDATE patients SET is_archived = 1 WHERE id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $archive_id);
            if ($stmt->execute()) {
                $success = 'Patient archivé avec succès.';
                logActivity($user_id, 'ARCHIVE_PATIENT', 'Archivage du patient ID: ' . $archive_id, $conn);
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
        $query = "UPDATE patients SET is_archived = 0 WHERE id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $restore_id);
            if ($stmt->execute()) {
                $success = 'Patient restauré avec succès.';
                logActivity($user_id, 'RESTORE_PATIENT', 'Restauration du patient ID: ' . $restore_id, $conn);
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
        $query = "DELETE FROM patients WHERE id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            if ($stmt->execute()) {
                $success = 'Patient supprimé définitivement.';
                logActivity($user_id, 'DELETE_PATIENT', 'Suppression définitive du patient ID: ' . $delete_id, $conn);
            } else {
                $error = 'Erreur de base de données.';
            }
            $stmt->close();
        }
    }
    $action = 'list';
}

// Traiter l'ajout/modification d'un patient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['new', 'edit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $nom = sanitize($_POST['nom'] ?? '');
        $prenom = sanitize($_POST['prenom'] ?? '');
        $cin = sanitize($_POST['cin'] ?? '');
        $date_naissance = sanitize($_POST['date_naissance'] ?? '');
        $adresse = sanitize($_POST['adresse'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $sexe = sanitize($_POST['sexe'] ?? '');
        $groupe_sanguin = sanitize($_POST['groupe_sanguin'] ?? '');
        
        if (empty($nom) || empty($prenom) || empty($cin)) {
            $error = 'Les champs obligatoires sont: nom, prénom, CIN';
        } else {
            // Chiffrer le CIN pour des raisons de sécurité
            $encryptedCin = encryptData($cin);
            
            if ($action === 'new') {
                $query = "INSERT INTO patients (nom, prenom, cin, date_naissance, adresse, phone, email, sexe, groupe_sanguin, created_at, is_archived) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)";
                $stmt = $conn->prepare($query);
                if ($stmt) {
                    $stmt->bind_param("sssssssss", $nom, $prenom, $encryptedCin, $date_naissance, $adresse, $phone, $email, $sexe, $groupe_sanguin);
                    if ($stmt->execute()) {
                        $success = 'Patient ajouté avec succès.';
                        logActivity($user_id, 'ADD_PATIENT', 'Ajout du patient: ' . $nom . ' ' . $prenom, $conn);
                        $action = 'list';
                    } else {
                        $error = 'Erreur lors de l\'ajout du patient.';
                    }
                    $stmt->close();
                }
            } else {
                $patient_id = (int)$_GET['id'];
                $query = "UPDATE patients SET nom = ?, prenom = ?, cin = ?, date_naissance = ?, adresse = ?, phone = ?, email = ?, sexe = ?, groupe_sanguin = ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                if ($stmt) {
                    $stmt->bind_param("sssssssssi", $nom, $prenom, $encryptedCin, $date_naissance, $adresse, $phone, $email, $sexe, $groupe_sanguin, $patient_id);
                    if ($stmt->execute()) {
                        $success = 'Patient modifié avec succès.';
                        logActivity($user_id, 'EDIT_PATIENT', 'Modification du patient: ' . $nom . ' ' . $prenom . ' (ID: ' . $patient_id . ')', $conn);
                        $action = 'list';
                    } else {
                        $error = 'Erreur lors de la modification du patient.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Récupérer un patient pour l'édition/consultation
$currentPatient = null;
if (in_array($action, ['edit', 'view']) && isset($_GET['id'])) {
    $patient_id = (int)$_GET['id'];
    $query = "SELECT * FROM patients WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $currentPatient = $result->fetch_assoc();
        $stmt->close();
        
        if ($currentPatient) {
            // Déchiffrer le CIN
            $decrypted = decryptData($currentPatient['cin']);
            $currentPatient['cin'] = !empty($decrypted) ? $decrypted : $currentPatient['cin'];
        }
    }
}

// Récupérer la liste filtrée/triée des patients
$patients = [];
$queryParams = [];
$types = '';
$sql = "SELECT * FROM patients WHERE is_archived = ?";
$queryParams[] = $view_archive;
$types .= 'i';

if (!empty($searchTerm)) {
    // Note: Pour chercher sur le CIN chiffré, nous devrons déchiffrer en PHP ou faire un matching exact si encryptData donne toujours la même valeur. 
    // Cependant, la recherche sur le nom/prenom fonctionne directement en SQL.
    $sql .= " AND (nom LIKE ? OR prenom LIKE ?)";
    $searchPattern = '%' . $searchTerm . '%';
    $queryParams[] = $searchPattern;
    $queryParams[] = $searchPattern;
    $types .= 'ss';
}

if (!empty($filter_sexe)) {
    $sql .= " AND sexe = ?";
    $queryParams[] = $filter_sexe;
    $types .= 's';
}

if (!empty($filter_groupe)) {
    $sql .= " AND groupe_sanguin = ?";
    $queryParams[] = $filter_groupe;
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
    while ($row = $result->fetch_assoc()) {
        // Déchiffrer le CIN pour l'affichage
        $decrypted = decryptData($row['cin']);
        $row['cin'] = !empty($decrypted) ? $decrypted : $row['cin'];
        $patients[] = $row;
    }
    $stmt->close();
}

$pageTitle = 'Gestion des Patients';
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="sidebar">
    <div class="sidebar-menu">
        <ul>
            <li class="<?php echo ($action === 'list' && !$view_archive) ? 'active' : ''; ?>"><a href="/patients.php"><i class="fas fa-users"></i> Patients Actifs</a></li>
            <li class="<?php echo ($action === 'list' && $view_archive) ? 'active' : ''; ?>"><a href="/patients.php?status=archived"><i class="fas fa-archive"></i> Patients Archivés</a></li>
            <li class="<?php echo ($action === 'new') ? 'active' : ''; ?>"><a href="/patients.php?action=new"><i class="fas fa-user-plus"></i> Ajouter un Patient</a></li>
            <li><a href="/medecins.php"><i class="fas fa-stethoscope"></i> Médecins</a></li>
            <li><a href="/consultations.php"><i class="fas fa-clipboard-list"></i> Consultations</a></li>
            <li><a href="/factures.php"><i class="fas fa-file-invoice-dollar"></i> Factures</a></li>
        </ul>
    </div>

    <div class="main-content">
        <?php if ($action === 'list'): ?>
        <div class="card">
            <h2 class="card-title">
                <i class="fas fa-users"></i> 
                <?php echo $view_archive ? 'Patients Archivés' : 'Liste des Patients'; ?>
            </h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- Filtres de recherche avancée -->
            <div style="margin-bottom: 2rem; padding: 1.5rem; background-color: var(--light); border: 1px solid var(--border); border-radius: var(--radius-sm);">
                <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end;">
                    <?php if ($view_archive): ?>
                        <input type="hidden" name="status" value="archived">
                    <?php endif; ?>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="search">Nom / Prénom</label>
                        <input type="text" id="search" name="search" placeholder="Rechercher..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="sexe">Sexe</label>
                        <select id="sexe" name="sexe">
                            <option value="">Tous</option>
                            <option value="M" <?php echo $filter_sexe === 'M' ? 'selected' : ''; ?>>Masculin</option>
                            <option value="F" <?php echo $filter_sexe === 'F' ? 'selected' : ''; ?>>Féminin</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="groupe_sanguin">Groupe Sanguin</label>
                        <select id="groupe_sanguin" name="groupe_sanguin">
                            <option value="">Tous</option>
                            <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $grp): ?>
                                <option value="<?php echo $grp; ?>" <?php echo $filter_groupe === $grp ? 'selected' : ''; ?>><?php echo $grp; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;"><i class="fas fa-filter"></i> Filtrer</button>
                        <a href="/patients.php<?php echo $view_archive ? '?status=archived' : ''; ?>" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
                    </div>
                </form>
            </div>

            <!-- Tableau des patients -->
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><a href="/patients.php?sort_by=id&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&sexe=<?php echo urlencode($filter_sexe); ?>&groupe_sanguin=<?php echo urlencode($filter_groupe); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">ID <i class="fas fa-sort"></i></a></th>
                            <th><a href="/patients.php?sort_by=nom&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&sexe=<?php echo urlencode($filter_sexe); ?>&groupe_sanguin=<?php echo urlencode($filter_groupe); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">Nom <i class="fas fa-sort"></i></a></th>
                            <th><a href="/patients.php?sort_by=prenom&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&sexe=<?php echo urlencode($filter_sexe); ?>&groupe_sanguin=<?php echo urlencode($filter_groupe); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">Prénom <i class="fas fa-sort"></i></a></th>
                            <th><a href="/patients.php?sort_by=cin&order=<?php echo $next_order; ?>&search=<?php echo urlencode($searchTerm); ?>&sexe=<?php echo urlencode($filter_sexe); ?>&groupe_sanguin=<?php echo urlencode($filter_groupe); ?><?php echo $view_archive ? '&status=archived' : ''; ?>">CIN <i class="fas fa-sort"></i></a></th>
                            <th>Téléphone</th>
                            <th>Email</th>
                            <th>Groupe Sanguin</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><?php echo $patient['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($patient['nom']); ?></strong></td>
                            <td><?php echo htmlspecialchars($patient['prenom']); ?></td>
                            <td><code><?php echo htmlspecialchars($patient['cin']); ?></code></td>
                            <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                            <td><?php echo htmlspecialchars($patient['email']); ?></td>
                            <td>
                                <?php if (!empty($patient['groupe_sanguin'])): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($patient['groupe_sanguin']); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="/patients.php?action=view&id=<?php echo $patient['id']; ?>" class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;"><i class="fas fa-eye"></i></a>
                                    
                                    <?php if (!$view_archive): ?>
                                        <a href="/patients.php?action=edit&id=<?php echo $patient['id']; ?>" class="btn btn-warning" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;"><i class="fas fa-edit"></i></a>
                                        <a href="/patients.php?archive_id=<?php echo $patient['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="return confirm('Archiver ce patient ?')" title="Archiver"><i class="fas fa-archive"></i></a>
                                    <?php else: ?>
                                        <a href="/patients.php?restore_id=<?php echo $patient['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-success" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="return confirm('Restaurer ce patient ?')" title="Restaurer"><i class="fas fa-trash-restore"></i></a>
                                        <a href="/patients.php?delete_id=<?php echo $patient['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="return confirm('Supprimer définitivement ?')" title="Supprimer définitivement"><i class="fas fa-trash-alt"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (empty($patients)): ?>
                <p style="text-align: center; margin-top: 2rem; color: var(--text-light);">Aucun patient trouvé.</p>
            <?php endif; ?>
        </div>

        <?php elseif (($action === 'new' || $action === 'edit')): ?>
        <div class="card">
            <h2 class="card-title">
                <i class="fas <?php echo $action === 'new' ? 'fa-user-plus' : 'fa-edit'; ?>"></i>
                <?php echo $action === 'new' ? 'Ajouter un nouveau patient' : 'Éditer le patient'; ?>
            </h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label for="nom">Nom *</label>
                        <input type="text" id="nom" name="nom" value="<?php echo htmlspecialchars($currentPatient['nom'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="prenom">Prénom *</label>
                        <input type="text" id="prenom" name="prenom" value="<?php echo htmlspecialchars($currentPatient['prenom'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="cin">CIN *</label>
                        <input type="text" id="cin" name="cin" value="<?php echo htmlspecialchars($currentPatient['cin'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="date_naissance">Date de naissance</label>
                        <input type="date" id="date_naissance" name="date_naissance" value="<?php echo htmlspecialchars($currentPatient['date_naissance'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="sexe">Sexe</label>
                        <select id="sexe" name="sexe">
                            <option value="">Sélectionner</option>
                            <option value="M" <?php echo (isset($currentPatient['sexe']) && $currentPatient['sexe'] === 'M') ? 'selected' : ''; ?>>Masculin</option>
                            <option value="F" <?php echo (isset($currentPatient['sexe']) && $currentPatient['sexe'] === 'F') ? 'selected' : ''; ?>>Féminin</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="groupe_sanguin">Groupe sanguin</label>
                        <select id="groupe_sanguin" name="groupe_sanguin">
                            <option value="">Sélectionner</option>
                            <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $grp): ?>
                                <option value="<?php echo $grp; ?>" <?php echo (isset($currentPatient['groupe_sanguin']) && $currentPatient['groupe_sanguin'] === $grp) ? 'selected' : ''; ?>><?php echo $grp; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="phone">Téléphone</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($currentPatient['phone'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($currentPatient['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="adresse">Adresse</label>
                    <textarea id="adresse" name="adresse" rows="3"><?php echo htmlspecialchars($currentPatient['adresse'] ?? ''); ?></textarea>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> <?php echo $action === 'new' ? 'Ajouter le patient' : 'Enregistrer les modifications'; ?>
                    </button>
                    <a href="/patients.php" class="btn btn-warning"><i class="fas fa-times"></i> Annuler</a>
                </div>
            </form>
        </div>

        <?php elseif ($action === 'view' && $currentPatient): ?>
        <div class="card">
            <h2 class="card-title"><i class="fas fa-id-card"></i> Détails du Patient</h2>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <div style="padding: 1rem; border-right: 1px solid var(--border);">
                    <p style="margin-bottom: 0.75rem;"><strong>Nom :</strong> <?php echo htmlspecialchars($currentPatient['nom']); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Prénom :</strong> <?php echo htmlspecialchars($currentPatient['prenom']); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>CIN (Déchiffré) :</strong> <code><?php echo htmlspecialchars($currentPatient['cin']); ?></code></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Date de naissance :</strong> <?php echo formatDate($currentPatient['date_naissance'], 'd/m/Y'); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Sexe :</strong> <?php echo $currentPatient['sexe'] === 'M' ? 'Masculin' : ($currentPatient['sexe'] === 'F' ? 'Féminin' : '-'); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Groupe Sanguin :</strong> <span class="badge badge-info"><?php echo htmlspecialchars($currentPatient['groupe_sanguin'] ?? '-'); ?></span></p>
                </div>

                <div style="padding: 1rem;">
                    <p style="margin-bottom: 0.75rem;"><strong>Téléphone :</strong> <?php echo htmlspecialchars($currentPatient['phone']); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Email :</strong> <?php echo htmlspecialchars($currentPatient['email']); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Adresse :</strong> <?php echo htmlspecialchars($currentPatient['adresse']); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Inscrit le :</strong> <?php echo formatDate($currentPatient['created_at']); ?></p>
                    <p style="margin-bottom: 0.75rem;"><strong>Statut :</strong> 
                        <span class="badge badge-<?php echo $currentPatient['is_archived'] ? 'warning' : 'success'; ?>">
                            <?php echo $currentPatient['is_archived'] ? 'Archivé' : 'Actif'; ?>
                        </span>
                    </p>
                </div>
            </div>

            <!-- Système de commentaires additionnel pour le patient -->
            <div style="margin-top: 3rem;">
                <h3><i class="fas fa-comments"></i> Observations et Commentaires Médicaux</h3>
                
                <?php
                // Traiter l'ajout de commentaire
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
                    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                        $error = 'Erreur de sécurité.';
                    } else {
                        $comment_text = sanitize($_POST['comment_text'] ?? '');
                        if (!empty($comment_text)) {
                            $stmt = $conn->prepare("INSERT INTO comments (patient_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
                            $stmt->bind_param("iis", $currentPatient['id'], $user_id, $comment_text);
                            if ($stmt->execute()) {
                                $success = 'Observation ajoutée.';
                                logActivity($user_id, 'ADD_COMMENT', 'Ajout d\'un commentaire pour le patient ID: ' . $currentPatient['id'], $conn);
                            }
                            $stmt->close();
                        }
                    }
                }
                
                // Récupérer les commentaires
                $comments = [];
                $cQuery = $conn->prepare("SELECT c.*, u.username FROM comments c JOIN users u ON c.user_id = u.id WHERE c.patient_id = ? ORDER BY c.created_at DESC");
                $cQuery->bind_param("i", $currentPatient['id']);
                $cQuery->execute();
                $comments = $cQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                $cQuery->close();
                ?>

                <form method="POST" style="margin-top: 1rem; margin-bottom: 2rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="add_comment" value="1">
                    <div class="form-group">
                        <textarea name="comment_text" rows="3" placeholder="Écrire un commentaire ou une note d'observation..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Ajouter l'observation</button>
                </form>

                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($comments as $comment): ?>
                        <div style="padding: 1rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background-color: var(--light);">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <strong><i class="fas fa-user"></i> <?php echo htmlspecialchars($comment['username']); ?></strong>
                                <span style="font-size: 0.85rem; color: var(--text-light);"><?php echo formatDate($comment['created_at']); ?></span>
                            </div>
                            <p style="margin: 0; color: var(--text);"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($comments)): ?>
                        <p style="color: var(--text-light); font-style: italic;">Aucun commentaire médical pour le moment.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="btn-group" style="margin-top: 2rem;">
                <a href="/patients.php?action=edit&id=<?php echo $currentPatient['id']; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Modifier</a>
                <a href="/patients.php" class="btn btn-primary"><i class="fas fa-chevron-left"></i> Retour</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
