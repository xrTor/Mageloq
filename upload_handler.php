<?php
require 'server.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'בקשה לא תקינה'], JSON_UNESCAPED_UNICODE);
    exit;
}

verify_csrf();
require_login();

$gallery_id = (int)($_POST['gallery_id'] ?? 0);
$gallery = get_gallery_for_owner($pdo, $gallery_id);
if (!$gallery) {
    http_response_code(403);
    echo json_encode(['error' => 'אין הרשאה לגלריה'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'שגיאת העלאה: ' . (int)$file['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)$file['size'] <= 0 || (int)$file['size'] > $max_file_size) {
    http_response_code(413);
    echo json_encode(['error' => 'הקובץ גדול מדי או ריק'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare('SELECT p.max_photos, p.expiry_hours FROM users u JOIN packages p ON p.id = u.package_id WHERE u.id = ? LIMIT 1');
$stmt->execute([$gallery['user_id']]);
$rules = $stmt->fetch();
if (!$rules) {
    http_response_code(400);
    echo json_encode(['error' => 'לא נמצאה חבילת משתמש'], JSON_UNESCAPED_UNICODE);
    exit;
}

$count_stmt = $pdo->prepare('SELECT COUNT(*) FROM files WHERE gallery_id = ?');
$count_stmt->execute([$gallery_id]);
$current_count = (int)$count_stmt->fetchColumn();
$max = (int)$rules['max_photos'];
if ($max > 0 && $current_count >= $max) {
    http_response_code(403);
    echo json_encode(['error' => 'חריגה ממכסת הקבצים של החבילה'], JSON_UNESCAPED_UNICODE);
    exit;
}

$original = safe_original_name($file['name'] ?? 'file');
$ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
if (!$ext || !in_array($ext, $allowed_extensions, true)) {
    http_response_code(415);
    echo json_encode(['error' => 'סוג הקובץ אינו נתמך'], JSON_UNESCAPED_UNICODE);
    exit;
}

$final_name = generate_unique_name($ext);
$target = $upload_dir . '/' . $final_name;
if (!move_uploaded_file($file['tmp_name'], $target)) {
    http_response_code(500);
    echo json_encode(['error' => 'הקובץ לא נשמר בשרת'], JSON_UNESCAPED_UNICODE);
    exit;
}
@chmod($target, 0644);

$expiry = ((int)$rules['expiry_hours'] > 0) ? date('Y-m-d H:i:s', strtotime('+' . (int)$rules['expiry_hours'] . ' hours')) : null;
$stmt = $pdo->prepare('INSERT INTO files (gallery_id, file_path, file_name, file_size, expiry_date) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$gallery_id, $final_name, $original, (int)$file['size'], $expiry]);

echo json_encode(['status' => 'success', 'file_id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
?>
