<?php
declare(strict_types=1);

require __DIR__ . '/layout_top.php';

$now = new DateTimeImmutable();
$activeLeases = [];
$historyLeases = [];

foreach ($leases as $lease) {
    if (in_array((string) $lease['lease_status'], ['ACTIVE', 'SCHEDULED'], true)) {
        $activeLeases[] = $lease;
    } else {
        $historyLeases[] = $lease;
    }
}

$studentHandle = strtolower((string) ($currentUser['student_code'] ?? 'student'));
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Active Compute Console &amp; Lease Receipts</h1>
        <p class="page-subtitle">
            Loop C &amp; D: Connect via ephemeral SSH tunnel, monitor live lease countdowns, or trigger early release for an automatic pro-rata credit refund.
        </p>
    </div>
    <div>
        <a href="/" class="btn btn-outline">+ Provision New Hardware</a>
    </div>
</div>

<section class="panel">
    <div class="panel-title">
        <span>Active &amp; Scheduled Hardware Leases (<?= count($activeLeases) ?>)</span>
        <span class="mono" style="font-size:0.78rem;color:var(--fg-muted);font-weight:400;">
            Server Clock: <?= e($now->format('Y-m-d H:i:s')) ?> (UTC+7)
        </span>
    </div>

    <?php if ($activeLeases === []): ?>
        <p class="mono" style="color:var(--fg-secondary);padding:1rem 0;">
            No active or scheduled leases at this time. Visit the <a href="/">Node Matrix</a> to stage hardware.
        </p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:1.25rem;">
            <?php foreach ($activeLeases as $lease): ?>
                <?php
                $leaseId = (int) $lease['lease_id'];
                $status = (string) $lease['lease_status'];
                $startDt = new DateTimeImmutable((string) $lease['start_time']);
                $endDt = new DateTimeImmutable((string) $lease['end_time']);
                $rate = (float) $lease['hourly_rate_snapshot'];

                $effectiveRef = $now < $startDt ? $startDt : $now;
                $unusedFullHours = 0;
                if ($effectiveRef < $endDt) {
                    $diff = $effectiveRef->diff($endDt);
                    $unusedFullHours = ($diff->days * 24) + $diff->h;
                }
                $estimatedRefund = round($unusedFullHours * $rate, 2);

                $sshCommand = sprintf(
                    'ssh -p %d %s@allocator.vku.udn.vn',
                    (int) $lease['assigned_port'],
                    $studentHandle
                );
                ?>
                <div class="node-card" style="background-color:var(--bg-primary);border-color:var(--border-strong);">
                    <div class="node-card-top">
                        <div>
                            <span class="badge <?= $status === 'ACTIVE' ? 'badge-active' : 'badge-scheduled' ?>">
                                <?= e($status) ?>
                            </span>
                            <span class="mono" style="font-weight:700;font-size:1.05rem;margin-left:0.5rem;">
                                <?= e($lease['hostname']) ?>
                            </span>
                            <span class="mono" style="color:var(--fg-muted);font-size:0.82rem;margin-left:0.4rem;">
                                [Job #<?= (int) $lease['allocation_id'] ?>: <?= e($lease['project_name']) ?>]
                            </span>
                        </div>
                        <div class="mono" style="text-align:right;">
                            <div style="font-size:0.74rem;color:var(--fg-muted);">
                                <?= $status === 'ACTIVE' ? 'Remaining Lease Window' : 'Starts In / Window' ?>
                            </div>
                            <div
                                class="live-countdown"
                                data-start-ts="<?= $startDt->getTimestamp() ?>"
                                data-end-ts="<?= $endDt->getTimestamp() ?>"
                                data-status="<?= e($status) ?>"
                                style="font-size:1rem;font-weight:700;"
                            >
                                Calculating...
                            </div>
                        </div>
                    </div>

                    <div class="terminal-box">
                        <div>
                            <span class="terminal-prompt">$</span>
                            <code><?= e($sshCommand) ?></code>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm"
                            style="background:#FAF8F5;color:#141413;border-color:#FAF8F5;"
                            onclick="copyTextToClipboard(<?= e(json_encode($sshCommand)) ?>, this)"
                        >
                            Copy SSH
                        </button>
                    </div>

                    <div class="spec-list" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));">
                        <div class="spec-item">
                            <span>Runtime Container Image</span>
                            <strong><?= e($lease['docker_image']) ?></strong>
                        </div>
                        <div class="spec-item">
                            <span>Lease Window</span>
                            <strong><?= e($startDt->format('Y-m-d H:i')) ?> &rarr; <?= e($endDt->format('Y-m-d H:i')) ?></strong>
                        </div>
                        <div class="spec-item">
                            <span>Ephemeral Port / Node IP</span>
                            <strong>Port <?= (int) $lease['assigned_port'] ?> &rarr; <?= e($lease['ip_address']) ?>:22</strong>
                        </div>
                        <div class="spec-item">
                            <span>Access Token (32-Char Hex)</span>
                            <strong><code><?= e($lease['access_token']) ?></code></strong>
                        </div>
                    </div>

                    <?php if (!empty($lease['startup_script'])): ?>
                        <div class="mono" style="font-size:0.78rem;background:var(--bg-elevated);padding:0.6rem 0.8rem;border:1px solid var(--border-color);">
                            <span style="color:var(--fg-muted);display:block;margin-bottom:0.2rem;">STARTUP SCRIPT:</span>
                            <pre style="white-space:pre-wrap;"><?= e($lease['startup_script']) ?></pre>
                        </div>
                    <?php endif; ?>

                    <div class="node-card-footer">
                        <div class="mono" style="font-size:0.8rem;">
                            Locked Rate: <strong><?= number_format($rate, 2) ?> CR/h</strong> &bull;
                            Early Release Pro-Rata Refund:
                            <strong style="color:var(--status-online-fg);">
                                +<?= number_format($estimatedRefund, 2) ?> CR (<?= $unusedFullHours ?> unused full hr)
                            </strong>
                        </div>

                        <form
                            method="POST"
                            action="/lease/terminate"
                            onsubmit="return confirm('Terminate lease #<?= $leaseId ?> on <?= e($lease['hostname']) ?> early and refund <?= number_format($estimatedRefund, 2) ?> CR?');"
                        >
                            <input type="hidden" name="lease_id" value="<?= $leaseId ?>">
                            <input type="hidden" name="return_to" value="/console">
                            <button type="submit" class="btn btn-danger btn-sm">
                                Terminate Lease &amp; Refund <?= number_format($estimatedRefund, 2) ?> CR
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="split-grid">
    <section class="panel">
        <div class="panel-title">
            <span>Lease Audit History (<?= count($historyLeases) ?>)</span>
        </div>
        <?php if ($historyLeases === []): ?>
            <p class="mono" style="color:var(--fg-muted);font-size:0.84rem;">No expired or cancelled leases yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Lease</th>
                            <th>Node / Project</th>
                            <th>Window</th>
                            <th>Status</th>
                            <th>Refunded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyLeases as $h): ?>
                            <tr>
                                <td class="mono">#<?= (int) $h['lease_id'] ?></td>
                                <td class="mono">
                                    <strong><?= e($h['hostname']) ?></strong><br>
                                    <span style="font-size:0.75rem;color:var(--fg-muted);"><?= e($h['project_name']) ?></span>
                                </td>
                                <td class="mono" style="font-size:0.76rem;">
                                    <?= e((string) $h['start_time']) ?><br>
                                    &rarr; <?= e((string) $h['end_time']) ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= strtolower((string) $h['lease_status']) ?>">
                                        <?= e($h['lease_status']) ?>
                                    </span>
                                </td>
                                <td class="mono"><?= number_format((float) $h['refunded_credits'], 2) ?> CR</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-title">
            <span>SSH Public Key Profile</span>
        </div>
        <form method="POST" action="/profile/ssh-key">
            <div class="form-group" style="margin-bottom:0.85rem;">
                <label class="form-label" for="profile_ssh_key">Authorized OpenSSH Key (ed25519 / rsa / ecdsa)</label>
                <textarea
                    id="profile_ssh_key"
                    name="ssh_public_key"
                    class="form-control"
                    required
                    placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... user@vku.udn.vn"
                ><?= e($currentUser['ssh_public_key'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Update SSH Public Key</button>
        </form>
    </section>
</div>

<script>
    (function () {
        const timers = document.querySelectorAll('.live-countdown');
        if (!timers.length) return;

        function formatDuration(sec) {
            if (sec <= 0) return '00h 00m 00s';
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = Math.floor(sec % 60);
            return String(h).padStart(2, '0') + 'h ' +
                   String(m).padStart(2, '0') + 'm ' +
                   String(s).padStart(2, '0') + 's';
        }

        function tick() {
            const nowSec = Math.floor(Date.now() / 1000);
            timers.forEach(function (el) {
                const startTs = parseInt(el.getAttribute('data-start-ts') || '0', 10);
                const endTs = parseInt(el.getAttribute('data-end-ts') || '0', 10);

                if (nowSec < startTs) {
                    el.textContent = 'Starts in ' + formatDuration(startTs - nowSec);
                } else if (nowSec < endTs) {
                    el.textContent = formatDuration(endTs - nowSec) + ' remaining';
                } else {
                    el.textContent = 'Window Expired';
                }
            });
        }

        tick();
        setInterval(tick, 1000);
    })();
</script>

<?php require __DIR__ . '/layout_bottom.php'; ?>
