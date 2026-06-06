<?php
function generate_unique_name($ext) { return bin2hex(random_bytes(16)) . '.' . $ext; }
function is_admin() { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function require_login() { if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; } }
function require_admin() { if (!is_admin()) { die("גישה אסורה. למנהלים בלבד."); } }
function send_system_mail($to, $subject, $message) {
    $headers = "From: noreply@yourdomain.com\r\nReply-To: noreply@yourdomain.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $message, $headers);
}
?>