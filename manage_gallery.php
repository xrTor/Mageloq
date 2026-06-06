<?php
require 'server.php';
require_login();

$gallery_id = (int)($_GET['id'] ?? 0);
$gallery = get_gallery_for_owner($pdo, $gallery_id);
if (!$gallery) {
    http_response_code(404);
    die('גלריה לא נמצאה או שאין הרשאה.');
}

function mageloq_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function mageloq_verify_csrf_json(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        mageloq_json_response(['error' => 'פג תוקף הטופס. רענן את העמוד ונסה שוב.'], 419);
    }
}

function mageloq_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return (bool)$stmt->fetch();
}

function mageloq_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($index));
    return (bool)$stmt->fetch();
}

function mageloq_gallery_schema_ready(PDO $pdo): array {
    static $ready = null;
    if ($ready !== null) return $ready;

    $ready = [
        'folder_path' => false,
        'file_hash' => false,
        'duplicate_of_file_id' => false,
    ];

    try {
        if (!mageloq_column_exists($pdo, 'files', 'folder_path')) {
            $pdo->exec("ALTER TABLE files ADD COLUMN folder_path VARCHAR(255) NOT NULL DEFAULT '' AFTER file_name");
        }
        if (!mageloq_column_exists($pdo, 'files', 'file_hash')) {
            $pdo->exec("ALTER TABLE files ADD COLUMN file_hash CHAR(64) DEFAULT NULL AFTER file_size");
        }
        if (!mageloq_column_exists($pdo, 'files', 'duplicate_of_file_id')) {
            $pdo->exec("ALTER TABLE files ADD COLUMN duplicate_of_file_id INT DEFAULT NULL AFTER file_hash");
        }

        if (!mageloq_index_exists($pdo, 'files', 'idx_files_folder')) {
            $pdo->exec("ALTER TABLE files ADD INDEX idx_files_folder (gallery_id, folder_path)");
        }
        if (!mageloq_index_exists($pdo, 'files', 'idx_files_hash')) {
            $pdo->exec("ALTER TABLE files ADD INDEX idx_files_hash (file_hash)");
        }
        if (!mageloq_index_exists($pdo, 'files', 'idx_files_duplicate')) {
            $pdo->exec("ALTER TABLE files ADD INDEX idx_files_duplicate (duplicate_of_file_id)");
        }

        $ready['folder_path'] = mageloq_column_exists($pdo, 'files', 'folder_path');
        $ready['file_hash'] = mageloq_column_exists($pdo, 'files', 'file_hash');
        $ready['duplicate_of_file_id'] = mageloq_column_exists($pdo, 'files', 'duplicate_of_file_id');
    } catch (Throwable $e) {
        // אם אין הרשאה ל־ALTER TABLE, העמוד עדיין יעבוד בלי הפיצ'רים החדשים במסד.
    }

    return $ready;
}

function mageloq_sanitize_folder_path(?string $path): string {
    $path = (string)$path;
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = trim($path);
    $path = trim($path, "/. \t\n\r\0\x0B");
    if ($path === '') return '';

    $parts = [];
    foreach (explode('/', $path) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') continue;
        $part = preg_replace('/[\x00-\x1F]/u', '', $part) ?? $part;
        $part = safe_original_name($part);
        $part = trim($part, ". \t\n\r\0\x0B");
        if ($part !== '') $parts[] = $part;
    }

    $clean = implode('/', $parts);
    return mb_substr($clean, 0, 255, 'UTF-8');
}

function mageloq_folder_from_relative_path(?string $relative_path): string {
    $relative_path = str_replace('\\', '/', (string)$relative_path);
    $relative_path = trim($relative_path, "/. \t\n\r\0\x0B");
    if ($relative_path === '' || strpos($relative_path, '/') === false) return '';

    $dir = dirname($relative_path);
    if ($dir === '.' || $dir === '/') return '';
    return mageloq_sanitize_folder_path($dir);
}

function mageloq_make_folder_path(?string $upload_folder, ?string $relative_path): string {
    $base = mageloq_sanitize_folder_path($upload_folder);
    $relative_folder = mageloq_folder_from_relative_path($relative_path);

    if ($base !== '' && $relative_folder !== '') return mageloq_sanitize_folder_path($base . '/' . $relative_folder);
    if ($base !== '') return $base;
    return $relative_folder;
}

function mageloq_download_file_name(string $name): string {
    $name = safe_original_name($name);
    $name = str_replace(["\r", "\n"], ' ', $name);
    return trim($name) !== '' ? $name : 'download';
}

function mageloq_find_original_by_hash(PDO $pdo, int $gallery_id, string $hash, array $schema, ?int $exclude_id = null): ?array {
    if (!$schema['file_hash']) return null;

    $where = 'gallery_id = ? AND file_hash = ?';
    $params = [$gallery_id, $hash];
    if ($exclude_id !== null) {
        $where .= ' AND id <> ?';
        $params[] = $exclude_id;
    }

    $stmt = $pdo->prepare("SELECT id, file_name FROM files WHERE {$where} ORDER BY id ASC LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mageloq_insert_file(PDO $pdo, int $gallery_id, string $final_name, string $original, int $size, ?string $expiry, string $folder_path, ?string $hash, ?int $duplicate_of_file_id, array $schema): int {
    $columns = ['gallery_id', 'file_path', 'file_name', 'file_size', 'expiry_date'];
    $values = [$gallery_id, $final_name, $original, $size, $expiry];

    if ($schema['folder_path']) {
        $columns[] = 'folder_path';
        $values[] = $folder_path;
    }
    if ($schema['file_hash']) {
        $columns[] = 'file_hash';
        $values[] = $hash;
    }
    if ($schema['duplicate_of_file_id']) {
        $columns[] = 'duplicate_of_file_id';
        $values[] = $duplicate_of_file_id;
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO files (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function mageloq_backfill_gallery_hashes(PDO $pdo, int $gallery_id, string $upload_dir, array $schema): void {
    if (!$schema['file_hash']) return;

    try {
        $stmt = $pdo->prepare('SELECT id, file_path, file_hash FROM files WHERE gallery_id = ? ORDER BY id ASC');
        $stmt->execute([$gallery_id]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if (!empty($row['file_hash'])) continue;
            $real = $upload_dir . '/' . $row['file_path'];
            if (!is_file($real)) continue;
            $hash = @hash_file('sha256', $real);
            if (!$hash) continue;
            $upd = $pdo->prepare('UPDATE files SET file_hash = ? WHERE id = ? AND gallery_id = ?');
            $upd->execute([$hash, (int)$row['id'], $gallery_id]);
        }

        if (!$schema['duplicate_of_file_id']) return;

        $stmt = $pdo->prepare('SELECT id, file_hash, duplicate_of_file_id FROM files WHERE gallery_id = ? AND file_hash IS NOT NULL AND file_hash <> "" ORDER BY id ASC');
        $stmt->execute([$gallery_id]);
        $rows = $stmt->fetchAll();
        $first_by_hash = [];

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $hash = (string)$row['file_hash'];
            $desired_duplicate = null;

            if (isset($first_by_hash[$hash])) {
                $desired_duplicate = $first_by_hash[$hash];
            } else {
                $first_by_hash[$hash] = $id;
            }

            $current_duplicate = $row['duplicate_of_file_id'] !== null ? (int)$row['duplicate_of_file_id'] : null;
            if ($current_duplicate !== $desired_duplicate) {
                $upd = $pdo->prepare('UPDATE files SET duplicate_of_file_id = ? WHERE id = ? AND gallery_id = ?');
                $upd->execute([$desired_duplicate, $id, $gallery_id]);
            }
        }
    } catch (Throwable $e) {
        // לא מפילים את העמוד בגלל Hash ישן שלא הצליח להתעדכן.
    }
}

function mageloq_zip_entry_name(string $folder_path, string $file_name, array &$used): string {
    $folder = mageloq_sanitize_folder_path($folder_path);
    $name = mageloq_download_file_name($file_name);
    $entry = ($folder !== '' ? $folder . '/' : '') . $name;
    $entry = trim(str_replace('\\', '/', $entry), '/');
    if ($entry === '') $entry = 'download';

    $base = $entry;
    $counter = 1;
    while (isset($used[$entry])) {
        $counter++;
        $dir = dirname($base);
        $filename = basename($base);
        $info = pathinfo($filename);
        $new_filename = ($info['filename'] ?? 'file') . '-' . $counter . (isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '');
        $entry = ($dir !== '.' ? $dir . '/' : '') . $new_filename;
    }
    $used[$entry] = true;
    return $entry;
}

function gallery_upload_rules(PDO $pdo, array $gallery): ?array {
    $stmt = $pdo->prepare('SELECT p.max_photos, p.expiry_hours FROM users u JOIN packages p ON p.id = u.package_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([(int)$gallery['user_id']]);
    $rules = $stmt->fetch();
    return $rules ?: null;
}

function normalize_upload_files(array $fileField): array {
    $files = [];
    if (!isset($fileField['name'])) return $files;

    if (is_array($fileField['name'])) {
        $count = count($fileField['name']);
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'name' => $fileField['name'][$i] ?? '',
                'full_path' => $fileField['full_path'][$i] ?? ($fileField['name'][$i] ?? ''),
                'type' => $fileField['type'][$i] ?? '',
                'tmp_name' => $fileField['tmp_name'][$i] ?? '',
                'error' => $fileField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $fileField['size'][$i] ?? 0,
            ];
        }
    } else {
        $fileField['full_path'] = $fileField['full_path'] ?? ($fileField['name'] ?? '');
        $files[] = $fileField;
    }

    return $files;
}

$gallery_schema = mageloq_gallery_schema_ready($pdo);
mageloq_backfill_gallery_hashes($pdo, $gallery_id, $upload_dir, $gallery_schema);

if (isset($_GET['download_file'])) {
    $file_id = (int)$_GET['download_file'];
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = ? AND gallery_id = ? LIMIT 1');
    $stmt->execute([$file_id, $gallery_id]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        die('הקובץ לא נמצא.');
    }

    $real = $upload_dir . '/' . $file['file_path'];
    if (!is_file($real)) {
        http_response_code(404);
        die('הקובץ חסר בשרת.');
    }

    $download_name = mageloq_download_file_name((string)$file['file_name']);
    $fallback_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $download_name) ?: 'download';
    $mime = function_exists('mime_content_type') ? (mime_content_type($real) ?: 'application/octet-stream') : 'application/octet-stream';

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: attachment; filename="' . $fallback_name . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['zip_selected'])) {
    verify_csrf();

    $file_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['files'] ?? []))));
    if (!$file_ids) die('לא נבחרו קבצים.');
    if (!class_exists('ZipArchive')) die('תוסף ZipArchive לא פעיל בשרת.');

    $placeholders = implode(',', array_fill(0, count($file_ids), '?'));
    $folder_select = $gallery_schema['folder_path'] ? 'folder_path' : "'' AS folder_path";
    $stmt = $pdo->prepare("SELECT file_path, file_name, {$folder_select} FROM files WHERE gallery_id = ? AND id IN ({$placeholders})");
    $stmt->execute(array_merge([$gallery_id], $file_ids));
    $zip_files = $stmt->fetchAll();
    if (!$zip_files) die('לא נמצאו קבצים.');

    set_time_limit(0);
    $zip = new ZipArchive();
    $zip_path = tempnam(sys_get_temp_dir(), 'mageloq_') . '.zip';
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) die('שגיאה ביצירת ZIP.');

    $used_names = [];
    foreach ($zip_files as $f) {
        $real = $upload_dir . '/' . $f['file_path'];
        if (!is_file($real)) continue;
        $entry_name = mageloq_zip_entry_name((string)($f['folder_path'] ?? ''), (string)$f['file_name'], $used_names);
        $zip->addFile($real, $entry_name);
    }
    $zip->close();

    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$gallery['gallery_name']) ?: 'mageloq_gallery';
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '.zip"');
    header('Content-Length: ' . filesize($zip_path));
    header('X-Content-Type-Options: nosniff');
    readfile($zip_path);
    @unlink($zip_path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_move_folder'])) {
    mageloq_verify_csrf_json();

    if (!$gallery_schema['folder_path']) {
        mageloq_json_response(['error' => 'עמודת התיקיות לא קיימת במסד.'], 500);
    }

    $ids = $_POST['file_ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $folder_path = mageloq_sanitize_folder_path($_POST['folder_path'] ?? '');

    if (!$ids) {
        mageloq_json_response(['error' => 'לא נבחרו קבצים להעברה.'], 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE files SET folder_path = ? WHERE gallery_id = ? AND id IN ({$placeholders})");
    $stmt->execute(array_merge([$folder_path, $gallery_id], $ids));

    mageloq_json_response(['status' => 'success', 'folder_path' => $folder_path, 'updated' => $stmt->rowCount()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload'])) {
    mageloq_verify_csrf_json();

    $rules = gallery_upload_rules($pdo, $gallery);
    if (!$rules) {
        mageloq_json_response(['error' => 'לא נמצאה חבילת משתמש פעילה להעלאות.'], 400);
    }

    if (empty($_FILES['file'])) {
        mageloq_json_response(['error' => 'לא נשלח קובץ להעלאה.'], 400);
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        mageloq_json_response(['error' => 'שגיאת העלאה: ' . (int)$file['error']], 400);
    }

    if ((int)$file['size'] <= 0 || (int)$file['size'] > $max_file_size) {
        mageloq_json_response(['error' => 'הקובץ גדול מדי או ריק'], 413);
    }

    $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM files WHERE gallery_id = ?');
    $count_stmt->execute([$gallery_id]);
    $current_count = (int)$count_stmt->fetchColumn();
    $max = (int)$rules['max_photos'];
    if ($max > 0 && $current_count >= $max) {
        mageloq_json_response(['error' => 'חריגה ממכסת הקבצים של החבילה'], 403);
    }

    $original = safe_original_name($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!$ext || !in_array($ext, $allowed_extensions, true)) {
        mageloq_json_response(['error' => 'סוג הקובץ אינו נתמך'], 415);
    }

    $hash = @hash_file('sha256', $file['tmp_name']);
    if (!$hash) {
        mageloq_json_response(['error' => 'לא ניתן לחשב Hash לקובץ.'], 500);
    }

    $relative_path = $_POST['relative_path'] ?? '';
    $folder_path = mageloq_make_folder_path($_POST['upload_folder'] ?? '', $relative_path);
    $duplicate = mageloq_find_original_by_hash($pdo, $gallery_id, $hash, $gallery_schema);
    $duplicate_id = $duplicate ? (int)$duplicate['id'] : null;

    $final_name = generate_unique_name($ext);
    $target = $upload_dir . '/' . $final_name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        mageloq_json_response(['error' => 'הקובץ לא נשמר בשרת'], 500);
    }
    @chmod($target, 0644);

    $expiry = ((int)$rules['expiry_hours'] > 0) ? date('Y-m-d H:i:s', strtotime('+' . (int)$rules['expiry_hours'] . ' hours')) : null;
    $new_id = mageloq_insert_file($pdo, $gallery_id, $final_name, $original, (int)$file['size'], $expiry, $folder_path, $hash, $duplicate_id, $gallery_schema);

    mageloq_json_response([
        'status' => 'success',
        'file_id' => $new_id,
        'hash' => $hash,
        'folder_path' => $folder_path,
        'duplicate' => $duplicate_id !== null,
        'duplicate_of_file_id' => $duplicate_id,
        'duplicate_of_name' => $duplicate['file_name'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['native_upload'])) {
    verify_csrf();

    $rules = gallery_upload_rules($pdo, $gallery);
    if (!$rules) {
        flash('danger', 'לא נמצאה חבילת משתמש פעילה להעלאות.');
        redirect('manage_gallery.php?id=' . $gallery_id);
    }

    $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM files WHERE gallery_id = ?');
    $count_stmt->execute([$gallery_id]);
    $current_count = (int)$count_stmt->fetchColumn();

    $max = (int)$rules['max_photos'];
    $expiry_hours = (int)$rules['expiry_hours'];
    $upload_folder = mageloq_sanitize_folder_path($_POST['upload_folder'] ?? '');
    $uploaded = 0;
    $duplicates = 0;
    $errors = [];
    $incoming_files = normalize_upload_files($_FILES['files'] ?? []);

    if (!$incoming_files || !array_filter($incoming_files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        flash('warning', 'לא נבחרו קבצים להעלאה.');
        redirect('manage_gallery.php?id=' . $gallery_id);
    }

    foreach ($incoming_files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($max > 0 && ($current_count + $uploaded) >= $max) {
            $errors[] = 'הגעת למכסת הקבצים של החבילה.';
            break;
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'שגיאה בהעלאת ' . safe_original_name($file['name'] ?? 'file') . ' (קוד ' . (int)$file['error'] . ').';
            continue;
        }

        if ((int)$file['size'] <= 0 || (int)$file['size'] > $max_file_size) {
            $errors[] = safe_original_name($file['name'] ?? 'file') . ' גדול מדי או ריק.';
            continue;
        }

        $original = safe_original_name($file['name'] ?? 'file');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!$ext || !in_array($ext, $allowed_extensions, true)) {
            $errors[] = $original . ' — סוג הקובץ אינו נתמך.';
            continue;
        }

        $hash = @hash_file('sha256', $file['tmp_name']);
        if (!$hash) {
            $errors[] = $original . ' — לא ניתן לחשב Hash לקובץ.';
            continue;
        }

        $folder_path = mageloq_make_folder_path($upload_folder, $file['full_path'] ?? '');
        $duplicate = mageloq_find_original_by_hash($pdo, $gallery_id, $hash, $gallery_schema);
        $duplicate_id = $duplicate ? (int)$duplicate['id'] : null;
        if ($duplicate_id !== null) $duplicates++;

        $final_name = generate_unique_name($ext);
        $target = $upload_dir . '/' . $final_name;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $errors[] = $original . ' — הקובץ לא נשמר בשרת.';
            continue;
        }
        @chmod($target, 0644);

        $expiry = ($expiry_hours > 0) ? date('Y-m-d H:i:s', strtotime('+' . $expiry_hours . ' hours')) : null;
        mageloq_insert_file($pdo, $gallery_id, $final_name, $original, (int)$file['size'], $expiry, $folder_path, $hash, $duplicate_id, $gallery_schema);
        $uploaded++;
    }

    if ($uploaded > 0) {
        $message = 'הועלו ' . $uploaded . ' קבצים בהצלחה.';
        if ($duplicates > 0) $message .= ' מתוכם ' . $duplicates . ' סומנו ככפולים לפי Hash.';
        flash('success', $message);
    }
    if ($errors) {
        flash($uploaded > 0 ? 'warning' : 'danger', implode(' ', array_slice($errors, 0, 4)) . (count($errors) > 4 ? ' ועוד...' : ''));
    }

    redirect('manage_gallery.php?id=' . $gallery_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_gallery'])) {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $clear_password = !empty($_POST['clear_password']);
    if ($name === '') {
        flash('danger', 'שם הגלריה לא יכול להיות ריק.');
    } else {
        if ($clear_password) {
            $stmt = $pdo->prepare('UPDATE galleries SET gallery_name = ?, password_hash = NULL WHERE id = ?');
            $stmt->execute([$name, $gallery_id]);
        } elseif ($password !== '') {
            $stmt = $pdo->prepare('UPDATE galleries SET gallery_name = ?, password_hash = ? WHERE id = ?');
            $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT), $gallery_id]);
        } else {
            $stmt = $pdo->prepare('UPDATE galleries SET gallery_name = ? WHERE id = ?');
            $stmt->execute([$name, $gallery_id]);
        }
        flash('success', 'פרטי הגלריה עודכנו.');
        redirect('manage_gallery.php?id=' . $gallery_id);
    }
}

if ($gallery_schema['folder_path'] && $gallery_schema['duplicate_of_file_id']) {
    $stmt = $pdo->prepare('SELECT f.*, d.file_name AS duplicate_original_name FROM files f LEFT JOIN files d ON d.id = f.duplicate_of_file_id WHERE f.gallery_id = ? ORDER BY f.folder_path ASC, f.created_at DESC, f.id DESC');
} elseif ($gallery_schema['folder_path']) {
    $stmt = $pdo->prepare('SELECT f.*, NULL AS duplicate_original_name FROM files f WHERE f.gallery_id = ? ORDER BY f.folder_path ASC, f.created_at DESC, f.id DESC');
} elseif ($gallery_schema['duplicate_of_file_id']) {
    $stmt = $pdo->prepare('SELECT f.*, "" AS folder_path, d.file_name AS duplicate_original_name FROM files f LEFT JOIN files d ON d.id = f.duplicate_of_file_id WHERE f.gallery_id = ? ORDER BY f.created_at DESC, f.id DESC');
} else {
    $stmt = $pdo->prepare('SELECT f.*, "" AS folder_path, NULL AS duplicate_original_name FROM files f WHERE f.gallery_id = ? ORDER BY f.created_at DESC, f.id DESC');
}
$stmt->execute([$gallery_id]);
$files = $stmt->fetchAll();

$files_by_folder = [];
$folder_options = [];
$lightbox_images = [];
$image_index_by_id = [];
$duplicate_count = 0;

foreach ($files as $f) {
    $folder = mageloq_sanitize_folder_path($f['folder_path'] ?? '');
    if (!isset($files_by_folder[$folder])) $files_by_folder[$folder] = [];
    $files_by_folder[$folder][] = $f;
    if ($folder !== '') $folder_options[$folder] = true;

    if (!empty($f['duplicate_of_file_id'])) $duplicate_count++;

    if (file_kind($f['file_path']) === 'image') {
        $image_index_by_id[(int)$f['id']] = count($lightbox_images);
        $lightbox_images[] = [
            'id' => (int)$f['id'],
            'src' => 'uploads/' . $f['file_path'],
            'name' => (string)$f['file_name'],
            'folder' => $folder,
        ];
    }
}
ksort($folder_options, SORT_NATURAL | SORT_FLAG_CASE);
uksort($files_by_folder, static function ($a, $b) {
    if ($a === '') return -1;
    if ($b === '') return 1;
    return strnatcasecmp($a, $b);
});

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$base_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$public_url = $scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base_path . '/gallery.php?token=' . $gallery['gallery_token'];

$page_title = 'ניהול גלריה';
require 'header.php';
?>

<style>
/* Mageloq upload block - DROPZONE-V8-FOLDERS-HASH-LIGHTBOX */
#mageloqUploadSection,
#mageloqUploadSection * {
    box-sizing: border-box !important;
}
#mageloqUploadSection {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    width: 100% !important;
    position: relative !important;
    z-index: 999 !important;
    overflow: visible !important;
}
#mageloqDropzone {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    width: 100% !important;
    min-height: 330px !important;
    margin: 18px 0 !important;
    padding: 30px 18px !important;
    border: 0 !important;
    border-radius: 34px !important;
    background: #ffffff !important;
    color: #0f172a !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: column !important;
    gap: 12px !important;
    text-align: center !important;
    cursor: pointer !important;
    box-shadow: 0 22px 55px rgba(23, 107, 255, 0.16), inset 0 0 0 2px rgba(23, 107, 255, 0.08) !important;
    user-select: none !important;
}
#mageloqDropzone:hover,
#mageloqDropzone.mageloq-drag-over {
    background: #eff6ff !important;
    box-shadow: 0 24px 65px rgba(15, 118, 110, 0.18), inset 0 0 0 3px rgba(15, 118, 110, 0.18) !important;
}
#mageloqDropzone .mageloq-drop-icon {
    display: flex !important;
    width: 90px !important;
    height: 90px !important;
    border-radius: 30px !important;
    align-items: center !important;
    justify-content: center !important;
    background: linear-gradient(135deg, #dbeafe, #cffafe) !important;
    font-size: 46px !important;
    line-height: 1 !important;
}
#mageloqDropzone .mageloq-drop-title {
    margin: 0 !important;
    color: #111827 !important;
    font-size: 32px !important;
    font-weight: 900 !important;
    line-height: 1.15 !important;
}
#mageloqDropzone .mageloq-drop-text {
    margin: 0 !important;
    color: #334155 !important;
    font-size: 17px !important;
    font-weight: 700 !important;
}
#mageloqDropzone .mageloq-drop-note {
    display: inline-block !important;
    margin-top: 4px !important;
    padding: 9px 14px !important;
    border-radius: 999px !important;
    border: 1px solid #bfdbfe !important;
    background: #f8fafc !important;
    color: #475569 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
}
#fileInput {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    width: 100% !important;
    margin-top: 14px !important;
    padding: 14px !important;
    border: 2px solid #cbd5e1 !important;
    border-radius: 16px !important;
    background: #fff !important;
    color: #111827 !important;
}
.mageloq-upload-version {
    display: inline-block !important;
    margin-bottom: 8px !important;
    padding: 6px 10px !important;
    border-radius: 999px !important;
    background: #ecfeff !important;
    border: 1px solid #67e8f9 !important;
    color: #155e75 !important;
    font-size: 12px !important;
    font-weight: 800 !important;
}
.mageloq-folder-target {
    display: grid !important;
    grid-template-columns: 1fr auto !important;
    gap: 10px !important;
    align-items: end !important;
}
.mageloq-upload-queue {
    display: grid !important;
    gap: 10px !important;
    max-height: 280px !important;
    overflow: auto !important;
}
.mageloq-upload-item {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    padding: 11px 13px !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 14px !important;
    background: #fff !important;
}
.mageloq-upload-name {
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    font-weight: 800 !important;
    color: #111827 !important;
}
.mageloq-upload-meta {
    font-size: 12px !important;
    color: #64748b !important;
}
.mageloq-progress-wrap {
    height: 12px !important;
    background: #e2e8f0 !important;
    border-radius: 999px !important;
    overflow: hidden !important;
}
.mageloq-progress-bar {
    height: 100% !important;
    width: 0% !important;
    background: #176bff !important;
    transition: width 0.15s ease !important;
}
.mageloq-file-check {
    position: absolute !important;
    top: 10px !important;
    right: 10px !important;
    z-index: 5 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 7px 10px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,0.94) !important;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12) !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    color: #111827 !important;
    cursor: pointer !important;
}
.mageloq-file-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
    margin-top: 10px !important;
}
.mageloq-file-action-btn {
    appearance: none !important;
    -webkit-appearance: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    min-width: 74px !important;
    min-height: 34px !important;
    padding: 7px 11px !important;
    border-radius: 999px !important;
    border: 1px solid transparent !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    line-height: 1 !important;
    text-decoration: none !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.10) !important;
}
.mageloq-file-action-btn:hover {
    text-decoration: none !important;
    transform: translateY(-1px) !important;
}
.mageloq-action-download {
    background: #176bff !important;
    border-color: #176bff !important;
    color: #fff !important;
}
.mageloq-action-report {
    background: #fff7ed !important;
    border-color: #fdba74 !important;
    color: #9a3412 !important;
}
.mageloq-action-delete {
    background: #fff1f2 !important;
    border-color: #fda4af !important;
    color: #9f1239 !important;
}
.mageloq-action-folder {
    background: #f0fdf4 !important;
    border-color: #86efac !important;
    color: #166534 !important;
}
.file-card {
    position: relative !important;
    overflow: hidden !important;
}
.file-card.is-duplicate {
    box-shadow: inset 0 0 0 2px rgba(249, 115, 22, 0.34) !important;
}
.mageloq-duplicate-badge {
    position: absolute !important;
    top: 10px !important;
    left: 10px !important;
    z-index: 6 !important;
    padding: 7px 10px !important;
    border-radius: 999px !important;
    background: #fff7ed !important;
    border: 1px solid #fdba74 !important;
    color: #9a3412 !important;
    font-size: 12px !important;
    font-weight: 900 !important;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12) !important;
}
.mageloq-folder-toolbar,
.mageloq-folder-header {
    border: 1px solid #e2e8f0 !important;
    background: #f8fafc !important;
    border-radius: 18px !important;
}
.mageloq-folder-header {
    padding: 13px 15px !important;
    margin: 18px 0 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}
.mageloq-folder-name {
    font-weight: 900 !important;
    color: #0f172a !important;
}
.mageloq-lightbox-thumb {
    cursor: zoom-in !important;
}
body.mageloq-lightbox-open {
    overflow: hidden !important;
}
#mageloqLightbox {
    position: fixed !important;
    inset: 0 !important;
    z-index: 99999 !important;
    display: none !important;
    background: rgba(2, 6, 23, 0.96) !important;
    color: #fff !important;
    align-items: center !important;
    justify-content: center !important;
}
#mageloqLightbox.open {
    display: flex !important;
}
.mageloq-lightbox-img {
    max-width: 96vw !important;
    max-height: 88vh !important;
    object-fit: contain !important;
    display: block !important;
    user-select: none !important;
}
.mageloq-lightbox-caption {
    position: fixed !important;
    bottom: 14px !important;
    left: 14px !important;
    right: 14px !important;
    text-align: center !important;
    font-size: 14px !important;
    font-weight: 800 !important;
    color: rgba(255,255,255,0.9) !important;
    pointer-events: none !important;
}
.mageloq-lightbox-close,
.mageloq-lightbox-arrow {
    position: fixed !important;
    z-index: 100001 !important;
    border: 0 !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,0.12) !important;
    color: #fff !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    backdrop-filter: blur(12px) !important;
}
.mageloq-lightbox-close {
    top: 18px !important;
    left: 18px !important;
    width: 46px !important;
    height: 46px !important;
    font-size: 24px !important;
}
.mageloq-lightbox-arrow {
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 54px !important;
    height: 72px !important;
    font-size: 36px !important;
}
.mageloq-lightbox-prev {
    right: 18px !important;
}
.mageloq-lightbox-next {
    left: 18px !important;
}
@media (max-width: 576px) {
    #mageloqDropzone {
        min-height: 240px !important;
        padding: 22px 12px !important;
    }
    #mageloqDropzone .mageloq-drop-title {
        font-size: 24px !important;
    }
    .mageloq-folder-target {
        grid-template-columns: 1fr !important;
    }
    .mageloq-lightbox-arrow {
        width: 44px !important;
        height: 60px !important;
        font-size: 30px !important;
    }
}
</style>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="section-title mb-1"><?= h($gallery['gallery_name']) ?></h1>
        <p class="muted mb-0">ניהול קבצים, תיקיות, קישור לקוח והגדרות גלריה.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="gallery.php?token=<?= h($gallery['gallery_token']) ?>" target="_blank" class="btn btn-success">צפה כלקוח</a>
        <a href="dashboard.php" class="btn btn-outline-secondary">חזרה</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card p-4" id="mageloqUploadSection" style="display:block !important; visibility:visible !important; opacity:1 !important; overflow:visible !important;">
            <span class="mageloq-upload-version">DROPZONE-V8-FOLDERS-HASH-LIGHTBOX</span>
            <h3 class="fw-bold mb-2">העלאת קבצים</h3>
            <p class="muted mb-3">אפשר לגרור תמונות או תיקיות לתוך האזור הגדול. כפולים לפי Hash יעלו אבל יסומנו ככפולים.</p>

            <form method="POST" enctype="multipart/form-data" id="uploadForm" style="display:block !important; visibility:visible !important; opacity:1 !important;">
                <?= csrf_field() ?>
                <input type="hidden" name="native_upload" value="1">

                <div class="mageloq-folder-target mb-3">
                    <div>
                        <label class="form-label fw-bold" for="uploadFolderInput">תיקיית יעד בתוך הגלריה</label>
                        <input type="text" name="upload_folder" id="uploadFolderInput" class="form-control" list="folderList" placeholder="ריק = ללא תיקייה / לדוגמה: חתונה/משפחה">
                        <datalist id="folderList">
                            <?php foreach (array_keys($folder_options) as $folder): ?>
                                <option value="<?= h($folder) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="small muted mt-1">אם מעלים תיקייה מהמחשב, המבנה שלה יישמר מתחת לתיקיית היעד.</div>
                    </div>
                    <button type="button" class="btn btn-outline-primary" id="pickFolderBtn">📁 בחר תיקייה מהמחשב</button>
                </div>

                <input type="file" id="folderInput" multiple webkitdirectory directory style="display:none !important;">

                <div
                    id="mageloqDropzone"
                    tabindex="0"
                    role="button"
                    aria-label="אזור גרירה והשלכה להוספת תמונות וקבצים"
                    style="display:flex !important; visibility:visible !important; opacity:1 !important; min-height:330px !important; width:100% !important; border:0 !important; border-radius:34px !important; background:#fff !important; color:#0f172a !important; align-items:center !important; justify-content:center !important; flex-direction:column !important; gap:12px !important; padding:30px 18px !important; cursor:pointer !important; text-align:center !important; box-shadow:0 22px 55px rgba(23,107,255,0.16), inset 0 0 0 2px rgba(23,107,255,0.08) !important;"
                >
                    <div class="mageloq-drop-icon">📤</div>
                    <p class="mageloq-drop-title">אזור גרירה והשלכה גדול</p>
                    <p class="mageloq-drop-text">גרור לכאן תמונות / תיקיות / RAW / וידאו / ZIP</p>
                    <p class="mageloq-drop-text">או לחץ על האזור כדי לבחור קבצים</p>
                    <span class="mageloq-drop-note">הקבצים רק מתווספים לרשימה. הם לא עולים אוטומטית.</span>
                </div>

                <label class="form-label fw-bold" for="fileInput">בחירת קבצים ידנית — גם זה מוסיף לרשימה:</label>
                <input
                    type="file"
                    name="files[]"
                    id="fileInput"
                    multiple
                    accept="image/*,video/*,.raw,.cr2,.cr3,.nef,.arw,.dng,.raf,.orf,.rw2,.pef,.srw,.zip,.rar,.7z,.pdf"
                >

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                    <div class="fw-bold" id="queueTitle">נבחרו 0 קבצים</div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="addMoreBtn">הוסף עוד</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="clearQueueBtn" disabled>נקה רשימה</button>
                    </div>
                </div>

                <div id="queueList" class="mageloq-upload-queue mt-3"></div>

                <div class="mageloq-progress-wrap mt-3 d-none" id="progressWrap">
                    <div class="mageloq-progress-bar" id="progressBar"></div>
                </div>
                <div id="uploadStatus" class="small muted mt-2"></div>

                <button type="submit" class="btn btn-primary w-100 mt-3" id="uploadBtn" disabled>
                    📤 העלה קבצים שנבחרו
                </button>

                <noscript>
                    <div class="alert alert-warning mt-3 mb-0">
                        JavaScript כבוי. במצב כזה PHP עלול להגביל העלאה ל־20 קבצים לפי max_file_uploads. כשה-JavaScript פעיל הקבצים נשלחים אחד-אחד ולכן אפשר להעלות יותר מ־20.
                    </div>
                </noscript>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-4 mb-4">
            <h4 class="fw-bold">קישור ללקוח</h4>
            <input class="form-control copy-input mb-2" readonly value="<?= h($public_url) ?>" onclick="this.select(); navigator.clipboard?.writeText(this.value); document.getElementById('copyNote').innerText='הקישור הועתק';">
            <div id="copyNote" class="small text-success"></div>
        </div>
        <div class="card p-4">
            <h4 class="fw-bold">הגדרות</h4>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="update_gallery" value="1">
                <label class="form-label">שם גלריה</label>
                <input type="text" name="name" class="form-control mb-3" value="<?= h($gallery['gallery_name']) ?>" required>
                <label class="form-label">סיסמה חדשה</label>
                <input type="password" name="password" class="form-control mb-2" placeholder="השאר ריק ללא שינוי">
                <label class="form-check mb-3">
                    <input type="checkbox" name="clear_password" value="1" class="form-check-input"> הסר סיסמה קיימת
                </label>
                <button class="btn btn-primary w-100">שמור הגדרות</button>
            </form>
        </div>
    </div>
</div>

<div class="card p-4">
    <form method="POST" action="manage_gallery.php?id=<?= (int)$gallery_id ?>" id="zipForm">
        <?= csrf_field() ?>
        <input type="hidden" name="gallery_id" value="<?= (int)$gallery_id ?>">
        <input type="hidden" name="zip_selected" value="1">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center flex-wrap gap-2">
                <h3 class="fw-bold mb-0">קבצים בגלריה</h3>
                <span class="badge badge-soft"><?= count($files) ?> קבצים</span>
                <span class="badge bg-warning text-dark">כפולים: <?= (int)$duplicate_count ?></span>
                <span class="small muted" id="selectedZipCount">נבחרו 0 ל-ZIP</span>
            </div>
            <?php if ($files): ?>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllZipBtn">בחר הכל</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="clearZipBtn">נקה בחירה</button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="moveSelectedFolderBtn">📁 העבר מסומנים לתיקייה</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="downloadZipBtn" disabled>⬇️ הורד ZIP מסומנים</button>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($files): ?>
            <div class="mageloq-folder-toolbar p-3 mb-3 d-flex align-items-center flex-wrap gap-2">
                <label class="fw-bold" for="folderFilter">סינון תיקייה:</label>
                <select class="form-select form-select-sm" id="folderFilter" style="width:auto;min-width:220px;">
                    <option value="__all__">כל התיקיות</option>
                    <option value="">ללא תיקייה</option>
                    <?php foreach (array_keys($folder_options) as $folder): ?>
                        <option value="<?= h($folder) ?>"><?= h($folder) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="small muted">ZIP מסומנים שומר את מבנה התיקיות.</span>
            </div>
        <?php endif; ?>

        <?php if (!$files): ?>
            <p class="muted mb-0">אין עדיין קבצים בגלריה.</p>
        <?php else: ?>
            <?php foreach ($files_by_folder as $folder => $folder_files): ?>
                <section class="mageloq-folder-group" data-folder-value="<?= h($folder) ?>">
                    <div class="mageloq-folder-header">
                        <div class="mageloq-folder-name">📁 <?= $folder === '' ? 'ללא תיקייה' : h($folder) ?></div>
                        <div class="small muted"><?= count($folder_files) ?> קבצים</div>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($folder_files as $f):
                            $kind = file_kind($f['file_path']);
                            $is_duplicate = !empty($f['duplicate_of_file_id']);
                            $image_index = $image_index_by_id[(int)$f['id']] ?? null;
                        ?>
                            <div class="col-6 col-md-4 col-lg-3" id="f-<?= (int)$f['id'] ?>">
                                <div class="card file-card h-100 <?= $is_duplicate ? 'is-duplicate' : '' ?>">
                                    <label class="mageloq-file-check" title="בחר להורדת ZIP">
                                        <input type="checkbox" name="files[]" value="<?= (int)$f['id'] ?>" class="form-check-input file-select-checkbox">
                                        <span>ZIP</span>
                                    </label>

                                    <?php if ($is_duplicate): ?>
                                        <span class="mageloq-duplicate-badge" title="כפול של: <?= h($f['duplicate_original_name'] ?? ('#' . (int)$f['duplicate_of_file_id'])) ?>">🔁 כפול</span>
                                    <?php endif; ?>

                                    <?php if ($kind === 'image'): ?>
                                        <img src="uploads/<?= h($f['file_path']) ?>" class="gallery-img mageloq-lightbox-thumb" alt="<?= h($f['file_name']) ?>" data-lightbox-index="<?= (int)$image_index ?>">
                                    <?php else: ?>
                                        <div class="file-thumb"><i class="fa-regular fa-file-lines"></i></div>
                                    <?php endif; ?>
                                    <div class="card-body">
                                        <div class="fw-bold text-truncate" title="<?= h($f['file_name']) ?>"><?= h($f['file_name']) ?></div>
                                        <div class="small muted mb-2">
                                            <?= h(format_bytes((int)$f['file_size'])) ?> · <?= h(strtoupper(pathinfo($f['file_path'], PATHINFO_EXTENSION))) ?>
                                            <?php if (($f['folder_path'] ?? '') !== ''): ?>
                                                <br>📁 <?= h($f['folder_path']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($f['file_hash'])): ?>
                                                <br><span title="<?= h($f['file_hash']) ?>">Hash: <?= h(substr((string)$f['file_hash'], 0, 12)) ?>...</span>
                                            <?php endif; ?>
                                            <?php if ($is_duplicate): ?>
                                                <br><strong class="text-warning">כפול לפי Hash של: <?= h($f['duplicate_original_name'] ?? ('#' . (int)$f['duplicate_of_file_id'])) ?></strong>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mageloq-file-actions">
                                            <a role="button" class="mageloq-file-action-btn mageloq-action-download" href="manage_gallery.php?id=<?= (int)$gallery_id ?>&download_file=<?= (int)$f['id'] ?>">⬇️ הורדה</a>
                                            <button type="button" class="mageloq-file-action-btn mageloq-action-folder" onclick="moveFilesToFolder([<?= (int)$f['id'] ?>])">📁 תיקייה</button>
                                            <button type="button" class="mageloq-file-action-btn mageloq-action-report" onclick="reportFile(<?= (int)$f['id'] ?>, <?= json_encode($f['file_name'], JSON_UNESCAPED_UNICODE) ?>)">🚩 דיווח</button>
                                            <button type="button" class="mageloq-file-action-btn mageloq-action-delete" onclick="deleteFile(<?= (int)$f['id'] ?>)">🗑️ מחק</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </form>
</div>

<div id="mageloqLightbox" aria-hidden="true">
    <button type="button" class="mageloq-lightbox-close" id="lightboxCloseBtn" aria-label="סגור">×</button>
    <button type="button" class="mageloq-lightbox-arrow mageloq-lightbox-prev" id="lightboxPrevBtn" aria-label="תמונה קודמת">›</button>
    <img src="" alt="" class="mageloq-lightbox-img" id="lightboxImg">
    <button type="button" class="mageloq-lightbox-arrow mageloq-lightbox-next" id="lightboxNextBtn" aria-label="תמונה הבאה">‹</button>
    <div class="mageloq-lightbox-caption" id="lightboxCaption"></div>
</div>

<script>
const csrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
const galleryId = <?= (int)$gallery_id ?>;
const lightboxImages = <?= json_encode($lightbox_images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const uploadForm = document.getElementById('uploadForm');
const fileInput = document.getElementById('fileInput');
const folderInput = document.getElementById('folderInput');
const pickFolderBtn = document.getElementById('pickFolderBtn');
const uploadFolderInput = document.getElementById('uploadFolderInput');
const dropZone = document.getElementById('mageloqDropzone');
const addMoreBtn = document.getElementById('addMoreBtn');
const clearQueueBtn = document.getElementById('clearQueueBtn');
const queueTitle = document.getElementById('queueTitle');
const queueList = document.getElementById('queueList');
const uploadBtn = document.getElementById('uploadBtn');
const uploadStatus = document.getElementById('uploadStatus');
const progressWrap = document.getElementById('progressWrap');
const progressBar = document.getElementById('progressBar');
const zipForm = document.getElementById('zipForm');
const selectedZipCount = document.getElementById('selectedZipCount');
const downloadZipBtn = document.getElementById('downloadZipBtn');
const selectAllZipBtn = document.getElementById('selectAllZipBtn');
const clearZipBtn = document.getElementById('clearZipBtn');
const moveSelectedFolderBtn = document.getElementById('moveSelectedFolderBtn');
const folderFilter = document.getElementById('folderFilter');
const lightbox = document.getElementById('mageloqLightbox');
const lightboxImg = document.getElementById('lightboxImg');
const lightboxCaption = document.getElementById('lightboxCaption');
const lightboxCloseBtn = document.getElementById('lightboxCloseBtn');
const lightboxPrevBtn = document.getElementById('lightboxPrevBtn');
const lightboxNextBtn = document.getElementById('lightboxNextBtn');

let queuedFiles = [];
let isUploading = false;
let lightboxIndex = 0;
let touchStartX = 0;
let touchStartY = 0;

function formatBytes(bytes) {
    bytes = Number(bytes || 0);
    if (bytes < 1024) return bytes + ' B';
    const units = ['KB', 'MB', 'GB', 'TB'];
    let size = bytes / 1024;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    return size.toFixed(size >= 10 ? 0 : 1) + ' ' + units[unitIndex];
}

function normalizeRelativePath(path) {
    return String(path || '').replace(/\\/g, '/').replace(/^\/+/, '').replace(/\/+/g, '/');
}

function addQueueItems(items) {
    if (isUploading) return;

    const list = Array.from(items || []).filter(item => item && item.file);
    if (!list.length) return;

    queuedFiles.push(...list.map(item => ({
        file: item.file,
        relativePath: normalizeRelativePath(item.relativePath || item.file.webkitRelativePath || item.file.name || '')
    })));

    renderQueue();
    uploadStatus.textContent = 'נוספו ' + list.length + ' קבצים לרשימת ההעלאה. סך הכל: ' + queuedFiles.length + '.';
}

function addFiles(files) {
    const items = Array.from(files || []).map(file => ({
        file,
        relativePath: file.webkitRelativePath || file.name || ''
    }));
    addQueueItems(items);
}

function renderQueue() {
    queueTitle.textContent = 'נבחרו ' + queuedFiles.length + ' קבצים';
    uploadBtn.disabled = queuedFiles.length === 0 || isUploading;
    clearQueueBtn.disabled = queuedFiles.length === 0 || isUploading;
    addMoreBtn.disabled = isUploading;
    pickFolderBtn.disabled = isUploading;
    fileInput.disabled = isUploading;
    folderInput.disabled = isUploading;

    queueList.innerHTML = '';

    queuedFiles.forEach((item, index) => {
        const file = item.file;
        const itemDiv = document.createElement('div');
        itemDiv.className = 'mageloq-upload-item';

        const info = document.createElement('div');
        info.style.minWidth = '0';
        info.style.flexGrow = '1';

        const name = document.createElement('div');
        name.className = 'mageloq-upload-name';
        name.title = file.name;
        name.textContent = file.name;

        const meta = document.createElement('div');
        meta.className = 'mageloq-upload-meta';
        const rel = item.relativePath && item.relativePath !== file.name ? ' · נתיב: ' + item.relativePath : '';
        meta.textContent = formatBytes(file.size) + rel;

        info.appendChild(name);
        info.appendChild(meta);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-outline-danger';
        remove.textContent = 'הסר';
        remove.disabled = isUploading;
        remove.addEventListener('click', () => {
            queuedFiles.splice(index, 1);
            renderQueue();
        });

        itemDiv.appendChild(info);
        itemDiv.appendChild(remove);
        queueList.appendChild(itemDiv);
    });
}

function openFilePicker() {
    if (!isUploading) fileInput.click();
}

function openFolderPicker() {
    if (!isUploading) folderInput.click();
}

function setProgress(done, total) {
    const percent = total > 0 ? Math.round((done / total) * 100) : 0;
    progressWrap.classList.remove('d-none');
    progressBar.style.width = percent + '%';
}

async function uploadOneFile(item) {
    const file = item.file;
    const fd = new FormData();
    fd.append('ajax_upload', '1');
    fd.append('file', file, file.name);
    fd.append('relative_path', item.relativePath || file.name || '');
    fd.append('upload_folder', uploadFolderInput?.value || '');
    fd.append('csrf_token', csrf);

    const response = await fetch('manage_gallery.php?id=' + encodeURIComponent(galleryId), {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    });

    const text = await response.text();
    let data = {};
    try {
        data = text ? JSON.parse(text) : {};
    } catch (e) {
        data = {error: text || 'תגובה לא תקינה מהשרת'};
    }

    if (!response.ok || data.error) {
        throw new Error(data.error || 'שגיאה בהעלאת ' + file.name);
    }

    return data;
}

async function uploadQueue(event) {
    event.preventDefault();

    if (isUploading || queuedFiles.length === 0) return;

    isUploading = true;
    renderQueue();
    setProgress(0, queuedFiles.length);

    let completed = 0;
    let uploaded = 0;
    let duplicates = 0;
    const errors = [];
    const filesToUpload = [...queuedFiles];

    for (const item of filesToUpload) {
        const file = item.file;
        uploadStatus.textContent = 'מעלה ' + (completed + 1) + ' מתוך ' + filesToUpload.length + ': ' + file.name;

        try {
            const result = await uploadOneFile(item);
            uploaded++;
            if (result.duplicate) duplicates++;
        } catch (err) {
            errors.push(file.name + ' — ' + (err.message || 'שגיאה לא ידועה'));
        }

        completed++;
        queuedFiles.shift();
        renderQueue();
        setProgress(completed, filesToUpload.length);
    }

    isUploading = false;
    renderQueue();

    if (uploaded > 0 && errors.length === 0) {
        uploadStatus.textContent = 'הועלו ' + uploaded + ' קבצים בהצלחה' + (duplicates ? ' — ' + duplicates + ' סומנו ככפולים לפי Hash' : '') + '. מרענן את הגלריה...';
        location.reload();
        return;
    }

    if (uploaded > 0 && errors.length > 0) {
        alert('הועלו ' + uploaded + ' קבצים' + (duplicates ? ' (' + duplicates + ' כפולים)' : '') + ', אבל היו שגיאות:\n' + errors.slice(0, 5).join('\n') + (errors.length > 5 ? '\nועוד...' : ''));
        location.reload();
        return;
    }

    uploadStatus.textContent = 'לא הועלו קבצים.';
    alert(errors.slice(0, 6).join('\n') || 'לא הועלו קבצים.');
}

function readDirectoryEntries(reader) {
    return new Promise((resolve, reject) => {
        const entries = [];
        const readBatch = () => {
            reader.readEntries(batch => {
                if (!batch.length) {
                    resolve(entries);
                    return;
                }
                entries.push(...batch);
                readBatch();
            }, reject);
        };
        readBatch();
    });
}

async function traverseEntry(entry, path = '') {
    if (!entry) return [];

    if (entry.isFile) {
        return new Promise(resolve => {
            entry.file(file => resolve([{file, relativePath: path + file.name}]), () => resolve([]));
        });
    }

    if (entry.isDirectory) {
        try {
            const entries = await readDirectoryEntries(entry.createReader());
            const nested = await Promise.all(entries.map(child => traverseEntry(child, path + entry.name + '/')));
            return nested.flat();
        } catch (e) {
            return [];
        }
    }

    return [];
}

async function collectDroppedItems(dataTransfer) {
    const dtItems = Array.from(dataTransfer.items || []);
    const entries = dtItems.map(item => item.webkitGetAsEntry ? item.webkitGetAsEntry() : null).filter(Boolean);

    if (entries.length) {
        const nested = await Promise.all(entries.map(entry => traverseEntry(entry, '')));
        return nested.flat();
    }

    return Array.from(dataTransfer.files || []).map(file => ({file, relativePath: file.name || ''}));
}

fileInput.addEventListener('change', () => {
    addFiles(fileInput.files);
    fileInput.value = '';
});

folderInput.addEventListener('change', () => {
    addFiles(folderInput.files);
    folderInput.value = '';
});

dropZone.addEventListener('click', (event) => {
    if (event.target !== fileInput) openFilePicker();
});

dropZone.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openFilePicker();
    }
});

addMoreBtn.addEventListener('click', openFilePicker);
pickFolderBtn.addEventListener('click', openFolderPicker);
clearQueueBtn.addEventListener('click', () => {
    if (isUploading) return;
    queuedFiles = [];
    uploadStatus.textContent = 'רשימת ההעלאה נוקתה.';
    progressWrap.classList.add('d-none');
    progressBar.style.width = '0%';
    renderQueue();
});

['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, (event) => {
        event.preventDefault();
        event.stopPropagation();
        dropZone.classList.add('mageloq-drag-over');
    });
});

['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, (event) => {
        event.preventDefault();
        event.stopPropagation();
        dropZone.classList.remove('mageloq-drag-over');
    });
});

dropZone.addEventListener('drop', async (event) => {
    const items = await collectDroppedItems(event.dataTransfer);
    addQueueItems(items);
});

uploadForm.addEventListener('submit', uploadQueue);
renderQueue();

function updateZipSelectionCounter() {
    const boxes = Array.from(document.querySelectorAll('.file-select-checkbox'));
    const checked = boxes.filter(box => box.checked).length;
    if (selectedZipCount) selectedZipCount.textContent = 'נבחרו ' + checked + ' ל-ZIP';
    if (downloadZipBtn) downloadZipBtn.disabled = checked === 0;
    if (moveSelectedFolderBtn) moveSelectedFolderBtn.disabled = checked === 0;
}

document.querySelectorAll('.file-select-checkbox').forEach(box => {
    box.addEventListener('change', updateZipSelectionCounter);
});

selectAllZipBtn?.addEventListener('click', () => {
    document.querySelectorAll('.file-select-checkbox').forEach(box => box.checked = true);
    updateZipSelectionCounter();
});

clearZipBtn?.addEventListener('click', () => {
    document.querySelectorAll('.file-select-checkbox').forEach(box => box.checked = false);
    updateZipSelectionCounter();
});

zipForm?.addEventListener('submit', (event) => {
    const selected = document.querySelectorAll('.file-select-checkbox:checked').length;
    if (selected === 0) {
        event.preventDefault();
        alert('בחר לפחות קובץ אחד להורדה כ-ZIP.');
    }
});

folderFilter?.addEventListener('change', () => {
    const selected = folderFilter.value;
    document.querySelectorAll('.mageloq-folder-group').forEach(group => {
        const folder = group.dataset.folderValue || '';
        group.style.display = (selected === '__all__' || selected === folder) ? '' : 'none';
    });
});

updateZipSelectionCounter();

async function moveFilesToFolder(ids) {
    if (!Array.isArray(ids) || ids.length === 0) return;
    const folder = prompt('לאיזו תיקייה להעביר? השאר ריק כדי להעביר ל"ללא תיקייה". אפשר גם נתיב כמו: חתונה/משפחה');
    if (folder === null) return;

    const fd = new FormData();
    fd.append('ajax_move_folder', '1');
    ids.forEach(id => fd.append('file_ids[]', id));
    fd.append('folder_path', folder.trim());
    fd.append('csrf_token', csrf);

    const r = await fetch('manage_gallery.php?id=' + encodeURIComponent(galleryId), {method: 'POST', body: fd, credentials: 'same-origin'});
    const res = await r.json().catch(() => ({}));

    if (res.status === 'success') {
        location.reload();
    } else {
        alert(res.error || 'שגיאה בהעברת הקבצים לתיקייה');
    }
}

moveSelectedFolderBtn?.addEventListener('click', () => {
    const ids = Array.from(document.querySelectorAll('.file-select-checkbox:checked')).map(box => Number(box.value)).filter(Boolean);
    if (!ids.length) {
        alert('בחר קבצים להעברה לתיקייה.');
        return;
    }
    moveFilesToFolder(ids);
});

async function deleteFile(id) {
    if (!confirm('למחוק את הקובץ לצמיתות?')) return;
    const fd = new FormData();
    fd.append('file_id', id);
    fd.append('csrf_token', csrf);
    const r = await fetch('delete_file.php', {method: 'POST', body: fd, credentials: 'same-origin'});
    const res = await r.json().catch(() => ({}));
    if (res.status === 'success') {
        document.getElementById('f-' + id)?.remove();
        updateZipSelectionCounter();
    } else {
        alert(res.error || 'שגיאה במחיקה');
    }
}

async function reportFile(id, fileName) {
    const reason = prompt('מה הבעיה בקובץ "' + fileName + '"?');
    if (!reason || !reason.trim()) return;

    const fd = new FormData();
    fd.append('file_id', id);
    fd.append('reason', reason.trim());
    fd.append('csrf_token', csrf);

    const r = await fetch('report_handler.php', {method: 'POST', body: fd, credentials: 'same-origin'});
    const res = await r.json().catch(() => ({}));

    if (res.status === 'success') {
        alert('הדיווח נשלח בהצלחה.');
    } else {
        alert(res.error || 'שגיאה בשליחת הדיווח');
    }
}

function openLightbox(index) {
    if (!lightboxImages.length) return;
    lightboxIndex = ((Number(index) || 0) + lightboxImages.length) % lightboxImages.length;
    const item = lightboxImages[lightboxIndex];
    lightboxImg.src = item.src;
    lightboxImg.alt = item.name || '';
    lightboxCaption.textContent = (lightboxIndex + 1) + ' / ' + lightboxImages.length + ' — ' + (item.folder ? item.folder + ' / ' : '') + (item.name || '');
    lightbox.classList.add('open');
    lightbox.setAttribute('aria-hidden', 'false');
    document.body.classList.add('mageloq-lightbox-open');
}

function closeLightbox() {
    lightbox.classList.remove('open');
    lightbox.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('mageloq-lightbox-open');
    lightboxImg.src = '';
}

function showPrevImage() {
    openLightbox(lightboxIndex - 1);
}

function showNextImage() {
    openLightbox(lightboxIndex + 1);
}

document.querySelectorAll('.mageloq-lightbox-thumb').forEach(img => {
    img.addEventListener('click', () => openLightbox(Number(img.dataset.lightboxIndex || 0)));
});

lightboxCloseBtn?.addEventListener('click', closeLightbox);
lightboxPrevBtn?.addEventListener('click', showPrevImage);
lightboxNextBtn?.addEventListener('click', showNextImage);
lightbox?.addEventListener('click', (event) => {
    if (event.target === lightbox) closeLightbox();
});

lightbox?.addEventListener('touchstart', (event) => {
    const touch = event.changedTouches[0];
    touchStartX = touch.clientX;
    touchStartY = touch.clientY;
}, {passive: true});

lightbox?.addEventListener('touchend', (event) => {
    const touch = event.changedTouches[0];
    const dx = touch.clientX - touchStartX;
    const dy = touch.clientY - touchStartY;
    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) {
        if (dx > 0) showPrevImage();
        else showNextImage();
    }
}, {passive: true});

document.addEventListener('keydown', (event) => {
    if (!lightbox?.classList.contains('open')) return;
    if (event.key === 'Escape') closeLightbox();
    if (event.key === 'ArrowRight') showPrevImage();
    if (event.key === 'ArrowLeft') showNextImage();
});
</script>
<?php require 'footer.php'; ?>
