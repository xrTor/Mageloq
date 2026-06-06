<?php
require 'server.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($name === '') {
        flash('danger', 'חובה להזין שם גלריה.');
    } else {
        $token = bin2hex(random_bytes(18));
        $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $stmt = $pdo->prepare('INSERT INTO galleries (user_id, gallery_name, gallery_token, password_hash) VALUES (?, ?, ?, ?)');
        $stmt->execute([$_SESSION['user_id'], $name, $token, $hash]);
        flash('success', 'הגלריה נוצרה בהצלחה. עכשיו אפשר להעלות קבצים.');
        redirect('manage_gallery.php?id=' . (int)$pdo->lastInsertId());
    }
}

$page_title = 'יצירת גלריה';
require 'header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card p-4 p-md-5">
            <h1 class="h2 fw-bold mb-4">גלריה חדשה</h1>
            <form method="POST">
                <?= csrf_field() ?>
                <label class="form-label">שם הגלריה</label>
                <input type="text" name="name" class="form-control mb-3" placeholder="לדוגמה: חתונה - משפחת כהן" required>
                <label class="form-label">סיסמה ללקוח <span class="muted">(אופציונלי)</span></label>
                <input type="password" name="password" class="form-control mb-4" placeholder="אפשר להשאיר ריק">
                <button class="btn btn-gradient btn-lg w-100">צור גלריה</button>
            </form>
        </div>
    </div>
</div>
<?php require 'footer.php'; ?>
