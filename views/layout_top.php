<?php
declare(strict_types=1);

$flash = pullFlash();
$trayCount = (int) ($hydratedTray['node_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'VKU Allocator') ?></title>
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
        <a href="/" class="brand">
            <span class="brand-mark">VKU // ALLOCATOR</span>
            <span class="brand-sub">HPC &amp; Hardware Lease Scheduler</span>
        </a>

        <nav class="nav-links">
            <a href="/" class="nav-link <?= ($activeNav ?? '') === 'matrix' ? 'active' : '' ?>">
                [A] Node Matrix
            </a>
            <a href="/tray" class="nav-link <?= ($activeNav ?? '') === 'tray' ? 'active' : '' ?>">
                [B] Staging Tray (<?= $trayCount ?>)
            </a>
            <?php if ($currentUser !== null): ?>
                <a href="/console" class="nav-link <?= ($activeNav ?? '') === 'console' ? 'active' : '' ?>">
                    [C/D] Console (<?= (int) ($activeSystemLeaseCount ?? 0) ?>)
                </a>
                <?php if (($currentUser['role'] ?? '') === 'ADMIN'): ?>
                    <a href="/admin" class="nav-link <?= ($activeNav ?? '') === 'admin' ? 'active' : '' ?>">
                        [D] Cluster Admin
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="topbar-meta">
            <?php if ($currentUser !== null): ?>
                <span class="role-tag"><?= e($currentUser['role']) ?></span>
                <span><?= e($currentUser['student_code']) ?></span>
                <span class="credit-pill"><?= number_format((float) $currentUser['credit_balance'], 2) ?> CR</span>
                <form method="POST" action="/logout" style="display:inline;">
                    <button type="submit" class="btn btn-outline btn-sm">Sign Out</button>
                </form>
            <?php else: ?>
                <a href="/login" class="btn btn-sm">Sign In</a>
            <?php endif; ?>
            <button type="button" id="theme-toggle" class="btn btn-outline btn-sm" title="Toggle Warm White / Soft Black Theme">
                Theme
            </button>
        </div>
    </div>
</header>

<main class="container">
    <?php if ($flash !== null): ?>
        <div class="flash flash-<?= e($flash['type']) ?>">
            <span><?= e($flash['message']) ?></span>
        </div>
    <?php endif; ?>
