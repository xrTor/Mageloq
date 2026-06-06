<?php
require 'server.php';
if (is_logged_in()) redirect('dashboard.php');
$page_title = 'אתר גלריות לצלמים';
require 'header.php';
$packages = $pdo->query('SELECT * FROM packages ORDER BY price ASC, id ASC')->fetchAll();
?>
<section class="hero mb-5">
    <div class="row align-items-center g-5">
        <div class="col-lg-7">
            <span class="badge rounded-pill text-bg-light mb-3 px-3 py-2">Mageloq — אתר גלריות לצלמים</span>
            <h1 class="hero-title mb-3">מעלים גלריה. שולחים קישור. הלקוח בוחר ומוריד.</h1>
            <p class="hero-subtitle mb-4">מערכת נקייה לצלמים: גלריות פרטיות, סיסמה ללקוח, בחירת תמונות, הורדת ZIP, תמיכה בקבצי RAW ודיווח על תמונות שדורשות טיפול.</p>
            <div class="d-flex flex-wrap gap-2">
                <a href="register.php" class="btn btn-gradient btn-lg px-4">התחל עכשיו</a>
                <a href="login.php" class="btn btn-outline-light btn-lg px-4">כבר יש לי חשבון</a>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="glass-card">
                <img src="images/logo.png" alt="Mageloq" class="mb-4" style="max-width:280px;">
                <div class="row g-3">
                    <div class="col-6"><div class="glass-card text-center"><div class="h2 fw-bold mb-0">ZIP</div><small>הורדה מרוכזת</small></div></div>
                    <div class="col-6"><div class="glass-card text-center"><div class="h2 fw-bold mb-0">RAW</div><small>קבצי מקור</small></div></div>
                    <div class="col-6"><div class="glass-card text-center"><div class="h2 fw-bold mb-0">🔒</div><small>גלריה חסויה</small></div></div>
                    <div class="col-6"><div class="glass-card text-center"><div class="h2 fw-bold mb-0">⚡</div><small>ניהול מהיר</small></div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="features" class="mb-5">
    <h2 class="section-title mb-4">מה האתר כולל?</h2>
    <div class="row g-4">
        <div class="col-md-6 col-lg-3"><div class="feature-card"><div class="feature-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div><h5 class="fw-bold">העלאת תמונות</h5><p class="muted mb-0">העלאה נוחה דרך ממשק Uppy, כולל קבצי RAW וקבצים גדולים לפי הגדרות השרת.</p></div></div>
        <div class="col-md-6 col-lg-3"><div class="feature-card"><div class="feature-icon"><i class="fa-solid fa-lock"></i></div><h5 class="fw-bold">גלריות פרטיות</h5><p class="muted mb-0">כל גלריה מקבלת קישור ייחודי ואפשר להגן עליה בסיסמה ללקוח.</p></div></div>
        <div class="col-md-6 col-lg-3"><div class="feature-card"><div class="feature-icon"><i class="fa-solid fa-file-zipper"></i></div><h5 class="fw-bold">הורדת ZIP</h5><p class="muted mb-0">הלקוח מסמן תמונות ומוריד אותן יחד בקובץ ZIP מסודר.</p></div></div>
        <div class="col-md-6 col-lg-3"><div class="feature-card"><div class="feature-icon"><i class="fa-solid fa-flag"></i></div><h5 class="fw-bold">דיווחים</h5><p class="muted mb-0">לקוחות יכולים לדווח על תמונה בעייתית, והצלם רואה זאת בלוח הבקרה.</p></div></div>
    </div>
</section>

<section id="pricing" class="mb-5">
    <div class="d-flex justify-content-between align-items-end gap-3 flex-wrap mb-4">
        <div>
            <h2 class="section-title mb-1">מסלולים</h2>
            <p class="muted mb-0">אפשר לערוך את החבילות דרך אזור הניהול.</p>
        </div>
        <a href="register.php" class="btn btn-gradient">פתיחת חשבון</a>
    </div>
    <div class="row g-4">
        <?php foreach ($packages as $p): ?>
            <div class="col-md-4">
                <div class="card h-100 p-4">
                    <h4 class="fw-bold"><?= h($p['name']) ?></h4>
                    <div class="display-6 fw-bold my-3">₪<?= h(number_format((float)$p['price'], 0)) ?></div>
                    <p class="muted">עד <?= ((int)$p['max_photos'] === 0) ? 'ללא הגבלה' : h((string)$p['max_photos']) ?> קבצים בגלריה.</p>
                    <p class="muted">תפוגה: <?= ((int)$p['expiry_hours'] === 0) ? 'ללא מחיקה אוטומטית' : h((string)$p['expiry_hours']) . ' שעות' ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php require 'footer.php'; ?>
