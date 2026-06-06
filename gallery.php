<?php
require 'server.php';

$token = trim($_GET['token'] ?? '');
$stmt = $pdo->prepare('SELECT g.*, u.username AS owner_name FROM galleries g LEFT JOIN users u ON u.id = g.user_id WHERE g.gallery_token = ? LIMIT 1');
$stmt->execute([$token]);
$gallery = $stmt->fetch();
if (!$gallery) {
    http_response_code(404);
    die('<h2 style="text-align:center;margin-top:80px;font-family:Arial">גלריה לא קיימת</h2>');
}

$key = 'gallery_auth_' . $gallery['id'];
if (!empty($gallery['password_hash']) && empty($_SESSION[$key]) && !(is_logged_in() && ((int)$gallery['user_id'] === (int)$_SESSION['user_id'] || is_admin()))) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
        verify_csrf();
        if (password_verify((string)$_POST['pass'], $gallery['password_hash'])) {
            $_SESSION[$key] = true;
            redirect('gallery.php?token=' . urlencode($token));
        }
        flash('danger', 'סיסמה שגויה.');
    }
    $page_title = 'כניסה לגלריה';
    require 'header.php';
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card p-4 p-md-5 text-center">
                <div class="display-4 mb-3">🔒</div>
                <h1 class="h3 fw-bold mb-2">גלריה חסויה</h1>
                <p class="muted">הזן סיסמה כדי לצפות בגלריה.</p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="password" name="pass" class="form-control mb-3" placeholder="סיסמה" autofocus required>
                    <button class="btn btn-gradient w-100">היכנס</button>
                </form>
            </div>
        </div>
    </div>
    <?php require 'footer.php'; exit; ?>
    <?php
}

$stmt = $pdo->prepare('SELECT * FROM files WHERE gallery_id = ? ORDER BY created_at ASC, id ASC');
$stmt->execute([$gallery['id']]);
$all_files = $stmt->fetchAll();
$images = $raws = $others = [];
foreach ($all_files as $f) {
    $kind = file_kind($f['file_path']);
    if ($kind === 'image') $images[] = $f;
    elseif ($kind === 'raw') $raws[] = $f;
    else $others[] = $f;
}
$can_delete = is_logged_in() && (is_admin() || (int)$gallery['user_id'] === (int)$_SESSION['user_id']);

$page_title = $gallery['gallery_name'];
require 'header.php';
?>
<div class="hero mb-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <h1 class="fw-bold mb-1"><?= h($gallery['gallery_name']) ?></h1>
            <div class="text-white-50">צלם/ת: <?= h($gallery['owner_name'] ?? 'Mageloq') ?> · <?= count($all_files) ?> קבצים</div>
        </div>
        <img src="images/logo.png" alt="Mageloq" style="width:190px;max-width:55%;">
    </div>
</div>

<form action="download_zip.php" method="POST" id="downloadForm">
    <?= csrf_field() ?>
    <input type="hidden" name="gallery_id" value="<?= (int)$gallery['id'] ?>">
    <div class="gallery-toolbar d-flex flex-wrap justify-content-between gap-2 mb-4">
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="toggleAll(true)">בחר הכל</button>
            <button type="button" class="btn btn-outline-secondary" onclick="toggleAll(false)">נקה בחירה</button>
        </div>
        <button type="submit" class="btn btn-success"><i class="fa-solid fa-file-zipper"></i> הורד ZIP נבחרים</button>
    </div>

    <?php if (!$all_files): ?>
        <div class="card p-5 text-center"><h3 class="fw-bold">הגלריה עדיין ריקה</h3><p class="muted mb-0">הצלם עדיין לא העלה קבצים.</p></div>
    <?php endif; ?>

    <?php if ($images): ?>
        <h3 class="fw-bold mb-3">תמונות</h3>
        <div class="row g-4 mb-5">
            <?php foreach ($images as $f): ?>
                <div class="col-6 col-md-4 col-lg-3" id="f-<?= (int)$f['id'] ?>">
                    <div class="card h-100 file-card">
                        <img src="uploads/<?= h($f['file_path']) ?>" class="gallery-img" alt="<?= h($f['file_name']) ?>" onclick="openPreview('uploads/<?= h($f['file_path']) ?>')">
                        <div class="card-body">
                            <label class="form-check d-flex align-items-start gap-2">
                                <input class="form-check-input cb" type="checkbox" name="files[]" value="<?= (int)$f['id'] ?>">
                                <span class="form-check-label text-truncate" title="<?= h($f['file_name']) ?>"><?= h($f['file_name']) ?></span>
                            </label>
                            <div class="small muted mb-2"><?= h(format_bytes((int)$f['file_size'])) ?></div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-warning flex-fill" onclick="reportFile(<?= (int)$f['id'] ?>)">דווח</button>
                                <?php if ($can_delete): ?><button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteFile(<?= (int)$f['id'] ?>)">מחק</button><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($raws): ?>
        <h3 class="fw-bold mb-3">קבצי מקור RAW</h3>
        <div class="row g-3 mb-5">
            <?php foreach ($raws as $f): ?>
                <div class="col-md-6 col-lg-4" id="f-<?= (int)$f['id'] ?>">
                    <div class="card raw-card p-3">
                        <label class="form-check d-flex gap-2 align-items-start">
                            <input class="form-check-input cb" type="checkbox" name="files[]" value="<?= (int)$f['id'] ?>">
                            <span><strong><?= h($f['file_name']) ?></strong><br><span class="small muted"><?= h(format_bytes((int)$f['file_size'])) ?></span></span>
                        </label>
                        <?php if ($can_delete): ?><button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="deleteFile(<?= (int)$f['id'] ?>)">מחק</button><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($others): ?>
        <h3 class="fw-bold mb-3">קבצים נוספים</h3>
        <div class="row g-3 mb-5">
            <?php foreach ($others as $f): ?>
                <div class="col-md-6 col-lg-4" id="f-<?= (int)$f['id'] ?>">
                    <div class="card raw-card p-3">
                        <label class="form-check d-flex gap-2 align-items-start">
                            <input class="form-check-input cb" type="checkbox" name="files[]" value="<?= (int)$f['id'] ?>">
                            <span><strong><?= h($f['file_name']) ?></strong><br><span class="small muted"><?= h(format_bytes((int)$f['file_size'])) ?></span></span>
                        </label>
                        <?php if ($can_delete): ?><button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="deleteFile(<?= (int)$f['id'] ?>)">מחק</button><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</form>

<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark">
            <button type="button" class="btn-close btn-close-white ms-auto m-3" data-bs-dismiss="modal" aria-label="סגור"></button>
            <img id="previewImg" src="" alt="preview" style="max-height:82vh;object-fit:contain;">
        </div>
    </div>
</div>

<script>
const csrf = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
function toggleAll(state) { document.querySelectorAll('.cb').forEach(c => c.checked = state); }
function openPreview(src) { document.getElementById('previewImg').src = src; new bootstrap.Modal(document.getElementById('previewModal')).show(); }
async function reportFile(id) {
    const reason = prompt('מה הבעיה בקובץ / בתמונה?');
    if (!reason) return;
    const fd = new FormData();
    fd.append('file_id', id);
    fd.append('reason', reason);
    fd.append('csrf_token', csrf);
    const r = await fetch('report_handler.php', {method: 'POST', body: fd});
    const res = await r.json().catch(() => ({}));
    alert(res.status === 'success' ? 'הדיווח נשלח.' : (res.error || 'שגיאה בשליחת הדיווח'));
}
async function deleteFile(id) {
    if (!confirm('למחוק את הקובץ לצמיתות?')) return;
    const fd = new FormData();
    fd.append('file_id', id);
    fd.append('csrf_token', csrf);
    const r = await fetch('delete_file.php', {method: 'POST', body: fd});
    const res = await r.json().catch(() => ({}));
    if (res.status === 'success') document.getElementById('f-' + id)?.remove();
    else alert(res.error || 'שגיאה במחיקה');
}
</script>
<?php require 'footer.php'; ?>
