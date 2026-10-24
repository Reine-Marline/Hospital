<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireRole('admin');
if (!checkSessionExpiration()) { redirect('/login.php'); }

$tables = [];
$result = $conn->query('SHOW TABLES');
if ($result) {
    while ($row = $result->fetch_row()) { $tables[] = $row[0]; }
}

$pageTitle = 'Tables de données';
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">Administration technique</p><h1>Tables de données</h1><p>Inventaire des tables de la base utilisée par cette installation.</p></div></section>
<section class="card">
    <h2 class="card-title"><i class="fas fa-database" aria-hidden="true"></i> <?php echo count($tables); ?> table(s)</h2>
    <ul class="schema-table-list">
        <?php foreach ($tables as $table): ?><li><code><?php echo htmlspecialchars($table, ENT_QUOTES, 'UTF-8'); ?></code></li><?php endforeach; ?>
    </ul>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
