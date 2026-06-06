<?php
require 'server.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
        flash('danger', 'נא למלא שם, אימייל תקין והודעה.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, $message]);
        send_system_mail($admin_email, 'פנייה חדשה מאתר Mageloq', "שם: {$name}\nאימייל: {$email}\n\n{$message}");
        flash('success', 'הפנייה נשלחה בהצלחה.');
        redirect('contact.php');
    }
}

$page_title = 'צור קשר';
require 'header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card p-4 p-md-5">
            <h1 class="h2 fw-bold text-center mb-4">צור קשר</h1>
            <form method="POST">
                <?= csrf_field() ?>
                <label class="form-label">שם</label>
                <input type="text" name="name" class="form-control mb-3" required>
                <label class="form-label">אימייל</label>
                <input type="email" name="email" class="form-control mb-3" required>
                <label class="form-label">הודעה</label>
                <textarea name="message" class="form-control mb-4" rows="5" required></textarea>
                <button class="btn btn-gradient w-100 btn-lg">שלח</button>
            </form>
        </div>
    </div>
</div>
<?php require 'footer.php'; ?>
