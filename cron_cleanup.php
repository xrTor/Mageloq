<?php
require 'server.php';

$stmt = $pdo->query('SELECT id, file_path FROM files WHERE expiry_date IS NOT NULL AND expiry_date < NOW()');
$deleted = 0;
foreach ($stmt->fetchAll() as $file) {
    $path = $upload_dir . '/' . $file['file_path'];
    if (is_file($path)) @unlink($path);
    $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([(int)$file['id']]);
    $deleted++;
}
echo "Deleted {$deleted} expired files.";
?>
