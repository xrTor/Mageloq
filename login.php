<?php
require 'server.php';
if (is_logged_in()) redirect('dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        redirect('dashboard.php');
    }
    flash('danger', 'אימייל או סיסמה שגויים.');
}

$page_title = 'התחברות';
require 'header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card p-4 p-md-5">
            <h1 class="h2 fw-bold text-center mb-4">התחברות</h1>
            <form method="POST" novalidate>
                <?= csrf_field() ?>
                <label class="form-label">אימייל</label>
                <input type="email" name="email" class="form-control mb-3" required>
                <label class="form-label">סיסמה</label>
                <input type="password" name="password" class="form-control mb-4" required>
                <button class="btn btn-gradient w-100 btn-lg">היכנס</button>
            </form>
            <p class="text-center muted mt-3 mb-0">אין חשבון? <a href="register.php">הרשמה</a></p>
        </div>
    </div>
</div>
<?php require 'footer.php'; ?>
