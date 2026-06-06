<?php
require 'server.php';
require_login();

$stmt = $pdo->prepare('SELECT g.*, COUNT(f.id) AS files_count, COALESCE(SUM(f.file_size),0) AS total_size FROM galleries g LEFT JOIN files f ON f.gallery_id = g.id WHERE g.user_id = ? GROUP BY g.id ORDER BY g.created_at DESC');
$stmt->execute([$_SESSION['user_id']]);
$galleries = $stmt->fetchAll();

$total_galleries = count($galleries);
$total_files = array_sum(array_map(fn($g) => (int)$g['files_count'], $galleries));
$total_bytes = array_sum(array_map(fn($g) => (int)$g['total_size'], $galleries));

$page_title = 'לוח בקרה';
require 'header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h1 class="section-title mb-1">הגלריות שלי</h1>
        <p class="muted mb-0">שלום <?= h($_SESSION['username'] ?? '') ?>, כאן מנהלים את גלריות הלקוחות.</p>
    </div>
    <a href="create_gallery.php" class="btn btn-gradient"><i class="fa-solid fa-plus"></i> גלריה חדשה</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?= $total_galleries ?></div><div class="muted">גלריות</div></div></div>
    <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?= $total_files ?></div><div class="muted">קבצים</div></div></div>
    <div class="col-md-4"><div class="stat-box"><div class="stat-number"><?= h(format_bytes((int)$total_bytes)) ?></div><div class="muted">נפח כולל</div></div></div>
</div>

<?php if (!$galleries): ?>
    <div class="card p-5 text-center">
        <div class="display-3 mb-3">📸</div>
        <h3 class="fw-bold">עדיין אין גלריות</h3>
        <p class="muted">צור גלריה ראשונה, העלה תמונות ושלח קישור ללקוח.</p>
        <div><a href="create_gallery.php" class="btn btn-gradient">צור גלריה</a></div>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($galleries as $g): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 p-4">
                    <div class="d-flex justify-content-between gap-3">
                        <div>
                            <h4 class="fw-bold mb-1"><?= h($g['gallery_name']) ?></h4>
                            <div class="muted small">נוצרה: <?= h(date('d/m/Y H:i', strtotime($g['created_at']))) ?></div>
                        </div>
                        <span class="badge badge-soft align-self-start"><?= (int)$g['files_count'] ?> קבצים</span>
                    </div>
                    <div class="my-3 muted">נפח: <?= h(format_bytes((int)$g['total_size'])) ?><?= $g['password_hash'] ? ' · מוגנת בסיסמה' : '' ?></div>
                    <input class="form-control copy-input mb-3" readonly value="<?= h((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']) . '/gallery.php?token=' . $g['gallery_token']) ?>" onclick="this.select(); navigator.clipboard?.writeText(this.value);">
                    <div class="d-grid gap-2">
                        <a href="manage_gallery.php?id=<?= (int)$g['id'] ?>" class="btn btn-outline-primary">ניהול והעלאה</a>
                        <a href="gallery.php?token=<?= h($g['gallery_token']) ?>" target="_blank" class="btn btn-success">פתיחה ללקוח</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require 'footer.php'; ?>
