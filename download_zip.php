<?php
require 'server.php';
set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('שיטה לא נתמכת.');
}
verify_csrf();

$gallery_id = (int)($_POST['gallery_id'] ?? 0);
$file_ids = array_values(array_filter(array_map('intval', $_POST['files'] ?? [])));
if (!$gallery_id || !$file_ids) die('לא נבחרו קבצים.');

$stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ? LIMIT 1');
$stmt->execute([$gallery_id]);
$gallery = $stmt->fetch();
if (!$gallery || !user_can_access_gallery($gallery)) {
    http_response_code(403);
    die('אין הרשאה להורדה.');
}

$placeholders = implode(',', array_fill(0, count($file_ids), '?'));
$stmt = $pdo->prepare("SELECT file_path, file_name FROM files WHERE gallery_id = ? AND id IN ($placeholders)");
$stmt->execute(array_merge([$gallery_id], $file_ids));
$files = $stmt->fetchAll();
if (!$files) die('לא נמצאו קבצים.');

if (!class_exists('ZipArchive')) die('תוסף ZipArchive לא פעיל בשרת.');
$zip = new ZipArchive();
$zip_path = tempnam(sys_get_temp_dir(), 'mageloq_') . '.zip';
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) die('שגיאה ביצירת ZIP.');

$used = [];
foreach ($files as $f) {
    $real = $upload_dir . '/' . $f['file_path'];
    if (!is_file($real)) continue;
    $name = safe_original_name($f['file_name']);
    $base_name = $name;
    if (isset($used[$base_name])) {
        $used[$base_name]++;
        $info = pathinfo($base_name);
        $name = ($info['filename'] ?? 'file') . '-' . $used[$base_name] . (isset($info['extension']) ? '.' . $info['extension'] : '');
    } else {
        $used[$base_name] = 1;
    }
    $zip->addFile($real, $name);
}
$zip->close();

$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $gallery['gallery_name']) ?: 'mageloq_gallery';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '.zip"');
header('Content-Length: ' . filesize($zip_path));
readfile($zip_path);
@unlink($zip_path);
exit;
?>
