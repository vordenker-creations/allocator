<?php
declare(strict_types=1);

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
        <h1 class="page-title">Campus Compute &amp; Hardware Node Matrix</h1>
        <p class="page-subtitle">
            Inspect live cluster telemetry, filter by target time window (24-Hour Reservation Horizon) or VRAM, and stage hardware into your Job Spec Tray.
        </p>
    </div>
</div>

<div class="policy-bar mono">
    <div>
        <strong>Anti-Hoarding Policy:</strong>
        STUDENT: max 1 node/job &amp; max 1 active/scheduled lease system-wide &bull;
        RESEARCHER / ADMIN: max 3 nodes/job &bull;
        Booking Horizon: 24-Hour Reservation Horizon
    </div>
    <div>
        <?php if ($currentUser !== null): ?>
            Active Role: <strong><?= e($currentUser['role']) ?></strong>
            (<?= (int) $activeSystemLeaseCount ?><?= $maxConcurrentAllowed !== null ? '/' . $maxConcurrentAllowed : '' ?> active lease)
        <?php else: ?>
            Browsing as Public Guest &mdash; <a href="/login">Sign in</a> for Researcher/Admin quotas
        <?php endif; ?>
    </div>
</div>

<?php if (isset($pveTelemetry)): ?>
    <section class="pve-banner mono <?= !empty($pveTelemetry['is_live']) ? 'pve-live' : 'pve-simulated' ?>">
        <div class="pve-banner-header">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <span class="status-dot"></span>
                <strong>PROXMOX VE HYPERVISOR TELEMETRY</strong>
                <span class="badge <?= !empty($pveTelemetry['is_live']) ? 'badge-online' : 'badge-busy' ?>" style="font-size:0.68rem;">
                    <span class="status-dot"></span><?= e($pveTelemetry['telemetry_source'] ?? $pveTelemetry['status_message']) ?>
                </span>
            </div>
            <div style="font-size:0.78rem;color:var(--fg-muted);">
                Host: <code><?= e($proxmoxHost ?? 'https://192.168.1.200:8006') ?></code> &bull; Node: <strong><?= e($pveTelemetry['node_name'] ?? 'vor-server') ?></strong>
            </div>
        </div>
        <div class="pve-banner-grid">
            <div class="pve-stat">
                <span class="pve-label">CPU Hardware</span>
                <span class="pve-val"><?= e($pveTelemetry['cpu_model']) ?> (<?= (int) $pveTelemetry['cpu_cores'] ?>C/<?= (int) $pveTelemetry['cpu_threads'] ?>T)</span>
            </div>
            <div class="pve-stat">
                <span class="pve-label">Live CPU Load</span>
                <span class="pve-val"><?= number_format((float) $pveTelemetry['cpu_usage_pct'], 1) ?>%</span>
            </div>
            <div class="pve-stat">
                <span class="pve-label">Physical RAM</span>
                <span class="pve-val"><?= number_format((float) $pveTelemetry['ram_used_gb'], 1) ?> / <?= number_format((float) $pveTelemetry['ram_total_gb'], 1) ?> GB (<?= number_format((float) $pveTelemetry['ram_usage_pct'], 1) ?>%)</span>
            </div>
            <div class="pve-stat">
                <span class="pve-label">Load Avg &amp; Uptime</span>
                <span class="pve-val"><?= e($pveTelemetry['load_average']) ?> &bull; <?= e($pveTelemetry['uptime_formatted']) ?></span>
            </div>
            <div class="pve-stat">
                <span class="pve-label">Running QEMU VMs</span>
                <span class="pve-val"><?= (int) $pveTelemetry['running_vms'] ?> / <?= (int) $pveTelemetry['total_vms'] ?> VMs</span>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($filterError)): ?>
    <div class="flash flash-error">
        <span><?= e($filterError) ?> (Resetting preview window to current time).</span>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel-title">
        <span>Target Window &amp; Hardware Spec Filter</span>
        <span class="mono" style="font-weight:400;font-size:0.78rem;color:var(--fg-muted);">
            Checking Window: <?= e($windowStart->format('Y-m-d H:i')) ?> &rarr; <?= e($windowEnd->format('Y-m-d H:i')) ?> (<?= (int) $previewHours ?>h)
        </span>
    </div>
    <form method="GET" action="/" class="filter-grid">
        <div class="form-group">
            <label class="form-label" for="start_time">Window Start (24-Hour Reservation Horizon)</label>
            <input
                type="datetime-local"
                id="start_time"
                name="start_time"
                class="form-control"
                value="<?= e($windowStart->format('Y-m-d\TH:i')) ?>"
                min="<?= e($defaultStartStr) ?>"
                max="<?= e($maxHorizonStr) ?>"
            >
        </div>

        <div class="form-group">
            <label class="form-label" for="hours">Duration Preview (Hours)</label>
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
            <label class="form-label" for="min_vram">Minimum VRAM (GB)</label>
            <select id="min_vram" name="min_vram" class="form-control">
                <?php foreach ([0 => 'Any VRAM (Include CPU)', 24 => '24 GB+ (RTX 4090 / Orin)', 48 => '48 GB+ (L40S / Orin 64)', 80 => '80 GB (A100 SXM4)'] as $vramVal => $vramLabel): ?>
                    <option value="<?= $vramVal ?>" <?= (int) $minVram === $vramVal ? 'selected' : '' ?>>
                        <?= e($vramLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="tier_id">Resource Tier</label>
            <select id="tier_id" name="tier_id" class="form-control">
                <option value="">All Resource Tiers</option>
                <?php foreach ($tiers as $tier): ?>
                    <option value="<?= (int) $tier['id'] ?>" <?= $selectedTierId === (int) $tier['id'] ? 'selected' : '' ?>>
                        <?= e($tier['name']) ?> (Max <?= (int) $tier['max_allocation_hours'] ?>h)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="display:flex;flex-direction:row;gap:0.5rem;">
            <button type="submit" class="btn" style="flex:1;">Apply Window</button>
            <a href="/" class="btn btn-outline">Reset</a>
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
    $tierMaxHours = (int) $tier['max_allocation_hours'];
    $clampedHoursForTier = max(1, min((int) $previewHours, $tierMaxHours));
    ?>
    <section class="tier-section">
        <div class="tier-header">
            <div>
                <h2 class="tier-name"><?= e($tier['name']) ?></h2>
                <p style="font-size:0.84rem;color:var(--fg-secondary);"><?= e($tier['description']) ?></p>
            </div>
            <span class="tier-meta">
                Tier Code: <strong><?= e($tier['code']) ?></strong> &bull;
                Max Lease Duration: <strong><?= $tierMaxHours ?>h</strong>
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
                    $badgeLabel = $hwStatus;
                    $canStage = false;
                } elseif ($overlapCount > 0) {
                    $badgeClass = 'badge-busy';
                    $badgeLabel = 'BUSY IN WINDOW';
                    $canStage = false;
                } else {
                    $badgeClass = 'badge-online';
                    $badgeLabel = 'ONLINE // FREE';
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

                        <div class="spec-list" style="margin-top:0.85rem;">
                            <div class="spec-item">
                                <span>Accelerator / GPU</span>
                                <strong><?= e($node['gpu_model'] ?? 'CPU-Only Node') ?></strong>
                            </div>
                            <div class="spec-item">
                                <span>VRAM / System RAM</span>
                                <strong><?= (int) $node['vram_gb'] ?> GB VRAM / <?= $specRamGb ?> GB RAM</strong>
                            </div>
                            <div class="spec-item">
                                <span>CPU Threads</span>
                                <strong><?= (int) $node['cpu_cores'] ?> Cores</strong>
                            </div>
                            <div class="spec-item">
                                <span>Burn Rate (Max <?= $tierMaxHours ?>h)</span>
                                <strong><?= number_format($rate, 2) ?> CR / hr</strong>
                            </div>
                        </div>

                        <div class="telemetry-block">
                            <div class="meter-row">
                                <div class="meter-header">
                                    <span>CPU Load</span>
                                    <span class="mono font-bold"><?= number_format((float) $telemetry['cpu_usage_pct'], 1) ?>%</span>
                                </div>
                                <div class="meter-track">
                                    <div class="meter-fill meter-cpu" style="width: <?= min(100, max(0, (float) $telemetry['cpu_usage_pct'])) ?>%;"></div>
                                </div>
                            </div>
                            <div class="meter-row">
                                <div class="meter-header">
                                    <span>System RAM</span>
                                    <span class="mono font-bold"><?= number_format((float) $telemetry['ram_used_gb'], 1) ?> / <?= number_format((float) $telemetry['ram_total_gb'], 0) ?> GB (<?= number_format((float) $telemetry['ram_usage_pct'], 1) ?>%)</span>
                                </div>
                                <div class="meter-track">
                                    <div class="meter-fill meter-ram" style="width: <?= min(100, max(0, (float) $telemetry['ram_usage_pct'])) ?>%;"></div>
                                </div>
                            </div>
                            <div class="meter-row">
                                <div class="meter-header">
                                    <span>VRAM Allocation</span>
                                    <span class="mono font-bold"><?= (int) $telemetry['vram_total_gb'] > 0 ? (number_format((float) $telemetry['vram_allocated_gb'], 1) . ' / ' . (int) $telemetry['vram_total_gb'] . ' GB (' . number_format((float) $telemetry['vram_usage_pct'], 1) . '%)') : 'N/A (CPU Node)' ?></span>
                                </div>
                                <div class="meter-track">
                                    <div class="meter-fill meter-vram" style="width: <?= min(100, max(0, (float) $telemetry['vram_usage_pct'])) ?>%;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="availability-section">
                            <div class="availability-header">
                                <span>24h Availability Horizon</span>
                                <span class="mono" style="font-size:0.7rem;color:var(--fg-muted);">Now &rarr; +24h</span>
                            </div>
                            <div class="availability-strip" role="group" aria-label="24-hour availability">
                                <?php foreach ($nodeAvailability[$nodeId] ?? [] as $slot): ?>
                                    <div
                                        class="availability-segment is-<?= e($slot['type'] ?? strtolower($slot['status'])) ?> <?= e($slot['class'] ?? '') ?>"
                                        title="<?= e($slot['tooltip']) ?>"
                                    ></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="availability-legend">
                                <span class="legend-item"><span class="legend-color is-free"></span> Free</span>
                                <span class="legend-item"><span class="legend-color is-booked"></span> Booked</span>
                                <span class="legend-item"><span class="legend-color is-maintenance"></span> Maintenance</span>
                            </div>
                        </div>

                        <?php if ($overlapCount > 0 && !empty($node['busy_until'])): ?>
                            <p class="mono" style="margin-top:0.6rem;font-size:0.76rem;color:var(--status-busy-fg);">
                                Reserved in window until <?= e((string) $node['busy_until']) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="node-card-footer">
                        <div class="mono">
                            <div style="font-size:0.72rem;color:var(--fg-muted);">
                                Est. Window Cost (<?= $clampedHoursForTier ?>h)
                            </div>
                            <div style="font-size:0.95rem;font-weight:700;">
                                <?= number_format($estimatedCost, 2) ?> CR
                            </div>
                        </div>

                        <?php if ($isStaged): ?>
                            <div style="display:flex;gap:0.4rem;align-items:center;">
                                <span class="badge badge-scheduled"><span class="status-dot"></span>STAGED (<?= (int) $stagedMap[$nodeId]['hours'] ?>h)</span>
                                <a href="/tray" class="btn btn-outline btn-sm">Configure</a>
                            </div>
                        <?php elseif ($canStage): ?>
                            <form method="POST" action="/tray/add" style="display:flex;gap:0.4rem;align-items:center;">
                                <input type="hidden" name="node_id" value="<?= $nodeId ?>">
                                <input type="hidden" name="start_time" value="<?= e($windowStart->format('Y-m-d\TH:i')) ?>">
                                <input type="hidden" name="redirect_to" value="/tray">
                                <input
                                    type="number"
                                    name="hours"
                                    min="1"
                                    max="<?= $tierMaxHours ?>"
                                    value="<?= $clampedHoursForTier ?>"
                                    class="form-control"
                                    style="width:68px;padding:0.35rem 0.45rem;font-size:0.8rem;"
                                    title="Hours (1 to <?= $tierMaxHours ?>h)"
                                >
                                <button type="submit" class="btn btn-sm">+ Stage Node</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline btn-sm" disabled>Unavailable</button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
