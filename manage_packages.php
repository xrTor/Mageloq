<?php
require 'server.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    verify_csrf();
    $stmt = $pdo->prepare('UPDATE packages SET name = ?, price = ?, max_photos = ?, expiry_hours = ? WHERE id = ?');
    $stmt->execute([trim($_POST['name']), (float)$_POST['price'], (int)$_POST['max_photos'], (int)$_POST['expiry_hours'], (int)$_POST['id']]);
    flash('success', 'החבילה עודכנה.');
    redirect('manage_packages.php');
}

$packages = $pdo->query('SELECT * FROM packages ORDER BY price ASC, id ASC')->fetchAll();
$page_title = 'ניהול חבילות';
require 'header.php';
?>
<h1 class="section-title mb-4">ניהול חבילות</h1>
<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 text-center align-middle">
            <thead class="table-dark"><tr><th>שם</th><th>מחיר</th><th>קבצים בגלריה<br><small>0 = ללא הגבלה</small></th><th>תפוגה בשעות<br><small>0 = ללא מחיקה</small></th><th>שמירה</th></tr></thead>
            <tbody>
            <?php foreach ($packages as $p): ?>
                <tr><form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="update" value="1">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <td><input type="text" name="name" class="form-control text-center" value="<?= h($p['name']) ?>"></td>
                    <td><input type="number" step="0.01" name="price" class="form-control text-center" value="<?= h((string)$p['price']) ?>"></td>
                    <td><input type="number" name="max_photos" class="form-control text-center" value="<?= (int)$p['max_photos'] ?>"></td>
                    <td><input type="number" name="expiry_hours" class="form-control text-center" value="<?= (int)$p['expiry_hours'] ?>"></td>
                    <td><button class="btn btn-success btn-sm">עדכן</button></td>
                </form></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require 'footer.php'; ?>
