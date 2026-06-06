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
$stmt = $pdo->prepare('SELECT f.*, g.user_id FROM files f JOIN galleries g ON g.id = f.gallery_id WHERE f.id = ? LIMIT 1');
$stmt->execute([$file_id]);
$file = $stmt->fetch();
if (!$file || !is_logged_in() || (!is_admin() && (int)$file['user_id'] !== (int)$_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'אין הרשאה למחיקה'], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = $upload_dir . '/' . $file['file_path'];
if (is_file($path)) @unlink($path);
$pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$file_id]);
echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
?>
