<nav class="navbar navbar-expand-lg navbar-light site-nav sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="index.php" aria-label="Mageloq">
            <img src="images/logo.png" alt="Mageloq" class="brand-logo">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="פתח תפריט">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if (is_logged_in()): ?>
                    <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-regular fa-images"></i> הגלריות שלי</a></li>
                    <li class="nav-item"><a class="nav-link" href="create_gallery.php"><i class="fa-solid fa-plus"></i> גלריה חדשה</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_reports.php"><i class="fa-regular fa-flag"></i> דיווחים</a></li>
                    <li class="nav-item"><a class="nav-link" href="profile.php"><i class="fa-regular fa-user"></i> פרופיל</a></li>
                    <?php if (is_admin()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="fa-solid fa-screwdriver-wrench"></i> ניהול</a>
                            <ul class="dropdown-menu text-end">
                                <li><a class="dropdown-item" href="admin_panel.php">משתמשים</a></li>
                                <li><a class="dropdown-item" href="manage_packages.php">חבילות</a></li>
                                <li><a class="dropdown-item" href="admin_messages.php">פניות מהאתר</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="index.php#features">יכולות</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#pricing">מסלולים</a></li>
                    <li class="nav-item"><a class="nav-link" href="contact.php">צור קשר</a></li>
                <?php endif; ?>
            </ul>
            <div class="d-flex gap-2 align-items-center">
                <?php if (is_logged_in()): ?>
                    <a href="logout.php" class="btn btn-outline-dark btn-sm">התנתק</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-outline-dark btn-sm">התחברות</a>
                    <a href="register.php" class="btn btn-gradient btn-sm">הרשמה</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
