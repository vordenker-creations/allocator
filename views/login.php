<?php
declare(strict_types=1);

use VKU\Allocator\Service\I18n;

require __DIR__ . '/layout_top.php';
?>

<div style="max-width:520px;margin:2rem auto;">
    <section class="panel">
        <div class="panel-title">
            <span><?= e(I18n::get('login_title', $currentLang)) ?></span>
            <span class="mono" style="font-size:0.74rem;color:var(--fg-muted);"><?= e(I18n::get('login_badge', $currentLang)) ?></span>
        </div>

        <p style="font-size:0.88rem;color:var(--fg-secondary);margin-bottom:1.25rem;">
            <?= e(I18n::get('login_desc', $currentLang)) ?>
        </p>

        <form method="POST" action="/login">
            <input type="hidden" name="return_to" value="<?= e($_GET['return_to'] ?? '/tray') ?>">

            <div class="form-group" style="margin-bottom:1rem;">
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

            <div class="form-group" style="margin-bottom:1.25rem;">
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

            <button type="submit" class="btn" style="width:100%;padding:0.75rem;">
                <?= e(I18n::get('login_submit_btn', $currentLang)) ?>
            </button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-title">
            <span><?= e(I18n::get('login_demo_title', $currentLang)) ?></span>
        </div>
        <p class="mono" style="font-size:0.76rem;color:var(--fg-muted);margin-bottom:0.85rem;">
            <?= e(I18n::get('login_demo_hint', $currentLang)) ?> <code>vku2026</code>):
        </p>
        <div style="display:flex;flex-direction:column;gap:0.5rem;">
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('22IT001', 'vku2026')"
            >
                <span><strong><?= e(I18n::translateRole('STUDENT', $currentLang)) ?></strong> &mdash; <?= e(I18n::get('login_demo_student_name', $currentLang)) ?></span>
                <span><?= e(I18n::get('login_demo_student_cap', $currentLang)) ?></span>
            </button>
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('RES104', 'vku2026')"
            >
                <span><strong><?= e(I18n::translateRole('RESEARCHER', $currentLang)) ?></strong> &mdash; <?= e(I18n::get('login_demo_res_name', $currentLang)) ?></span>
                <span><?= e(I18n::get('login_demo_res_cap', $currentLang)) ?></span>
            </button>
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('ADMIN01', 'vku2026')"
            >
                <span><strong><?= e(I18n::translateRole('ADMIN', $currentLang)) ?></strong> &mdash; <?= e(I18n::get('login_demo_admin_name', $currentLang)) ?></span>
                <span><?= e(I18n::get('login_demo_admin_cap', $currentLang)) ?></span>
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
