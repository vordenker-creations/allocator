<?php
declare(strict_types=1);

require __DIR__ . '/layout_top.php';

$effectiveRole = $currentUserRole ?? \VKU\Allocator\Domain\Enum\UserRole::Student;
$maxNodesPerJob = $effectiveRole->maxNodesPerJob();
$maxConcurrentLeases = $effectiveRole->maxConcurrentActiveLeases();
$userBalance = $currentUser !== null ? (float) $currentUser['credit_balance'] : 0.0;
$totalCost = (float) $hydratedTray['total_credits'];
$remainingBalance = $userBalance - $totalCost;
$userSshKey = (string) ($currentUser['ssh_public_key'] ?? '');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Job Spec Staging Tray</h1>
        <p class="page-subtitle">
            Configure runtime environment, job start time (24-Hour Reservation Horizon), per-node duration, and commit an atomic reservation.
        </p>
    </div>
    <div style="display:flex;gap:0.5rem;">
        <a href="/" class="btn btn-outline">&larr; Add More Nodes</a>
        <?php if ($hydratedTray['node_count'] > 0): ?>
            <form method="POST" action="/tray/clear" style="display:inline;">
                <button type="submit" class="btn btn-outline">Clear Tray</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($hydratedTray['node_count'] === 0): ?>
    <section class="panel" style="text-align:center;padding:3rem 1.5rem;">
        <h2 class="mono" style="margin-bottom:0.65rem;">Your Job Spec Staging Tray is Empty</h2>
        <p style="color:var(--fg-secondary);margin-bottom:1.25rem;">
            Select one or more online hardware nodes from the Node Matrix to configure a compute job.
        </p>
        <a href="/" class="btn">Browse Node Matrix</a>
    </section>
<?php else: ?>
    <div class="split-grid">
        <div>
            <form id="job-spec-form" method="POST" action="/provision">
                <section class="panel">
                    <div class="panel-title">
                        <span>1. Staged Compute Nodes (<?= (int) $hydratedTray['node_count'] ?> / <?= $maxNodesPerJob ?> Max for <?= e($effectiveRole->value) ?>)</span>
                    </div>

                    <div class="table-wrap" style="margin-bottom:1rem;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Node Hostname</th>
                                    <th>Tier &amp; Cap</th>
                                    <th>Rate</th>
                                    <th>Hours</th>
                                    <th>Window End</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hydratedTray['items'] as $item): ?>
                                    <?php $nid = (int) $item['node_id']; ?>
                                    <tr>
                                        <td class="mono">
                                            <strong><?= e($item['hostname']) ?></strong><br>
                                            <span style="font-size:0.75rem;color:var(--fg-muted);">
                                                <?= e($item['gpu_model'] ?? ($item['cpu_cores'] . '-Core CPU')) ?>
                                            </span>
                                        </td>
                                        <td class="mono">
                                            <?= e($item['tier_name']) ?><br>
                                            <span style="font-size:0.74rem;color:var(--fg-muted);">
                                                Max <?= (int) $item['max_allocation_hours'] ?>h
                                            </span>
                                        </td>
                                        <td class="mono"><?= number_format((float) $item['credits_per_hour'], 2) ?> CR/h</td>
                                        <td>
                                            <input
                                                type="number"
                                                name="node_hours[<?= $nid ?>]"
                                                class="form-control node-hours-input"
                                                data-rate="<?= (float) $item['credits_per_hour'] ?>"
                                                data-node-id="<?= $nid ?>"
                                                min="1"
                                                max="<?= (int) $item['max_allocation_hours'] ?>"
                                                value="<?= (int) $item['hours'] ?>"
                                                style="width:76px;"
                                            >
                                        </td>
                                        <td class="mono" style="font-size:0.78rem;">
                                            <?= e($item['end_time']->format('m-d H:i')) ?>
                                        </td>
                                        <td class="mono" style="font-weight:700;" id="subtotal-<?= $nid ?>">
                                            <?= number_format((float) $item['line_cost'], 2) ?> CR
                                        </td>
                                        <td>
                                            <button
                                                type="submit"
                                                form="remove-node-<?= $nid ?>"
                                                class="btn btn-outline btn-sm"
                                                title="Remove node from tray"
                                            >
                                                &times;
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" formaction="/tray/update" class="btn btn-outline btn-sm">
                            Recalculate &amp; Save Tray Changes
                        </button>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-title">
                        <span>2. Runtime Environment &amp; Scheduling Parameters</span>
                    </div>

                    <div class="filter-grid" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label" for="project_name">Job / Project Label</label>
                            <input
                                type="text"
                                id="project_name"
                                name="project_name"
                                class="form-control"
                                required
                                maxlength="100"
                                value="<?= e($hydratedTray['project_name']) ?>"
                                placeholder="e.g. disrated-vlm-eval"
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="start_time">Job-Wide Start Time (24-Hour Reservation Horizon)</label>
                            <input
                                type="datetime-local"
                                id="start_time"
                                name="start_time"
                                class="form-control"
                                required
                                min="<?= e($minStartStr) ?>"
                                max="<?= e($maxStartStr) ?>"
                                value="<?= e($hydratedTray['start_time_input']) ?>"
                            >
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label" for="docker_image">Container / Runtime Environment Preset</label>
                        <select id="docker_image" name="docker_image" class="form-control">
                            <?php foreach ($presets as $preset): ?>
                                <option
                                    value="<?= e($preset->value) ?>"
                                    <?= $hydratedTray['docker_image'] === $preset->value ? 'selected' : '' ?>
                                >
                                    <?= e($preset->shortLabel()) ?> &mdash; [<?= e($preset->value) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="form-label" for="startup_script">Optional Startup Script / Job Notes</label>
                        <textarea
                            id="startup_script"
                            name="startup_script"
                            class="form-control"
                            placeholder="#!/usr/bin/env bash&#10;export CUDA_VISIBLE_DEVICES=0&#10;python3 train.py --epochs 10"
                        ><?= e($hydratedTray['startup_script']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ssh_public_key">
                            SSH Public Key (ssh-ed25519 / ssh-rsa / ecdsa) &mdash; Required for Node Provisioning
                        </label>
                        <input
                            type="text"
                            id="ssh_public_key"
                            name="ssh_public_key"
                            class="form-control"
                            required
                            value="<?= e($userSshKey) ?>"
                            placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... student@vku.udn.vn"
                        >
                        <span class="mono" style="font-size:0.74rem;color:var(--fg-muted);">
                            Validated via strict OpenSSH regex prior to atomic SQL commit.
                        </span>
                    </div>
                </section>
            </form>

            <?php foreach ($hydratedTray['items'] as $item): ?>
                <form id="remove-node-<?= (int) $item['node_id'] ?>" method="POST" action="/tray/remove" style="display:none;">
                    <input type="hidden" name="node_id" value="<?= (int) $item['node_id'] ?>">
                </form>
            <?php endforeach; ?>
        </div>

        <aside class="panel">
            <div class="panel-title">
                <span>3. Transactional Cost Summary</span>
            </div>

            <div class="summary-row">
                <span>Staged Nodes</span>
                <strong><?= (int) $hydratedTray['node_count'] ?> / <?= $maxNodesPerJob ?></strong>
            </div>
            <div class="summary-row">
                <span>Job Start Window</span>
                <strong><?= e($hydratedTray['start_time_dt']->format('Y-m-d H:i')) ?></strong>
            </div>
            <div class="summary-row">
                <span>Booking Horizon</span>
                <strong>Valid (24-Hour Horizon)</strong>
            </div>

            <?php if ($currentUser !== null): ?>
                <div class="summary-row">
                    <span>Current Balance</span>
                    <strong><?= number_format($userBalance, 2) ?> CR</strong>
                </div>
                <div class="summary-row total">
                    <span>Total Credit Burn</span>
                    <span id="grand-total-credits"><?= number_format($totalCost, 2) ?> CR</span>
                </div>
                <div class="summary-row" style="border-top:1px solid var(--border-color);margin-top:0.5rem;">
                    <span>Projected Balance After Commit</span>
                    <strong style="color:<?= $remainingBalance < 0 ? 'var(--status-maint-fg)' : 'var(--status-online-fg)' ?>;">
                        <?= number_format($remainingBalance, 2) ?> CR
                    </strong>
                </div>

                <?php if ($maxConcurrentLeases !== null && $activeSystemLeaseCount >= $maxConcurrentLeases): ?>
                    <div class="flash flash-error" style="margin-top:1rem;margin-bottom:0;">
                        <span>
                            Hoarding limit: Your <?= e($effectiveRole->value) ?> account already has <?= (int) $activeSystemLeaseCount ?> active/scheduled lease. Terminate it in the Console first.
                        </span>
                    </div>
                <?php endif; ?>

                <button
                    type="submit"
                    form="job-spec-form"
                    class="btn"
                    style="width:100%;margin-top:1.25rem;padding:0.8rem;"
                    <?= ($remainingBalance < 0 || ($maxConcurrentLeases !== null && $activeSystemLeaseCount >= $maxConcurrentLeases)) ? 'disabled' : '' ?>
                >
                    Provision Job Atomically &rarr;
                </button>
            <?php else: ?>
                <div class="summary-row total">
                    <span>Total Credit Burn</span>
                    <span id="grand-total-credits"><?= number_format($totalCost, 2) ?> CR</span>
                </div>
                <p style="font-size:0.84rem;color:var(--fg-secondary);margin:1rem 0;">
                    Authentication is required to verify your Compute Credit quota and SSH public key.
                </p>
                <a href="/login" class="btn" style="width:100%;padding:0.8rem;">
                    Sign In to Provision Job &rarr;
                </a>
            <?php endif; ?>

            <div class="mono" style="margin-top:1.25rem;font-size:0.74rem;color:var(--fg-muted);line-height:1.5;">
                <strong>ACID Transaction Guarantees:</strong><br>
                1. Row-level <code>FOR UPDATE</code> lock on user &amp; target nodes.<br>
                2. Verifies zero overlapping leases in <code>[start_time, end_time]</code>.<br>
                3. Deducts credits &amp; issues ephemeral SSH port (20000&ndash;60000).
            </div>
        </aside>
    </div>

    <script>
        (function () {
            const inputs = document.querySelectorAll('.node-hours-input');
            const grandTotalEl = document.getElementById('grand-total-credits');
            if (!inputs.length || !grandTotalEl) return;

            function recalc() {
                let total = 0;
                inputs.forEach(function (input) {
                    const rate = parseFloat(input.getAttribute('data-rate') || '0');
                    const nodeId = input.getAttribute('data-node-id');
                    const hours = Math.max(1, parseInt(input.value || '1', 10));
                    const sub = rate * hours;
                    total += sub;
                    const subEl = document.getElementById('subtotal-' + nodeId);
                    if (subEl) {
                        subEl.textContent = sub.toFixed(2) + ' CR';
                    }
                });
                grandTotalEl.textContent = total.toFixed(2) + ' CR';
            }

            inputs.forEach(function (input) {
                input.addEventListener('input', recalc);
            });
        })();
    </script>
<?php endif; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
