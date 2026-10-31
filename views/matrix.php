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
            Loop A: Inspect live cluster telemetry, filter by target time window (24h horizon) or VRAM, and stage hardware into your Job Spec Tray.
        </p>
    </div>
    <div>
        <a href="/tray" class="btn">
            Open Staging Tray (<?= (int) $hydratedTray['node_count'] ?> / <?= $maxNodesAllowed ?> Nodes &bull; <?= number_format((float) $hydratedTray['total_credits'], 2) ?> CR)
        </a>
    </div>
</div>

<div class="policy-bar mono">
    <div>
        <strong>Anti-Hoarding Policy:</strong>
        STUDENT: max 1 node/job &amp; max 1 active/scheduled lease system-wide &bull;
        RESEARCHER / ADMIN: max 3 nodes/job &bull;
        Booking Horizon: &le; 24h from NOW()
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
            <label class="form-label" for="start_time">Window Start (&le; 24h Horizon)</label>
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
                            <span class="badge <?= $badgeClass ?>"><?= e($badgeLabel) ?></span>
                        </div>

                        <div class="spec-list" style="margin-top:0.85rem;">
                            <div class="spec-item">
                                <span>Accelerator / GPU</span>
                                <strong><?= e($node['gpu_model'] ?? 'CPU-Only Node') ?></strong>
                            </div>
                            <div class="spec-item">
                                <span>VRAM / System RAM</span>
                                <strong><?= (int) $node['vram_gb'] ?> GB VRAM / <?= (int) $node['ram_gb'] ?> GB RAM</strong>
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
                                <span class="badge badge-scheduled">STAGED (<?= (int) $stagedMap[$nodeId]['hours'] ?>h)</span>
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
