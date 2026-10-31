<?php
declare(strict_types=1);

require __DIR__ . '/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">HPC Cluster Administration &amp; Override Console</h1>
        <p class="page-subtitle">
            Loop D: Manage compute node operational states, register new hardware nodes, adjust student credit quotas, and audit cluster-wide leases.
        </p>
    </div>
</div>

<div class="split-grid">
    <section class="panel">
        <div class="panel-title">
            <span>1. Hardware Node Fleet Status Override (<?= count($nodes) ?> Nodes)</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hostname / IP</th>
                        <th>Tier &amp; Specs</th>
                        <th>Rate</th>
                        <th>State</th>
                        <th>Set State</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nodes as $node): ?>
                        <?php
                        $nid = (int) $node['id'];
                        $st = (string) $node['status'];
                        ?>
                        <tr>
                            <td class="mono">#<?= $nid ?></td>
                            <td class="mono">
                                <strong><?= e($node['hostname']) ?></strong><br>
                                <span style="font-size:0.74rem;color:var(--fg-muted);"><?= e($node['ip_address']) ?></span>
                            </td>
                            <td class="mono" style="font-size:0.78rem;">
                                <?= e($node['tier_name']) ?><br>
                                <span style="color:var(--fg-muted);">
                                    <?= e($node['gpu_model'] ?? 'CPU Only') ?> (<?= (int) $node['vram_gb'] ?>G VRAM / <?= (int) $node['ram_gb'] ?>G RAM)
                                </span>
                            </td>
                            <td class="mono"><?= number_format((float) $node['credits_per_hour'], 2) ?> CR/h</td>
                            <td>
                                <span class="badge badge-<?= strtolower($st) ?>"><?= e($st) ?></span>
                            </td>
                            <td>
                                <form method="POST" action="/admin/node/status" style="display:flex;gap:0.35rem;">
                                    <input type="hidden" name="node_id" value="<?= $nid ?>">
                                    <select name="status" class="form-control" style="padding:0.28rem 0.45rem;font-size:0.76rem;width:130px;">
                                        <?php foreach (['ONLINE', 'MAINTENANCE', 'OFFLINE'] as $opt): ?>
                                            <option value="<?= $opt ?>" <?= $st === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-outline btn-sm">Apply</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div>
        <section class="panel">
            <div class="panel-title">
                <span>2. Top Up / Adjust User Compute Credits</span>
            </div>
            <form method="POST" action="/admin/user/topup">
                <div class="form-group" style="margin-bottom:0.85rem;">
                    <label class="form-label" for="user_id">Select Student / Researcher Account</label>
                    <select id="user_id" name="user_id" class="form-control" required>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>">
                                [<?= e($u['role']) ?>] <?= e($u['student_code']) ?> &mdash; <?= e($u['full_name']) ?> (<?= number_format((float) $u['credit_balance'], 2) ?> CR)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:0.85rem;">
                    <label class="form-label" for="amount">Credit Delta (+ to Top Up, - to Deduct)</label>
                    <input
                        type="number"
                        step="0.01"
                        id="amount"
                        name="amount"
                        class="form-control"
                        value="100.00"
                        required
                    >
                </div>

                <button type="submit" class="btn" style="width:100%;">Commit Credit Quota Adjustment</button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-title">
                <span>3. Register New Hardware Node</span>
            </div>
            <form method="POST" action="/admin/node/create">
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label class="form-label" for="new_tier_id">Resource Tier</label>
                    <select id="new_tier_id" name="tier_id" class="form-control" required>
                        <?php foreach ($tiers as $t): ?>
                            <option value="<?= (int) $t['id'] ?>">
                                <?= e($t['name']) ?> (Max <?= (int) $t['max_allocation_hours'] ?>h)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-grid" style="margin-bottom:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="hostname">Hostname (Unique)</label>
                        <input type="text" id="hostname" name="hostname" class="form-control" placeholder="vku-h100-01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ip_address">Internal IP Address</label>
                        <input type="text" id="ip_address" name="ip_address" class="form-control" placeholder="10.20.10.50" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label class="form-label" for="gpu_model">GPU / Accelerator Model (Leave empty for CPU node)</label>
                    <input type="text" id="gpu_model" name="gpu_model" class="form-control" placeholder="NVIDIA H100 PCIe 80GB">
                </div>

                <div class="filter-grid" style="margin-bottom:0.9rem;">
                    <div class="form-group">
                        <label class="form-label" for="vram_gb">VRAM (GB)</label>
                        <input type="number" id="vram_gb" name="vram_gb" class="form-control" value="80" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ram_gb">System RAM (GB)</label>
                        <input type="number" id="ram_gb" name="ram_gb" class="form-control" value="256" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cpu_cores">CPU Cores</label>
                        <input type="number" id="cpu_cores" name="cpu_cores" class="form-control" value="64" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="credits_per_hour">Rate (CR/hr)</label>
                        <input type="number" step="0.50" id="credits_per_hour" name="credits_per_hour" class="form-control" value="55.00" min="0.5" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-outline" style="width:100%;">+ Provision Hardware Node into Fleet</button>
            </form>
        </section>
    </div>
</div>

<section class="panel">
    <div class="panel-title">
        <span>4. Cluster-Wide Lease Ledger (Recent <?= count($recentLeases) ?> Leases)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Lease</th>
                    <th>User</th>
                    <th>Node / Tier</th>
                    <th>Project</th>
                    <th>Time Window</th>
                    <th>Port</th>
                    <th>Rate / Refund</th>
                    <th>Status</th>
                    <th>Admin Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLeases as $rl): ?>
                    <?php
                    $lid = (int) $rl['lease_id'];
                    $lStatus = (string) $rl['lease_status'];
                    ?>
                    <tr>
                        <td class="mono">#<?= $lid ?></td>
                        <td class="mono">
                            <strong><?= e($rl['student_code']) ?></strong>
                            <span class="role-tag"><?= e($rl['user_role']) ?></span><br>
                            <span style="font-size:0.74rem;color:var(--fg-muted);"><?= e($rl['full_name']) ?></span>
                        </td>
                        <td class="mono">
                            <strong><?= e($rl['hostname']) ?></strong><br>
                            <span style="font-size:0.74rem;color:var(--fg-muted);"><?= e($rl['tier_name']) ?></span>
                        </td>
                        <td class="mono"><?= e($rl['project_name']) ?></td>
                        <td class="mono" style="font-size:0.76rem;">
                            <?= e((string) $rl['start_time']) ?><br>
                            &rarr; <?= e((string) $rl['end_time']) ?>
                        </td>
                        <td class="mono"><?= (int) $rl['assigned_port'] ?></td>
                        <td class="mono" style="font-size:0.78rem;">
                            <?= number_format((float) $rl['hourly_rate_snapshot'], 2) ?> CR/h<br>
                            <?php if ((float) $rl['refunded_credits'] > 0): ?>
                                <span style="color:var(--status-online-fg);">Refund: <?= number_format((float) $rl['refunded_credits'], 2) ?> CR</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= strtolower($lStatus) ?>"><?= e($lStatus) ?></span>
                        </td>
                        <td>
                            <?php if (in_array($lStatus, ['ACTIVE', 'SCHEDULED'], true)): ?>
                                <form method="POST" action="/lease/terminate" onsubmit="return confirm('Admin force-terminate lease #<?= $lid ?> and refund unused full hours?');">
                                    <input type="hidden" name="lease_id" value="<?= $lid ?>">
                                    <input type="hidden" name="return_to" value="/admin">
                                    <button type="submit" class="btn btn-danger btn-sm">Force Terminate</button>
                                </form>
                            <?php else: ?>
                                <span class="mono" style="font-size:0.74rem;color:var(--fg-muted);">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
