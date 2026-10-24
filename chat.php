<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();
if (!checkSessionExpiration()) { redirect('/login.php'); }

$user_id = getUserId();
$username = $_SESSION['username'];
$csrf_token = generateCsrfToken();
$error = '';
$success = '';

// Utilisateur sélectionné pour le chat
$chat_with = (int)($_GET['user'] ?? 0);

// Envoyer un message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de sécurité.';
    } else {
        $message = sanitize($_POST['message'] ?? '');
        $receiver_id = (int)($_POST['receiver_id'] ?? 0);
        
        if (!empty($message) && $receiver_id > 0) {
            // Message texte
            $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iis", $user_id, $receiver_id, $message);
            $stmt->execute();
            $stmt->close();
            
            // Créer une notification pour le destinataire
            createNotification($receiver_id, 'chat', 'Nouveau message', $username . ' vous a envoyé un message.', $conn);
        }
        
        // Envoi de document via chat
        if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] === UPLOAD_ERR_OK && $receiver_id > 0) {
            $file = $_FILES['chat_file'];
            $uploadsDir = __DIR__ . '/uploads/chat';
            if (!file_exists($uploadsDir)) { mkdir($uploadsDir, 0755, true); }
            $safeFilename = generateSafeFileName($file['name']);
            $destination = $uploadsDir . '/' . $safeFilename;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $fileMsg = '[Fichier: ' . $file['name'] . ']';
                $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, receiver_id, message, filename, original_name, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iisss", $user_id, $receiver_id, $fileMsg, $safeFilename, $file['name']);
                $stmt->execute();
                $stmt->close();
                createNotification($receiver_id, 'chat', 'Document reçu', $username . ' vous a envoyé un fichier: ' . $file['name'], $conn);
            }
        }
        
        // Rediriger pour éviter la double soumission
        redirect('/chat.php?user=' . $receiver_id);
    }
}

// Télécharger un fichier envoyé via chat
if (isset($_GET['download_file'])) {
    $msgId = (int)$_GET['download_file'];
    $stmt = $conn->prepare("SELECT filename, original_name FROM chat_messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?) LIMIT 1");
    $stmt->bind_param("iii", $msgId, $user_id, $user_id);
    $stmt->execute();
    $fileMsg = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($fileMsg && !empty($fileMsg['filename'])) {
        $fp = __DIR__ . '/uploads/chat/' . $fileMsg['filename'];
        if (file_exists($fp)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileMsg['original_name'] . '"');
            readfile($fp);
            exit;
        }
    }
}

// Liste des utilisateurs
$users = [];
$uResult = $conn->query("SELECT id, username, role FROM users WHERE id != $user_id AND status = 'active' ORDER BY username");
if ($uResult) { $users = $uResult->fetch_all(MYSQLI_ASSOC); }

// Récupérer les messages avec l'utilisateur sélectionné
$messages = [];
$chatUser = null;
if ($chat_with > 0) {
    $stmt = $conn->prepare("SELECT username, role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $chat_with); $stmt->execute();
    $chatUser = $stmt->get_result()->fetch_assoc(); $stmt->close();
    
    if ($chatUser) {
        $stmt = $conn->prepare("SELECT * FROM chat_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at ASC LIMIT 200");
        $stmt->bind_param("iiii", $user_id, $chat_with, $chat_with, $user_id);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Dernier message de chaque conversation pour la liste
$conversations = [];
foreach ($users as $u) {
    $stmt = $conn->prepare("SELECT message, created_at FROM chat_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("iiii", $user_id, $u['id'], $u['id'], $user_id);
    $stmt->execute();
    $lastMsg = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $u['last_message'] = $lastMsg['message'] ?? '';
    $u['last_time'] = $lastMsg['created_at'] ?? '';
    $conversations[] = $u;
}

$pageTitle = 'Chat en temps réel';
require_once __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<section class="page-heading"><div><p class="eyebrow">Équipe hospitalière</p><h1>Messagerie interne</h1><p>Échangez avec les autres membres du personnel.</p></div></section>
<div class="chat-container">
            <!-- Liste des utilisateurs -->
            <div class="chat-sidebar">
                <div class="chat-search" style="padding:1rem;border-bottom:1px solid var(--border);">
                    <h3 style="margin-bottom:0.5rem;"><i class="fas fa-comments"></i> Conversations</h3>
                </div>
                <div class="chat-users-list">
                    <?php foreach ($conversations as $cu): ?>
                        <a href="/chat.php?user=<?php echo $cu['id']; ?>" class="chat-user-item <?php echo ($chat_with == $cu['id']) ? 'active' : ''; ?>">
                            <div class="chat-user-avatar"><?php echo strtoupper(substr($cu['username'], 0, 2)); ?></div>
                            <div class="chat-user-details">
                                <h4><?php echo htmlspecialchars($cu['username']); ?></h4>
                                <p><?php echo htmlspecialchars(substr($cu['last_message'], 0, 30)); ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($conversations)): ?>
                        <p style="padding:1rem;color:var(--text-light);text-align:center;">Aucun utilisateur.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Zone de chat -->
            <div class="chat-area">
                <?php if ($chatUser): ?>
                    <div class="chat-header">
                        <div class="chat-user-avatar"><?php echo strtoupper(substr($chatUser['username'], 0, 2)); ?></div>
                        <div>
                            <h4><?php echo htmlspecialchars($chatUser['username']); ?></h4>
                            <small style="color:var(--text-light);"><?php echo ucfirst($chatUser['role']); ?></small>
                        </div>
                    </div>

                    <div class="chat-body">
                        <?php foreach ($messages as $msg): ?>
                            <div class="chat-message <?php echo ($msg['sender_id'] == $user_id) ? 'chat-message-sent' : 'chat-message-received'; ?>">
                                <div>
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                    <?php if (!empty($msg['filename'])): ?>
                                        <br><a href="/chat.php?download_file=<?php echo $msg['id']; ?>" style="color:inherit;text-decoration:underline;">
                                            <i class="fas fa-paperclip"></i> <?php echo htmlspecialchars($msg['original_name']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="chat-message-meta"><?php echo formatDate($msg['created_at'], 'H:i'); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($messages)): ?>
                            <p style="text-align:center;color:var(--text-light);margin-top:2rem;">Aucun message. Commencez la conversation !</p>
                        <?php endif; ?>
                    </div>

                    <div class="chat-footer">
                        <form method="POST" enctype="multipart/form-data" class="chat-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="receiver_id" value="<?php echo $chat_with; ?>">
                            <input type="hidden" name="send_message" value="1">
                            <input type="text" name="message" class="chat-input" placeholder="Écrire un message..." autocomplete="off">
                            <label for="chat_file" style="cursor:pointer;padding:0.8rem;" title="Joindre un fichier"><i class="fas fa-paperclip" style="font-size:1.2rem;color:var(--text-light);"></i></label>
                            <input type="file" id="chat_file" name="chat_file" style="display:none;">
                            <button type="submit" class="btn btn-primary" style="border-radius:var(--radius-sm);"><i class="fas fa-paper-plane"></i></button>
                        </form>
                    </div>

                <?php else: ?>
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-light);">
                        <div style="text-align:center;">
                            <i class="fas fa-comments" style="font-size:4rem;margin-bottom:1rem;opacity:0.3;"></i>
                            <p>Sélectionnez un utilisateur pour commencer une conversation.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
