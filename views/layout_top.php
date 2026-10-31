<?php
declare(strict_types=1);

use VKU\Allocator\Service\I18n;

$currentUser = $currentUser ?? null;
$hydratedTray = $hydratedTray ?? [];
$activeSystemLeaseCount = $activeSystemLeaseCount ?? 0;

$flash = pullFlash();
$trayCount = (int) ($hydratedTray['node_count'] ?? 0);
$currentLang = $currentLang ?? I18n::resolve(
    isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : null,
    isset($_COOKIE['vku_lang']) && is_string($_COOKIE['vku_lang']) ? $_COOKIE['vku_lang'] : (isset($_COOKIE['lang']) && is_string($_COOKIE['lang']) ? $_COOKIE['lang'] : null),
    isset($_SESSION['lang']) && is_string($_SESSION['lang']) ? $_SESSION['lang'] : null
);
?>
<!DOCTYPE html>
<html lang="<?= e($currentLang) ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? ($currentLang === 'vi' ? 'VKU Allocator — Điều phối tài nguyên' : 'VKU Allocator')) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
    <script>
        (function () {
            const saved = localStorage.getItem('vku_allocator_theme');
            if (saved === 'dark' || saved === 'light') {
                document.documentElement.setAttribute('data-theme', saved);
            }
        })();
    </script>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a href="/" class="brand" aria-label="Allocator">
            <svg class="brand-logo" width="130" height="26" viewBox="0 0 646 129" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Allocator">
                <title>Allocator</title>
                <rect width="72" height="129" fill="#E21A1B"/>
                <rect x="93" width="72" height="129" fill="#FFC400"/>
                <rect x="186" width="72" height="129" fill="#004BA5"/>
                <path class="brand-wordmark" d="M294.31 89L306.454 40.82H317.08L329.29 89H320.842L318.202 77.318H305.398L302.758 89H294.31ZM306.916 70.586H316.684L313.78 57.65C313.296 55.494 312.878 53.558 312.526 51.842C312.174 50.082 311.932 48.806 311.8 48.014C311.668 48.806 311.426 50.082 311.074 51.842C310.766 53.558 310.348 55.472 309.82 57.584L306.916 70.586ZM356.918 89C354.542 89 352.452 88.516 350.648 87.548C348.888 86.58 347.502 85.216 346.49 83.456C345.478 81.652 344.972 79.584 344.972 77.252V48.278H333.356V40.82H353.222V77.252C353.222 78.572 353.596 79.628 354.344 80.42C355.136 81.168 356.192 81.542 357.512 81.542H368.468V89H356.918ZM396.492 89C394.116 89 392.026 88.516 390.222 87.548C388.462 86.58 387.076 85.216 386.064 83.456C385.052 81.652 384.546 79.584 384.546 77.252V48.278H372.93V40.82H392.796V77.252C392.796 78.572 393.17 79.628 393.918 80.42C394.71 81.168 395.766 81.542 397.086 81.542H408.042V89H396.492ZM430.523 89.594C427.443 89.594 424.759 89.022 422.471 87.878C420.227 86.69 418.467 85.04 417.191 82.928C415.959 80.772 415.343 78.242 415.343 75.338V66.362C415.343 63.458 415.959 60.95 417.191 58.838C418.467 56.682 418.467 55.032 422.471 53.888C424.759 52.7 427.443 52.106 430.523 52.106C433.647 52.106 436.331 52.7 438.575 53.888C440.819 55.032 442.557 56.682 443.789 58.838C445.065 60.95 445.703 63.436 445.703 66.296V75.338C445.703 78.242 445.065 80.772 443.789 82.928C442.557 85.04 440.819 86.69 438.575 87.878C436.331 89.022 433.647 89.594 430.523 89.594ZM430.523 82.4C432.723 82.4 434.417 81.806 435.605 80.618C436.837 79.386 437.453 77.626 437.453 75.338V66.362C437.453 64.03 436.837 62.27 435.605 61.082C434.417 59.894 432.723 59.3 430.523 59.3C428.367 59.3 426.673 59.894 425.441 61.082C424.209 62.27 423.593 64.03 423.593 66.362V75.338C423.593 77.626 424.209 79.386 425.441 80.618C426.673 81.806 428.367 82.4 430.523 82.4ZM470.295 89.66C467.215 89.66 464.509 89.088 462.177 87.944C459.889 86.756 458.107 85.106 456.831 82.994C455.555 80.838 454.917 78.308 454.917 75.404V66.296C454.917 63.348 455.555 60.818 456.831 58.706C458.107 56.594 459.889 54.966 462.177 53.822C464.509 52.634 467.215 52.04 470.295 52.04C474.871 52.04 478.523 53.228 481.251 55.604C483.979 57.936 485.409 61.126 485.541 65.174H477.291C477.159 63.282 476.477 61.83 475.245 60.818C474.057 59.762 472.407 59.234 470.295 59.234C468.095 59.234 466.357 59.85 465.081 61.082C463.805 62.27 463.167 63.986 463.167 66.23V75.404C463.167 77.648 463.805 79.386 465.081 80.618C466.357 81.85 468.095 82.466 470.295 82.466C472.407 82.466 474.057 81.96 475.245 80.948C476.477 79.892 477.159 78.418 477.291 76.526H485.541C485.409 80.574 483.979 83.786 481.251 86.162C478.523 88.494 474.871 89.66 470.295 89.66ZM505.645 89.66C501.905 89.66 498.957 88.67 496.801 86.69C494.645 84.71 493.567 82.048 493.567 78.704C493.567 75.14 494.755 72.39 497.131 70.454C499.507 68.518 502.873 67.55 507.229 67.55H516.271V64.448C516.271 62.688 515.699 61.324 514.555 60.356C513.411 59.344 511.849 58.838 509.869 58.838C508.065 58.838 506.569 59.234 505.381 60.026C504.193 60.818 503.489 61.896 503.269 63.26H495.217C495.613 59.828 497.153 57.1 499.837 55.076C502.521 53.052 505.953 52.04 510.133 52.04C514.577 52.04 518.075 53.162 520.627 55.406C523.223 57.606 524.521 60.598 524.521 64.382V89H516.535V82.664H515.215L516.535 80.882C516.535 83.566 515.545 85.7 513.565 87.284C511.585 88.868 508.945 89.66 505.645 89.66ZM508.351 83.456C510.683 83.456 512.575 82.862 514.027 81.674C515.523 80.486 516.271 78.946 516.271 77.054V72.632H507.361C505.689 72.632 504.347 73.116 503.335 74.084C502.323 75.052 501.817 76.328 501.817 77.912C501.817 79.628 502.389 80.992 503.533 82.004C504.721 82.972 506.327 83.456 508.351 83.456ZM553.205 89C549.729 89 547.001 88.032 545.021 86.096C543.085 84.16 542.117 81.498 542.117 78.11V60.158H532.283V52.7H542.117V42.47H550.433V52.7H564.359V60.158H550.433V78.11C550.433 80.398 551.555 81.542 553.799 81.542H563.699V89H553.205ZM588.82 89.594C585.74 89.594 583.056 89.022 580.768 87.878C578.524 86.69 576.764 85.04 575.488 82.928C574.256 80.772 573.64 78.242 573.64 75.338V66.362C573.64 63.458 574.256 60.95 575.488 58.838C576.764 56.682 578.524 55.032 580.768 53.888C583.056 52.7 585.74 52.106 588.82 52.106C591.944 52.106 594.628 52.7 596.872 53.888C599.116 55.032 600.854 56.682 602.086 58.838C603.362 60.95 604 63.436 604 66.296V75.338C604 78.242 603.362 80.772 602.086 82.928C600.854 85.04 599.116 86.69 596.872 87.878C594.628 89.022 591.944 89.594 588.82 89.594ZM588.82 82.4C591.02 82.4 592.714 81.806 593.902 80.618C595.134 79.386 595.75 77.626 595.75 75.338V66.362C595.75 64.03 595.134 62.27 593.902 61.082C592.714 59.894 591.02 59.3 588.82 59.3C586.664 59.3 584.97 59.894 583.738 61.082C582.506 62.27 581.89 64.03 581.89 66.362V75.338C581.89 77.626 582.506 79.386 583.738 80.618C584.97 81.806 586.664 82.4 588.82 82.4ZM614.798 89V52.7H622.586V59.63H624.632L622.19 63.854C622.19 59.938 623.07 56.99 624.83 55.01C626.59 53.03 629.186 52.04 632.618 52.04C636.534 52.04 639.614 53.25 641.858 55.67C644.102 58.09 645.224 61.434 645.224 65.702V68.342H636.578V66.296C636.578 63.964 635.962 62.16 634.73 60.884C633.542 59.608 631.892 58.97 629.78 58.97C627.624 58.97 625.952 59.608 624.764 60.884C623.62 62.16 623.048 63.964 623.048 66.296V89H614.798Z" fill="currentColor"/>
            </svg>
            <span class="brand-sub"><?= e(I18n::get('brand_sub', $currentLang)) ?></span>
        </a>

        <!-- Navigation Links: Node Matrix | Staging Tray | Active Console | Cluster Admin -->
        <nav class="nav-links">
            <a href="/" class="nav-link <?= ($activeNav ?? '') === 'matrix' ? 'active' : '' ?>">
                <?= e(I18n::get('nav_node_matrix', $currentLang)) ?>
            </a>
            <a href="/tray" class="nav-link <?= ($activeNav ?? '') === 'tray' ? 'active' : '' ?>">
                <?= e(I18n::get('nav_staging_tray', $currentLang)) ?> (<?= $trayCount ?>)
            </a>
            <?php if ($currentUser !== null): ?>
                <a href="/console" class="nav-link <?= ($activeNav ?? '') === 'console' ? 'active' : '' ?>">
                    <?= e(I18n::get('nav_active_console', $currentLang)) ?> (<?= (int) ($activeSystemLeaseCount ?? 0) ?>)
                </a>
                <?php if (($currentUser['role'] ?? '') === 'ADMIN'): ?>
                    <a href="/admin" class="nav-link <?= ($activeNav ?? '') === 'admin' ? 'active' : '' ?>">
                        <?= e(I18n::get('nav_cluster_admin', $currentLang)) ?>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="topbar-meta">
            <?php if ($currentUser !== null): ?>
                <span class="role-tag"><?= e(I18n::translateRole((string) $currentUser['role'], $currentLang)) ?></span>
                <span><?= e($currentUser['student_code']) ?></span>
                <span class="credit-pill"><?= number_format((float) $currentUser['credit_balance'], 2) ?> CR</span>
                <form method="POST" action="/logout" style="display:inline;">
                    <button type="submit" class="btn btn-outline btn-sm"><?= e(I18n::get('nav_sign_out', $currentLang)) ?></button>
                </form>
            <?php else: ?>
                <a href="/login" class="btn btn-sm"><?= e(I18n::get('nav_sign_in', $currentLang)) ?></a>
            <?php endif; ?>
            <button type="button" id="theme-toggle" class="btn btn-outline btn-sm" title="<?= e(I18n::get('theme_toggle_title', $currentLang)) ?>"><?= e(I18n::get('nav_theme', $currentLang)) ?></button>
            <button type="button" id="lang-toggle" class="btn btn-outline btn-sm" data-lang="<?= e(I18n::get('lang_toggle_target', $currentLang)) ?>" data-current-lang="<?= e($currentLang) ?>" aria-label="<?= e(I18n::get('lang_toggle_title', $currentLang)) ?>" title="<?= e(I18n::get('lang_toggle_title', $currentLang)) ?>"><?= e(I18n::get('lang_toggle_label', $currentLang)) ?></button>
        </div>
    </div>
</header>

<main class="container">
    <?php if ($flash !== null): ?>
        <div class="flash flash-<?= e($flash['type']) ?>">
            <span><?= e($flash['message']) ?></span>
        </div>
    <?php endif; ?>
