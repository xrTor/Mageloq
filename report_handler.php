<?php
require 'server.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'שיטה לא נתמכת'], JSON_UNESCAPED_UNICODE);
    exit;
}
verify_csrf();

$file_id = (int)($_POST['file_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
if (!$file_id || $reason === '') {
    http_response_code(400);
    echo json_encode(['error' => 'חסר דיווח'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare('SELECT f.file_name, g.id AS gallery_id, g.user_id, g.gallery_name, g.password_hash, u.email, u.username FROM files f JOIN galleries g ON g.id = f.gallery_id LEFT JOIN users u ON u.id = g.user_id WHERE f.id = ? LIMIT 1');
$stmt->execute([$file_id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'קובץ לא נמצא'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!empty($row['password_hash']) && empty($_SESSION['gallery_auth_' . $row['gallery_id']]) && !(is_logged_in() && (is_admin() || (int)$row['user_id'] === (int)($_SESSION['user_id'] ?? 0)))) {
    http_response_code(403);
    echo json_encode(['error' => 'אין הרשאה לדווח בגלריה זו'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->prepare('INSERT INTO reports (file_id, reason) VALUES (?, ?)')->execute([$file_id, $reason]);
if (!empty($row['email'])) {
    send_system_mail($row['email'], 'דיווח חדש בגלריה: ' . $row['gallery_name'], "התקבל דיווח על הקובץ {$row['file_name']}:\n\n{$reason}");
}
echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
?>
