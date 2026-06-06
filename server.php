<?php
// Mageloq - קובץ חיבור והגדרות כלליות
// עדכן כאן את פרטי מסד הנתונים אם צריך.
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$db   = 'mageloq';
$user = 'root';
$pass = '';

$site_name  = 'Mageloq';
$site_email = 'noreply@mageloq.local';
$admin_email = 'admin@mageloq.local';
$upload_dir = __DIR__ . '/uploads';
$upload_url = 'uploads';
$max_file_size = 1024 * 1024 * 1024; // 1GB לקובץ, בכפוף להגדרות PHP בשרת

$allowed_extensions = [
    'jpg','jpeg','png','gif','webp','avif','bmp','tif','tiff','heic','heif',
    'raw','cr2','cr3','nef','arw','dng','raf','orf','rw2','pef','srw',
    'mp4','mov','avi','mkv','zip','rar','7z','pdf'
];

$image_extensions = ['jpg','jpeg','png','gif','webp','avif','bmp','tif','tiff','heic','heif'];
$raw_extensions   = ['raw','cr2','cr3','nef','arw','dng','raf','orf','rw2','pef','srw'];
$video_extensions = ['mp4','mov','avi','mkv'];

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

try {
    $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('שגיאת חיבור למסד הנתונים: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function is_admin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('גישה אסורה. למנהלים בלבד.');
    }
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            die('פג תוקף הטופס. רענן את העמוד ונסה שוב.');
        }
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_html(): string {
    if (empty($_SESSION['flash'])) return '';
    $html = '';
    foreach ($_SESSION['flash'] as $item) {
        $type = preg_replace('/[^a-z]/', '', $item['type']);
        $html .= '<div class="alert alert-' . $type . ' shadow-sm">' . h($item['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

function generate_unique_name(string $ext): string {
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext));
    return bin2hex(random_bytes(18)) . '.' . $ext;
}

function safe_original_name(string $name): string {
    $name = trim($name);
    $name = str_replace(["\0", '/', '\\'], '-', $name);
    return mb_substr($name, 0, 180, 'UTF-8') ?: 'file';
}

function format_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = max($bytes, 0);
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return ($i === 0 ? (string)$value : number_format($value, 1)) . ' ' . $units[$i];
}

function send_system_mail(string $to, string $subject, string $message): bool {
    global $site_email, $site_name;
    $headers  = "From: {$site_name} <{$site_email}>\r\n";
    $headers .= "Reply-To: {$site_email}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $message, $headers);
}

function get_gallery_for_owner(PDO $pdo, int $gallery_id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ? LIMIT 1');
    $stmt->execute([$gallery_id]);
    $gallery = $stmt->fetch();
    if (!$gallery) return null;
    if (!is_admin() && (int)$gallery['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) return null;
    return $gallery;
}

function user_can_access_gallery(array $gallery): bool {
    if (is_admin()) return true;
    if (is_logged_in() && (int)$gallery['user_id'] === (int)$_SESSION['user_id']) return true;
    if (empty($gallery['password_hash'])) return true;
    return !empty($_SESSION['gallery_auth_' . $gallery['id']]);
}

function file_kind(string $filename): string {
    global $image_extensions, $raw_extensions, $video_extensions;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, $image_extensions, true)) return 'image';
    if (in_array($ext, $raw_extensions, true)) return 'raw';
    if (in_array($ext, $video_extensions, true)) return 'video';
    return 'other';
}
?>