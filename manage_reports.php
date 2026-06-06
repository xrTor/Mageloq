<?php
require 'server.php';
require_login();

if (isset($_GET['del'])) {
    $report_id = (int)$_GET['del'];
    if (is_admin()) {
        $stmt = $pdo->prepare('DELETE FROM reports WHERE id = ?');
        $stmt->execute([$report_id]);
    } else {
        $stmt = $pdo->prepare('DELETE r FROM reports r JOIN files f ON f.id = r.file_id JOIN galleries g ON g.id = f.gallery_id WHERE r.id = ? AND g.user_id = ?');
        $stmt->execute([$report_id, $_SESSION['user_id']]);
    }
    flash('success', 'הדיווח סומן כטופל.');
    redirect('manage_reports.php');
}

if (is_admin()) {
    $stmt = $pdo->query('SELECT r.*, f.file_path, f.file_name, g.gallery_name, u.username FROM reports r JOIN files f ON f.id = r.file_id JOIN galleries g ON g.id = f.gallery_id LEFT JOIN users u ON u.id = g.user_id ORDER BY r.created_at DESC');
} else {
    $stmt = $pdo->prepare('SELECT r.*, f.file_path, f.file_name, g.gallery_name, u.username FROM reports r JOIN files f ON f.id = r.file_id JOIN galleries g ON g.id = f.gallery_id LEFT JOIN users u ON u.id = g.user_id WHERE g.user_id = ? ORDER BY r.created_at DESC');
    $stmt->execute([$_SESSION['user_id']]);
}
$reports = $stmt->fetchAll();
$page_title = 'דיווחי לקוחות';
require 'header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="section-title mb-0">דיווחי לקוחות</h1>
    <span class="badge badge-soft"><?= count($reports) ?> פתוחים</span>
</div>
<?php if (!$reports): ?>
    <div class="card p-5 text-center"><h3 class="fw-bold">אין דיווחים פתוחים</h3><p class="muted mb-0">מצוין — אין כרגע תמונות שסומנו כבעייתיות.</p></div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($reports as $r): $kind = file_kind($r['file_path']); ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-danger">
                    <?php if ($kind === 'image'): ?>
                        <img src="uploads/<?= h($r['file_path']) ?>" class="gallery-img" alt="<?= h($r['file_name']) ?>">
                    <?php else: ?>
                        <div class="file-thumb"><i class="fa-regular fa-file-lines"></i></div>
                    <?php endif; ?>
                    <div class="card-body">
                        <h5 class="text-danger fw-bold">“<?= h($r['reason']) ?>”</h5>
                        <p class="small muted mb-2">גלריה: <?= h($r['gallery_name']) ?><br>קובץ: <?= h($r['file_name']) ?><br>תאריך: <?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></p>
                        <a href="?del=<?= (int)$r['id'] ?>" class="btn btn-outline-success w-100" onclick="return confirm('לסמן כטופל?')">טופל / מחק דיווח</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require 'footer.php'; ?>
