<?php
require 'server.php';
if (is_logged_in()) redirect('dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        flash('danger', 'נא למלא שם, אימייל תקין וסיסמה באורך 6 תווים לפחות.');
    } else {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $role = $count === 0 ? 'admin' : 'client';
        $package_id = $count === 0 ? 3 : 1;
        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, package_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $package_id]);
            flash('success', $role === 'admin' ? 'נרשמת בהצלחה כמנהל הראשון במערכת.' : 'נרשמת בהצלחה. אפשר להתחבר עכשיו.');
            redirect('login.php');
        } catch (PDOException $e) {
            flash('danger', 'האימייל כבר קיים במערכת.');
        }
    }
}

$page_title = 'הרשמה';
require 'header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card p-4 p-md-5">
            <h1 class="h2 fw-bold text-center mb-4">פתיחת חשבון Mageloq</h1>
            <form method="POST" novalidate>
                <?= csrf_field() ?>
                <label class="form-label">שם מלא / שם עסק</label>
                <input type="text" name="username" class="form-control mb-3" required>
                <label class="form-label">אימייל</label>
                <input type="email" name="email" class="form-control mb-3" required>
                <label class="form-label">סיסמה</label>
                <input type="password" name="password" class="form-control mb-4" minlength="6" required>
                <button class="btn btn-gradient w-100 btn-lg">צור חשבון</button>
            </form>
            <p class="text-center muted mt-3 mb-0">כבר רשום? <a href="login.php">התחברות</a></p>
        </div>
    </div>
</div>
<?php require 'footer.php'; ?>
