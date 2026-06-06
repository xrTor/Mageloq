<?php
require 'server.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    verify_csrf();
    $user_id = (int)$_POST['user_id'];
    $role = in_array($_POST['role'] ?? 'client', ['admin','client'], true) ? $_POST['role'] : 'client';
    $package_id = (int)$_POST['package_id'];
    $stmt = $pdo->prepare('UPDATE users SET role = ?, package_id = ? WHERE id = ?');
    $stmt->execute([$role, $package_id, $user_id]);
    flash('success', 'המשתמש עודכן.');
    redirect('admin_panel.php');
}

$users = $pdo->query('SELECT u.*, p.name AS package_name FROM users u LEFT JOIN packages p ON p.id = u.package_id ORDER BY u.created_at DESC')->fetchAll();
$packages = $pdo->query('SELECT id, name FROM packages ORDER BY price ASC, id ASC')->fetchAll();
$page_title = 'ניהול משתמשים';
require 'header.php';
?>
<h1 class="section-title mb-4">ניהול משתמשים</h1>
<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead class="table-dark"><tr><th>משתמש</th><th>תפקיד</th><th>חבילה</th><th>נוצר</th><th>שמירה</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="update_user" value="1">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <td><strong><?= h($u['username']) ?></strong><br><span class="small muted"><?= h($u['email']) ?></span></td>
                        <td>
                            <select name="role" class="form-select">
                                <option value="client" <?= $u['role']==='client'?'selected':'' ?>>לקוח</option>
                                <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>מנהל</option>
                            </select>
                        </td>
                        <td>
                            <select name="package_id" class="form-select">
                                <?php foreach ($packages as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= (int)$u['package_id']===(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><?= h(date('d/m/Y', strtotime($u['created_at']))) ?></td>
                        <td><button class="btn btn-primary btn-sm">שמור</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require 'footer.php'; ?>
