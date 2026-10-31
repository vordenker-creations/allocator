<?php
declare(strict_types=1);

require __DIR__ . '/layout_top.php';
?>

<div style="max-width:520px;margin:2rem auto;">
    <section class="panel">
        <div class="panel-title">
            <span>VKU Identity Authentication</span>
            <span class="mono" style="font-size:0.74rem;color:var(--fg-muted);">Argon2id Auth</span>
        </div>

        <p style="font-size:0.88rem;color:var(--fg-secondary);margin-bottom:1.25rem;">
            Sign in with your VKU Student Code / Staff ID or `@vku.udn.vn` email address to stage and provision cluster hardware.
        </p>

        <form method="POST" action="/login">
            <input type="hidden" name="return_to" value="<?= e($_GET['return_to'] ?? '/tray') ?>">

            <div class="form-group" style="margin-bottom:1rem;">
                <label class="form-label" for="identifier">Student Code / Staff ID or VKU Email</label>
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    class="form-control"
                    required
                    autofocus
                    placeholder="22IT001 or an.nv.22it@vku.udn.vn"
                >
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label class="form-label" for="password">Password</label>
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
                Authenticate &rarr;
            </button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-title">
            <span>Quick-Fill Demo Accounts (Viva / Grading)</span>
        </div>
        <p class="mono" style="font-size:0.76rem;color:var(--fg-muted);margin-bottom:0.85rem;">
            Click any role below to auto-fill credentials (Password: <code>vku2026</code>):
        </p>
        <div style="display:flex;flex-direction:column;gap:0.5rem;">
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('22IT001', 'vku2026')"
            >
                <span><strong>STUDENT</strong> &mdash; 22IT001 (Nguyen Van An)</span>
                <span>Max 1 Node/Job &bull; 240 CR</span>
            </button>
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('RES104', 'vku2026')"
            >
                <span><strong>RESEARCHER</strong> &mdash; RES104 (Dr. Le Hoang Nam)</span>
                <span>Max 3 Nodes/Job &bull; 1250 CR</span>
            </button>
            <button
                type="button"
                class="btn btn-outline btn-sm"
                style="justify-content:space-between;"
                onclick="fillLogin('ADMIN01', 'vku2026')"
            >
                <span><strong>ADMIN</strong> &mdash; ADMIN01 (HPC Cluster Admin)</span>
                <span>Max 3 Nodes/Job &bull; 9999 CR</span>
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
