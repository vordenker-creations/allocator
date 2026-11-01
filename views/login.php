<?php
declare(strict_types=1);

use VKU\Allocator\Service\I18n;

require __DIR__ . '/layout_top.php';

$returnToParam = (string) ($_GET['return_to'] ?? '/tray');
$googleUrl = '/auth/google?return_to=' . rawurlencode($returnToParam);
?>

<div style="max-width:480px;margin:32px auto;">
    <section class="panel">
        <div class="panel-title">
            <span><?= e(I18n::get('login_title', $currentLang)) ?></span>
            <span class="mono" style="font-size:0.72rem;font-weight:400;color:var(--fg-muted);"><?= e(I18n::get('login_badge', $currentLang)) ?></span>
        </div>

        <p style="font-size:13.5px;color:var(--fg-secondary);margin-bottom:20px;">
            <?= e(I18n::get('login_desc', $currentLang)) ?>
        </p>

        <form method="POST" action="/login">
            <input type="hidden" name="return_to" value="<?= e($returnToParam) ?>">

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" for="identifier"><?= e(I18n::get('login_id_label', $currentLang)) ?></label>
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    class="form-control"
                    required
                    autofocus
                    placeholder="<?= e(I18n::get('login_id_placeholder', $currentLang)) ?>"
                >
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label" for="password"><?= e(I18n::get('login_pw_label', $currentLang)) ?></label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    required
                    placeholder="••••••••"
                >
            </div>

            <button type="submit" class="btn btn-lg" style="width:100%;">
                <?= e(I18n::get('login_submit_btn', $currentLang)) ?>
            </button>
        </form>

        <div class="oauth-divider">
            <span><?= e(I18n::get('login_or_divider', $currentLang)) ?></span>
        </div>

        <a href="<?= e($googleUrl) ?>" class="btn btn-outline btn-lg btn-google" style="width:100%;">
            <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" style="flex-shrink:0;">
                <path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/>
                <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/>
                <path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.13-1.57.38-2.29V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.98-3.09z"/>
                <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09c.95-2.85 3.6-4.96 6.73-4.96z"/>
            </svg>
            <span><?= e(I18n::get('login_google_btn', $currentLang)) ?></span>
        </a>
    </section>

    <section class="panel">
        <div class="panel-title">
            <span><?= e(I18n::get('login_demo_title', $currentLang)) ?></span>
        </div>
        <p class="mono" style="font-size:12px;color:var(--fg-muted);margin-bottom:12px;">
            <?= e(I18n::get('login_demo_hint', $currentLang)) ?> <code>vku2026</code>):
        </p>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('22IT001', 'vku2026')"
            >
                <span><strong><?= e(I18n::translateRole('STUDENT', $currentLang)) ?></strong> &mdash; <?= e(I18n::get('login_demo_student_name', $currentLang)) ?></span>
                <span class="mono"><?= e(I18n::get('login_demo_student_cap', $currentLang)) ?></span>
            </button>
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('RES104', 'vku2026')"
            >
                <span><strong><?= e(I18n::translateRole('RESEARCHER', $currentLang)) ?></strong> &mdash; <?= e(I18n::get('login_demo_res_name', $currentLang)) ?></span>
                <span class="mono"><?= e(I18n::get('login_demo_res_cap', $currentLang)) ?></span>
            </button>
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('ADMIN01', 'vku2026')"
            >
                <span><strong><?= e(I18n::translateRole('ADMIN', $currentLang)) ?></strong> &mdash; <?= e(I18n::get('login_demo_admin_name', $currentLang)) ?></span>
                <span class="mono"><?= e(I18n::get('login_demo_admin_cap', $currentLang)) ?></span>
            </button>
        </div>
    </section>
</div>

<script>
    function fillLogin(id, pw) {
        document.getElementById('identifier').value = id;
        document.getElementById('password').value = pw;
    }
</script>

<?php require __DIR__ . '/layout_bottom.php'; ?>
