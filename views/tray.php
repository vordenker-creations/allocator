<?php
declare(strict_types=1);

use VKU\Allocator\Service\I18n;

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
        <h1 class="page-title"><?= e(I18n::get('tray_title', $currentLang)) ?></h1>
        <p class="page-subtitle">
            <!-- 24-Hour Reservation Horizon -->
            <?= e(I18n::get('tray_subtitle', $currentLang)) ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="/" class="btn btn-outline"><?= e(I18n::get('tray_add_more', $currentLang)) ?></a>
        <?php if ($hydratedTray['node_count'] > 0): ?>
            <form method="POST" action="/tray/clear" style="display:inline-flex;">
                <button type="submit" class="btn btn-outline"><?= e(I18n::get('tray_clear', $currentLang)) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($hydratedTray['node_count'] === 0): ?>
    <section class="panel" style="text-align:center;padding:48px 24px;">
        <h2 class="mono" style="font-size:16px;font-weight:600;margin-bottom:8px;"><?= e(I18n::get('tray_empty_title', $currentLang)) ?></h2>
        <p style="color:var(--fg-secondary);margin-bottom:20px;">
            <?= e(I18n::get('tray_empty_desc', $currentLang)) ?>
        </p>
        <a href="/" class="btn"><?= e(I18n::get('tray_browse_btn', $currentLang)) ?></a>
    </section>
<?php else: ?>
    <div class="split-grid">
        <div>
            <form id="job-spec-form" method="POST" action="/provision">
                <section class="panel">
                    <div class="panel-title">
                        <span><?= e(I18n::format('tray_sec1_title', $currentLang, [(int) $hydratedTray['node_count'], $maxNodesPerJob, I18n::translateRole($effectiveRole->value, $currentLang)])) ?></span>
                    </div>

                    <div class="table-wrap" style="margin-bottom:12px;">
                        <table>
                            <thead>
                                <tr>
                                    <th><?= e(I18n::get('th_node_hostname', $currentLang)) ?></th>
                                    <th><?= e(I18n::get('th_tier_cap', $currentLang)) ?></th>
                                    <th><?= e(I18n::get('th_rate', $currentLang)) ?></th>
                                    <th><?= e(I18n::get('th_hours', $currentLang)) ?></th>
                                    <th><?= e(I18n::get('th_window_end', $currentLang)) ?></th>
                                    <th><?= e(I18n::get('th_subtotal', $currentLang)) ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hydratedTray['items'] as $item): ?>
                                    <?php $nid = (int) $item['node_id']; ?>
                                    <tr>
                                        <td class="mono">
                                            <strong><?= e($item['hostname']) ?></strong><br>
                                            <span style="font-size:0.74rem;color:var(--fg-muted);">
                                                <?= e($item['gpu_model'] ?? I18n::format('tray_core_cpu', $currentLang, [(int) $item['cpu_cores']])) ?>
                                            </span>
                                        </td>
                                        <td class="mono">
                                            <?= e(I18n::translateTierName('', (string) $item['tier_name'], $currentLang)) ?><br>
                                            <span style="font-size:0.74rem;color:var(--fg-muted);">
                                                <?= e(I18n::format('filter_max_h', $currentLang, [(int) $item['max_allocation_hours']])) ?>
                                            </span>
                                        </td>
                                        <td class="mono"><?= number_format((float) $item['credits_per_hour'], 2) ?> CR/h</td>
                                        <td>
                                            <input
                                                type="number"
                                                name="node_hours[<?= $nid ?>]"
                                                class="form-control form-control-sm node-hours-input"
                                                data-rate="<?= (float) $item['credits_per_hour'] ?>"
                                                data-node-id="<?= $nid ?>"
                                                min="1"
                                                max="<?= (int) $item['max_allocation_hours'] ?>"
                                                value="<?= (int) $item['hours'] ?>"
                                                style="width:72px;"
                                            >
                                        </td>
                                        <td class="mono" style="font-size:0.78rem;">
                                            <?= e($item['end_time']->format('m-d H:i')) ?>
                                        </td>
                                        <td class="mono" style="font-weight:600;" id="subtotal-<?= $nid ?>">
                                            <?= number_format((float) $item['line_cost'], 2) ?> CR
                                        </td>
                                        <td>
                                            <button
                                                type="submit"
                                                form="remove-node-<?= $nid ?>"
                                                class="btn btn-outline btn-sm"
                                                title="<?= e(I18n::get('tray_remove_title', $currentLang)) ?>"
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
                            <?= e(I18n::get('tray_recalc_btn', $currentLang)) ?>
                        </button>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-title">
                        <span><?= e(I18n::get('tray_sec2_title', $currentLang)) ?></span>
                    </div>

                    <div class="filter-grid" style="margin-bottom:16px;">
                        <div class="form-group">
                            <label class="form-label" for="project_name"><?= e(I18n::get('tray_project_label', $currentLang)) ?></label>
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
                            <label class="form-label" for="start_time"><?= e(I18n::get('tray_start_label', $currentLang)) ?></label>
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

                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label" for="docker_image"><?= e(I18n::get('tray_preset_label', $currentLang)) ?></label>
                        <select id="docker_image" name="docker_image" class="form-control">
                            <?php foreach ($presets as $preset): ?>
                                <option
                                    value="<?= e($preset->value) ?>"
                                    <?= $hydratedTray['docker_image'] === $preset->value ? 'selected' : '' ?>
                                >
                                    <?= e($preset->shortLabel($currentLang)) ?> &mdash; [<?= e($preset->value) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label" for="startup_script"><?= e(I18n::get('tray_script_label', $currentLang)) ?></label>
                        <textarea
                            id="startup_script"
                            name="startup_script"
                            class="form-control"
                            placeholder="#!/usr/bin/env bash&#10;export CUDA_VISIBLE_DEVICES=0&#10;python3 train.py --epochs 10"
                        ><?= e($hydratedTray['startup_script']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ssh_public_key">
                            <?= e(I18n::get('tray_ssh_label', $currentLang)) ?>
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
                            <?= e(I18n::get('tray_ssh_hint', $currentLang)) ?>
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
                <span><?= e(I18n::get('tray_sec3_title', $currentLang)) ?></span>
            </div>

            <div class="summary-row">
                <span><?= e(I18n::get('tray_sum_nodes', $currentLang)) ?></span>
                <strong><?= (int) $hydratedTray['node_count'] ?> / <?= $maxNodesPerJob ?></strong>
            </div>
            <div class="summary-row">
                <span><?= e(I18n::get('tray_sum_start', $currentLang)) ?></span>
                <strong><?= e($hydratedTray['start_time_dt']->format('Y-m-d H:i')) ?></strong>
            </div>
            <div class="summary-row">
                <span><?= e(I18n::get('tray_sum_horizon', $currentLang)) ?></span>
                <strong><?= e(I18n::get('tray_sum_horizon_valid', $currentLang)) ?></strong>
            </div>

            <?php if ($currentUser !== null): ?>
                <div class="summary-row">
                    <span><?= e(I18n::get('tray_sum_balance', $currentLang)) ?></span>
                    <strong><?= number_format($userBalance, 2) ?> CR</strong>
                </div>
                <div class="summary-row total">
                    <span><?= e(I18n::get('tray_sum_burn', $currentLang)) ?></span>
                    <span id="grand-total-credits"><?= number_format($totalCost, 2) ?> CR</span>
                </div>
                <div class="summary-row" style="border-top:1px solid var(--border-color);margin-top:8px;">
                    <span><?= e(I18n::get('tray_sum_projected', $currentLang)) ?></span>
                    <strong style="color:<?= $remainingBalance < 0 ? 'var(--status-maint-fg)' : 'var(--status-online-fg)' ?>;">
                        <?= number_format($remainingBalance, 2) ?> CR
                    </strong>
                </div>

                <?php if ($maxConcurrentLeases !== null && $activeSystemLeaseCount >= $maxConcurrentLeases): ?>
                    <div class="flash flash-error" style="margin-top:16px;margin-bottom:0;">
                        <span>
                            <?= e(I18n::format('tray_hoarding_warn', $currentLang, [I18n::translateRole($effectiveRole->value, $currentLang), (int) $activeSystemLeaseCount])) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <button
                    type="submit"
                    form="job-spec-form"
                    class="btn btn-lg"
                    style="width:100%;margin-top:16px;"
                    <?= ($remainingBalance < 0 || ($maxConcurrentLeases !== null && $activeSystemLeaseCount >= $maxConcurrentLeases)) ? 'disabled' : '' ?>
                >
                    <?= e(I18n::get('tray_provision_btn', $currentLang)) ?>
                </button>
            <?php else: ?>
                <div class="summary-row total">
                    <span><?= e(I18n::get('tray_sum_burn', $currentLang)) ?></span>
                    <span id="grand-total-credits"><?= number_format($totalCost, 2) ?> CR</span>
                </div>
                <p style="font-size:13px;color:var(--fg-secondary);margin:16px 0;">
                    <?= e(I18n::get('tray_auth_required', $currentLang)) ?>
                </p>
                <a href="/login" class="btn btn-lg" style="width:100%;">
                    <?= e(I18n::get('tray_signin_btn', $currentLang)) ?>
                </a>
            <?php endif; ?>

            <div class="mono" style="margin-top:16px;padding-top:12px;border-top:1px solid var(--border-subtle);font-size:0.74rem;color:var(--fg-muted);line-height:1.55;">
                <strong><?= e(I18n::get('tray_acid_title', $currentLang)) ?></strong><br>
                <?= I18n::get('tray_acid_1', $currentLang) ?><br>
                <?= I18n::get('tray_acid_2', $currentLang) ?><br>
                <?= I18n::get('tray_acid_3', $currentLang) ?>
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
