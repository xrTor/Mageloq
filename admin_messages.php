<?php
require 'server.php';
require_admin();

if (isset($_GET['toggle'])) {
    $stmt = $pdo->prepare("UPDATE contact_messages SET status = IF(status = 'new', 'read', 'new') WHERE id = ?");
    $stmt->execute([(int)$_GET['toggle']]);
    redirect('admin_messages.php');
}
if (isset($_GET['del'])) {
    $pdo->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([(int)$_GET['del']]);
    redirect('admin_messages.php');
}
$messages = $pdo->query('SELECT * FROM contact_messages ORDER BY created_at DESC')->fetchAll();
$page_title = 'פניות מהאתר';
require 'header.php';
?>
<h1 class="section-title mb-4">פניות מהאתר</h1>
<?php if (!$messages): ?>
    <div class="card p-5 text-center"><h3 class="fw-bold">אין פניות</h3></div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($messages as $m): ?>
            <div class="list-group-item p-4 <?= $m['status'] === 'new' ? 'bg-warning bg-opacity-10' : '' ?>">
                <div class="d-flex justify-content-between gap-3 flex-wrap">
                    <h4 class="fw-bold mb-1"><?= h($m['name']) ?> <small class="muted">&lt;<?= h($m['email']) ?>&gt;</small></h4>
                    <span class="small muted"><?= h(date('d/m/Y H:i', strtotime($m['created_at']))) ?></span>
                </div>
                <p class="fs-5 my-3"><?= nl2br(h($m['message'])) ?></p>
                <a href="?toggle=<?= (int)$m['id'] ?>" class="btn btn-sm btn-secondary"><?= $m['status'] === 'new' ? 'סמן כנקרא' : 'סמן כחדש' ?></a>
                <a href="?del=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('למחוק פנייה?')">מחק</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require 'footer.php'; ?>
