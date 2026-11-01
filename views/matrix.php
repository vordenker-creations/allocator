<?php
declare(strict_types=1);

use VKU\Allocator\Service\I18n;

require __DIR__ . '/layout_top.php';

$stagedMap = [];
foreach ($hydratedTray['items'] as $stagedItem) {
    $stagedMap[(int) $stagedItem['node_id']] = $stagedItem;
}

$effectiveRole = $currentUserRole ?? \VKU\Allocator\Domain\Enum\UserRole::Student;
$maxNodesAllowed = $effectiveRole->maxNodesPerJob();
$maxConcurrentAllowed = $effectiveRole->maxConcurrentActiveLeases();
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e(I18n::get('matrix_title', $currentLang)) ?></h1>
        <p class="page-subtitle">
            <!-- 24-Hour Reservation Horizon -->
            <?= e(I18n::get('matrix_subtitle', $currentLang)) ?>
        </p>
    </div>
</div>

<div class="policy-bar mono">
    <span>
        <strong><?= e(I18n::get('policy_title', $currentLang)) ?></strong>
        <?= e(I18n::get('policy_summary', $currentLang)) ?>
    </span>
    <?php if ($currentUser !== null): ?>
        <span class="policy-status-pill">
            <span><?= e(I18n::get('policy_active_role', $currentLang)) ?></span>
            <strong><?= e(I18n::translateRole((string) $currentUser['role'], $currentLang)) ?></strong>
            <span>&bull; <?= (int) $activeSystemLeaseCount ?><?= $maxConcurrentAllowed !== null ? '/' . $maxConcurrentAllowed : '' ?> <?= e(I18n::get('policy_active_lease', $currentLang)) ?></span>
        </span>
    <?php else: ?>
        <span class="policy-status-pill">
            <span><?= e(I18n::get('policy_guest_prefix', $currentLang)) ?> <a href="/login"><?= e(I18n::get('policy_guest_signin', $currentLang)) ?></a> <?= e(I18n::get('policy_guest_suffix', $currentLang)) ?></span>
        </span>
    <?php endif; ?>
</div>

<?php if (isset($pveTelemetry)): ?>
    <!-- PROXMOX VE HYPERVISOR TELEMETRY -->
    <section class="pve-banner mono <?= !empty($pveTelemetry['is_live']) ? 'pve-live' : 'pve-simulated' ?>">
        <div class="pve-banner-header">
            <div class="pve-banner-title-group">
                <span class="pve-cockpit-title"><?= e(I18n::get('pve_banner_title', $currentLang)) ?></span>
                <span class="badge <?= !empty($pveTelemetry['is_live']) ? 'badge-online' : 'badge-busy' ?>">
                    <span class="status-dot"></span><?= e(I18n::translateTelemetrySource((string) ($pveTelemetry['telemetry_source'] ?? $pveTelemetry['status_message']), $currentLang)) ?>
                </span>
            </div>
            <div class="pve-endpoint-meta">
                <?= e(I18n::get('pve_host_label', $currentLang)) ?> <code><?= e($proxmoxHost ?? 'https://192.168.1.200:8006') ?></code> &bull; <?= e(I18n::get('pve_node_label', $currentLang)) ?> <strong><?= e($pveTelemetry['node_name'] ?? 'vor-server') ?></strong>
            </div>
        </div>
        <div class="pve-banner-grid">
            <div class="pve-stat">
                <span class="pve-label"><?= e(I18n::get('pve_cpu_hw', $currentLang)) ?></span>
                <span class="pve-val pve-val-hw"><?= e($pveTelemetry['cpu_model']) ?></span>
                <span class="pve-sub"><?= (int) $pveTelemetry['cpu_cores'] ?>C / <?= (int) $pveTelemetry['cpu_threads'] ?>T</span>
            </div>
            <div class="pve-stat">
                <span class="pve-label"><?= e(I18n::get('pve_live_cpu', $currentLang)) ?></span>
                <span class="pve-val"><?= number_format((float) $pveTelemetry['cpu_usage_pct'], 1) ?>%</span>
                <span class="pve-sub"><?= (int) $pveTelemetry['cpu_cores'] ?>C &bull; <?= (int) $pveTelemetry['cpu_threads'] ?>T</span>
            </div>
            <div class="pve-stat">
                <span class="pve-label"><?= e(I18n::get('pve_phys_ram', $currentLang)) ?></span>
                <span class="pve-val"><?= number_format((float) $pveTelemetry['ram_used_gb'], 1) ?> / <?= number_format((float) $pveTelemetry['ram_total_gb'], 1) ?> GB</span>
                <span class="pve-sub"><?= number_format((float) $pveTelemetry['ram_usage_pct'], 1) ?>%</span>
            </div>
            <div class="pve-stat">
                <span class="pve-label"><?= e(I18n::get('pve_load_uptime', $currentLang)) ?></span>
                <span class="pve-val"><?= e($pveTelemetry['uptime_formatted']) ?></span>
                <span class="pve-sub"><?= e($pveTelemetry['load_average']) ?></span>
            </div>
            <div class="pve-stat">
                <span class="pve-label"><?= e(I18n::get('pve_running_vms', $currentLang)) ?></span>
                <span class="pve-val"><?= (int) $pveTelemetry['running_vms'] ?> / <?= (int) $pveTelemetry['total_vms'] ?></span>
                <span class="pve-sub"><?= e(I18n::get('pve_vms_unit', $currentLang)) ?></span>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($filterError)): ?>
    <div class="flash flash-error">
        <span><?= e(I18n::translateServiceMessage((string) $filterError, $currentLang)) ?> <?= e(I18n::get('filter_reset_notice', $currentLang)) ?></span>
    </div>
<?php endif; ?>

<section class="panel panel-compact">
    <div class="panel-title">
        <span><?= e(I18n::get('filter_panel_title', $currentLang)) ?></span>
        <span class="mono" style="font-weight:400;font-size:0.74rem;color:var(--fg-muted);">
            <?= e(I18n::get('filter_checking_window', $currentLang)) ?> <?= e($windowStart->format('Y-m-d H:i')) ?> &rarr; <?= e($windowEnd->format('Y-m-d H:i')) ?> (<?= (int) $previewHours ?>h)
        </span>
    </div>
    <form method="GET" action="/" class="filter-grid">
        <div class="form-group">
            <label class="form-label" for="start_time"><?= e(I18n::get('filter_window_start', $currentLang)) ?></label>
            <input
                type="datetime-local"
                id="start_time"
                name="start_time"
                class="form-control"
                value="<?= e($windowStart->format('Y-m-d\TH:i')) ?>"
                min="<?= e($minStartStr ?? $defaultStartStr) ?>"
                max="<?= e($maxHorizonStr) ?>"
            >
        </div>

        <div class="form-group">
            <label class="form-label" for="hours"><?= e(I18n::get('filter_duration_preview', $currentLang)) ?></label>
            <input
                type="number"
                id="hours"
                name="hours"
                class="form-control"
                min="1"
                max="24"
                value="<?= (int) $previewHours ?>"
            >
        </div>

        <div class="form-group">
            <label class="form-label" for="min_vram"><?= e(I18n::get('filter_min_vram', $currentLang)) ?></label>
            <select id="min_vram" name="min_vram" class="form-control">
                <?php
                $vramOptions = [
                    0 => I18n::get('vram_opt_0', $currentLang),
                    24 => I18n::get('vram_opt_24', $currentLang),
                    48 => I18n::get('vram_opt_48', $currentLang),
                    80 => I18n::get('vram_opt_80', $currentLang),
                ];
                foreach ($vramOptions as $vramVal => $vramLabel):
                ?>
                    <option value="<?= $vramVal ?>" <?= (int) $minVram === $vramVal ? 'selected' : '' ?>>
                        <?= e($vramLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="tier_id"><?= e(I18n::get('filter_resource_tier', $currentLang)) ?></label>
            <select id="tier_id" name="tier_id" class="form-control">
                <option value=""><?= e(I18n::get('filter_all_tiers', $currentLang)) ?></option>
                <?php foreach ($tiers as $tier): ?>
                    <option value="<?= (int) $tier['id'] ?>" <?= $selectedTierId === (int) $tier['id'] ? 'selected' : '' ?>>
                        <?= e(I18n::translateTierName((string) ($tier['code'] ?? ''), (string) $tier['name'], $currentLang)) ?> (<?= e(I18n::format('filter_max_h', $currentLang, [(int) $tier['max_allocation_hours']])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="display:flex;flex-direction:row;gap:8px;">
            <button type="submit" class="btn" style="flex:1;"><?= e(I18n::get('filter_apply_btn', $currentLang)) ?></button>
            <a href="/" class="btn btn-outline"><?= e(I18n::get('filter_reset_btn', $currentLang)) ?></a>
        </div>
    </form>
</section>

<?php foreach ($tiers as $tier): ?>
    <?php
    $tierId = (int) $tier['id'];
    $tierNodes = $nodesByTier[$tierId] ?? [];
    if ($tierNodes === []) {
        continue;
    }
    $tierCode = (string) ($tier['code'] ?? '');
    $tierMaxHours = (int) $tier['max_allocation_hours'];
    $clampedHoursForTier = max(1, min((int) $previewHours, $tierMaxHours));
    ?>
    <section class="tier-section">
        <div class="tier-header">
            <div>
                <h2 class="tier-name"><?= e(I18n::translateTierName($tierCode, (string) $tier['name'], $currentLang)) ?></h2>
                <p class="tier-desc"><?= e(I18n::translateTierDescription($tierCode, (string) $tier['description'], $currentLang)) ?></p>
            </div>
            <span class="tier-meta">
                <?= e(I18n::get('tier_code_label', $currentLang)) ?> <strong><?= e($tierCode) ?></strong> &bull;
                <?= e(I18n::get('tier_max_duration', $currentLang)) ?> <strong><?= $tierMaxHours ?>h</strong>
            </span>
        </div>

        <div class="node-grid">
            <?php foreach ($tierNodes as $node): ?>
                <?php
                $nodeId = (int) $node['id'];
                $hwStatus = (string) $node['status'];
                $overlapCount = (int) $node['overlapping_leases_count'];
                $isStaged = isset($stagedMap[$nodeId]);
                $rate = (float) $node['credits_per_hour'];
                $estimatedCost = round($clampedHoursForTier * $rate, 2);
                $telemetry = $nodeTelemetryMap[$nodeId] ?? \VKU\Allocator\Service\ProxmoxTelemetryService::simulateCampusNodeTelemetry($node, false);
                $specRamGb = $node['hostname'] === 'vor-server' && isset($telemetry['ram_total_gb']) ? (int) $telemetry['ram_total_gb'] : (int) $node['ram_gb'];

                if ($hwStatus !== 'ONLINE') {
                    $badgeClass = 'badge-maintenance';
                    $badgeLabel = I18n::translateStatus($hwStatus, $currentLang);
                    $canStage = false;
                } elseif ($overlapCount > 0) {
                    $badgeClass = 'badge-busy';
                    $badgeLabel = I18n::get('status_busy_window', $currentLang);
                    $canStage = false;
                } else {
                    $badgeClass = 'badge-online';
                    $badgeLabel = I18n::get('status_online_free', $currentLang);
                    $canStage = true;
                }
                ?>
                <article class="node-card <?= $isStaged ? 'is-staged' : '' ?>">
                    <div>
                        <div class="node-card-top">
                            <div>
                                <div class="node-hostname"><?= e($node['hostname']) ?></div>
                                <div class="node-ip">IP: <?= e($node['ip_address']) ?> &bull; Node #<?= $nodeId ?></div>
                            </div>
                            <span class="badge <?= $badgeClass ?>"><span class="status-dot"></span><?= e($badgeLabel) ?></span>
                        </div>

                        <div class="spec-list">
                            <div class="spec-item">
                                <span><?= e(I18n::get('spec_accelerator', $currentLang)) ?></span>
                                <strong><?= e($node['gpu_model'] ?? I18n::get('spec_cpu_only', $currentLang)) ?></strong>
                            </div>
                            <div class="spec-item">
                                <span><?= e(I18n::get('spec_vram_ram', $currentLang)) ?></span>
                                <strong><?= (int) $node['vram_gb'] ?> GB VRAM / <?= $specRamGb ?> GB RAM</strong>
                            </div>
                            <div class="spec-item">
                                <span><?= e(I18n::get('spec_cpu_threads', $currentLang)) ?></span>
                                <strong><?= e(I18n::format('spec_cores_unit', $currentLang, [(int) $node['cpu_cores']])) ?></strong>
                            </div>
                            <div class="spec-item">
                                <span><?= e(I18n::format('spec_burn_rate', $currentLang, [$tierMaxHours])) ?></span>
                                <strong><?= number_format($rate, 2) ?> CR / hr</strong>
                            </div>
                        </div>

                        <div class="telemetry-block">
                            <div class="meter-row">
                                <div class="meter-header">
                                    <span><?= e(I18n::get('meter_cpu_load', $currentLang)) ?></span>
                                    <span class="mono"><?= number_format((float) $telemetry['cpu_usage_pct'], 1) ?>%</span>
                                </div>
                                <div class="meter-track">
                                    <div class="meter-fill meter-cpu" style="width: <?= min(100, max(0, (float) $telemetry['cpu_usage_pct'])) ?>%;"></div>
                                </div>
                            </div>
                            <div class="meter-row">
                                <div class="meter-header">
                                    <span><?= e(I18n::get('meter_system_ram', $currentLang)) ?></span>
                                    <span class="mono"><?= number_format((float) $telemetry['ram_used_gb'], 1) ?> / <?= number_format((float) $telemetry['ram_total_gb'], 0) ?> GB (<?= number_format((float) $telemetry['ram_usage_pct'], 1) ?>%)</span>
                                </div>
                                <div class="meter-track">
                                    <div class="meter-fill meter-ram" style="width: <?= min(100, max(0, (float) $telemetry['ram_usage_pct'])) ?>%;"></div>
                                </div>
                            </div>
                            <div class="meter-row">
                                <div class="meter-header">
                                    <span><?= e(I18n::get('meter_vram_alloc', $currentLang)) ?></span>
                                    <span class="mono"><?= (int) $telemetry['vram_total_gb'] > 0 ? (number_format((float) $telemetry['vram_allocated_gb'], 1) . ' / ' . (int) $telemetry['vram_total_gb'] . ' GB (' . number_format((float) $telemetry['vram_usage_pct'], 1) . '%)') : e(I18n::get('meter_na_cpu', $currentLang)) ?></span>
                                </div>
                                <div class="meter-track">
                                    <div class="meter-fill meter-vram" style="width: <?= min(100, max(0, (float) $telemetry['vram_usage_pct'])) ?>%;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="availability-section">
                            <div class="availability-header">
                                <span><?= e(I18n::get('avail_horizon_label', $currentLang)) ?></span>
                                <span class="mono" style="font-size:0.68rem;color:var(--fg-muted);text-transform:none;"><?= e(I18n::get('avail_now_plus_24', $currentLang)) ?></span>
                            </div>
                            <div class="availability-strip" role="group" aria-label="24-hour availability">
                                <?php foreach ($nodeAvailability[$nodeId] ?? [] as $slot): ?>
                                    <div
                                        class="availability-segment is-<?= e($slot['type'] ?? strtolower($slot['status'])) ?> <?= e($slot['class'] ?? '') ?>"
                                        title="<?= e(I18n::translateTooltip((string) $slot['tooltip'], $currentLang)) ?>"
                                    ></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="availability-legend">
                                <span class="legend-item"><span class="legend-color is-free"></span> <?= e(I18n::get('legend_free', $currentLang)) ?></span>
                                <span class="legend-item"><span class="legend-color is-booked"></span> <?= e(I18n::get('legend_booked', $currentLang)) ?></span>
                                <span class="legend-item"><span class="legend-color is-maintenance"></span> <?= e(I18n::get('legend_maintenance', $currentLang)) ?></span>
                            </div>
                        </div>

                        <?php if ($overlapCount > 0 && !empty($node['busy_until'])): ?>
                            <p class="mono" style="margin-top:8px;font-size:0.74rem;color:var(--status-busy-fg);">
                                <?= e(I18n::get('reserved_until_prefix', $currentLang)) ?> <?= e((string) $node['busy_until']) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="node-card-footer">
                        <div>
                            <div class="cost-label">
                                <?= e(I18n::format('est_window_cost', $currentLang, [$clampedHoursForTier])) ?>
                            </div>
                            <div class="cost-value">
                                <?= number_format($estimatedCost, 2) ?> CR
                            </div>
                        </div>

                        <?php if ($isStaged): ?>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <span class="badge badge-scheduled"><span class="status-dot"></span><?= e(I18n::get('status_staged', $currentLang)) ?> (<?= (int) $stagedMap[$nodeId]['hours'] ?>h)</span>
                                <a href="/tray" class="btn btn-outline btn-sm"><?= e(I18n::get('btn_configure', $currentLang)) ?></a>
                            </div>
                        <?php elseif ($canStage): ?>
                            <form method="POST" action="/tray/add" style="display:flex;gap:8px;align-items:center;">
                                <input type="hidden" name="node_id" value="<?= $nodeId ?>">
                                <input type="hidden" name="start_time" value="<?= e($windowStart->format('Y-m-d\TH:i')) ?>">
                                <input type="hidden" name="redirect_to" value="/tray">
                                <input
                                    type="number"
                                    name="hours"
                                    min="1"
                                    max="<?= $tierMaxHours ?>"
                                    value="<?= $clampedHoursForTier ?>"
                                    class="form-control form-control-sm"
                                    style="width:64px;"
                                    title="<?= e(I18n::format('hours_input_title', $currentLang, [$tierMaxHours])) ?>"
                                >
                                <button type="submit" class="btn btn-sm"><?= e(I18n::get('btn_stage_node', $currentLang)) ?></button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline btn-sm" disabled><?= e(I18n::get('btn_unavailable', $currentLang)) ?></button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
