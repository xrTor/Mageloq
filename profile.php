<?php
require 'server.php';
require_login();
$stmt = $pdo->prepare('SELECT u.*, p.name AS package_name, p.max_photos, p.expiry_hours FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$page_title = 'פרופיל';
require 'header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card p-4 p-md-5">
            <h1 class="h2 fw-bold mb-4">הפרופיל שלי</h1>
            <p><strong>שם:</strong> <?= h($user['username']) ?></p>
            <p><strong>אימייל:</strong> <?= h($user['email']) ?></p>
            <p><strong>תפקיד:</strong> <?= $user['role'] === 'admin' ? 'מנהל' : 'לקוח' ?></p>
            <p><strong>חבילה:</strong> <span class="badge badge-soft"><?= h($user['package_name'] ?? 'ללא') ?></span></p>
            <p class="muted mb-0">מגבלה: <?= ((int)$user['max_photos'] === 0) ? 'ללא הגבלה' : h((string)$user['max_photos']) . ' קבצים בגלריה' ?> · תפוגה: <?= ((int)$user['expiry_hours'] === 0) ? 'ללא' : h((string)$user['expiry_hours']) . ' שעות' ?></p>
        </div>
    </div>
</div>
<?php require 'footer.php'; ?>
