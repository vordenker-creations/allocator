<?php
declare(strict_types=1);

use VKU\Allocator\Service\I18n;

require __DIR__ . '/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e(I18n::get('admin_title', $currentLang)) ?></h1>
        <p class="page-subtitle">
            <?= e(I18n::get('admin_subtitle', $currentLang)) ?>
        </p>
    </div>
</div>

<div class="split-grid">
    <section class="panel">
        <div class="panel-title">
            <span><?= e(I18n::format('admin_sec1_title', $currentLang, [count($nodes)])) ?></span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><?= e(I18n::get('th_id', $currentLang)) ?></th>
                        <th><?= e(I18n::get('th_hostname_ip', $currentLang)) ?></th>
                        <th><?= e(I18n::get('th_tier_specs', $currentLang)) ?></th>
                        <th><?= e(I18n::get('th_rate', $currentLang)) ?></th>
                        <th><?= e(I18n::get('th_state', $currentLang)) ?></th>
                        <th><?= e(I18n::get('th_set_state', $currentLang)) ?></th>
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
                                <?= e(I18n::translateTierName((string) ($node['tier_code'] ?? ''), (string) $node['tier_name'], $currentLang)) ?><br>
                                <span style="color:var(--fg-muted);">
                                    <?= e($node['gpu_model'] ?? I18n::get('admin_cpu_only_short', $currentLang)) ?> (<?= (int) $node['vram_gb'] ?>G VRAM / <?= (int) $node['ram_gb'] ?>G RAM)
                                </span>
                            </td>
                            <td class="mono"><?= number_format((float) $node['credits_per_hour'], 2) ?> CR/h</td>
                            <td>
                                <span class="badge badge-<?= strtolower($st) ?>"><span class="status-dot"></span><?= e(I18n::translateStatus($st, $currentLang)) ?></span>
                            </td>
                            <td>
                                <form method="POST" action="/admin/node/status" style="display:flex;gap:6px;align-items:center;">
                                    <input type="hidden" name="node_id" value="<?= $nid ?>">
                                    <select name="status" class="form-control form-control-sm" style="width:136px;">
                                        <?php foreach (['ONLINE', 'MAINTENANCE', 'OFFLINE'] as $opt): ?>
                                            <option value="<?= $opt ?>" <?= $st === $opt ? 'selected' : '' ?>><?= e(I18n::translateStatus($opt, $currentLang)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-outline btn-sm"><?= e(I18n::get('btn_apply', $currentLang)) ?></button>
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
                <span><?= e(I18n::get('admin_sec2_title', $currentLang)) ?></span>
            </div>
            <form method="POST" action="/admin/user/topup">
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" for="user_id"><?= e(I18n::get('admin_select_user', $currentLang)) ?></label>
                    <select id="user_id" name="user_id" class="form-control" required>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>">
                                [<?= e(I18n::translateRole((string) $u['role'], $currentLang)) ?>] <?= e($u['student_code']) ?> &mdash; <?= e($u['full_name']) ?> (<?= number_format((float) $u['credit_balance'], 2) ?> CR)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="amount"><?= e(I18n::get('admin_credit_delta', $currentLang)) ?></label>
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

                <button type="submit" class="btn" style="width:100%;"><?= e(I18n::get('admin_commit_credit_btn', $currentLang)) ?></button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-title">
                <span><?= e(I18n::get('admin_sec3_title', $currentLang)) ?></span>
            </div>
            <form method="POST" action="/admin/node/create">
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" for="new_tier_id"><?= e(I18n::get('filter_resource_tier', $currentLang)) ?></label>
                    <select id="new_tier_id" name="tier_id" class="form-control" required>
                        <?php foreach ($tiers as $t): ?>
                            <option value="<?= (int) $t['id'] ?>">
                                <?= e(I18n::translateTierName((string) ($t['code'] ?? ''), (string) $t['name'], $currentLang)) ?> (<?= e(I18n::format('filter_max_h', $currentLang, [(int) $t['max_allocation_hours']])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-grid" style="margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label" for="hostname"><?= e(I18n::get('admin_hostname_label', $currentLang)) ?></label>
                        <input type="text" id="hostname" name="hostname" class="form-control" placeholder="vku-h100-01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ip_address"><?= e(I18n::get('admin_ip_label', $currentLang)) ?></label>
                        <input type="text" id="ip_address" name="ip_address" class="form-control" placeholder="10.20.10.50" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" for="gpu_model"><?= e(I18n::get('admin_gpu_label', $currentLang)) ?></label>
                    <input type="text" id="gpu_model" name="gpu_model" class="form-control" placeholder="NVIDIA H100 PCIe 80GB">
                </div>

                <div class="filter-grid" style="margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label" for="vram_gb"><?= e(I18n::get('admin_vram_label', $currentLang)) ?></label>
                        <input type="number" id="vram_gb" name="vram_gb" class="form-control" value="80" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ram_gb"><?= e(I18n::get('admin_ram_label', $currentLang)) ?></label>
                        <input type="number" id="ram_gb" name="ram_gb" class="form-control" value="256" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cpu_cores"><?= e(I18n::get('admin_cores_label', $currentLang)) ?></label>
                        <input type="number" id="cpu_cores" name="cpu_cores" class="form-control" value="64" min="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="credits_per_hour"><?= e(I18n::get('admin_rate_label', $currentLang)) ?></label>
                        <input type="number" step="0.50" id="credits_per_hour" name="credits_per_hour" class="form-control" value="55.00" min="0.5" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-outline" style="width:100%;"><?= e(I18n::get('admin_create_node_btn', $currentLang)) ?></button>
            </form>
        </section>
    </div>
</div>

<section class="panel">
    <div class="panel-title">
        <span><?= e(I18n::format('admin_sec4_title', $currentLang, [count($recentLeases)])) ?></span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= e(I18n::get('th_lease', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_user', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_node_tier', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_project', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_time_window', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_port', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_rate_refund', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_status', $currentLang)) ?></th>
                    <th><?= e(I18n::get('th_admin_action', $currentLang)) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLeases as $rl): ?>
                    <?php
                    $lid = (int) $rl['lease_id'];
                    $lStatus = (string) $rl['lease_status'];
                    $forceConfirmMsg = I18n::format('admin_force_confirm', $currentLang, [$lid]);
                    ?>
                    <tr>
                        <td class="mono">#<?= $lid ?></td>
                        <td class="mono">
                            <strong><?= e($rl['student_code']) ?></strong>
                            <span class="role-tag"><?= e(I18n::translateRole((string) $rl['user_role'], $currentLang)) ?></span><br>
                            <span style="font-size:0.74rem;color:var(--fg-muted);"><?= e($rl['full_name']) ?></span>
                        </td>
                        <td class="mono">
                            <strong><?= e($rl['hostname']) ?></strong><br>
                            <span style="font-size:0.74rem;color:var(--fg-muted);"><?= e(I18n::translateTierName('', (string) $rl['tier_name'], $currentLang)) ?></span>
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
                                <span style="color:var(--status-online-fg);"><?= e(I18n::get('admin_refund_prefix', $currentLang)) ?> <?= number_format((float) $rl['refunded_credits'], 2) ?> CR</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= strtolower($lStatus) ?>"><span class="status-dot"></span><?= e(I18n::translateStatus($lStatus, $currentLang)) ?></span>
                        </td>
                        <td>
                            <?php if (in_array($lStatus, ['ACTIVE', 'SCHEDULED'], true)): ?>
                                <form method="POST" action="/lease/terminate" onsubmit="return confirm(<?= e(json_encode($forceConfirmMsg)) ?>);">
                                    <input type="hidden" name="lease_id" value="<?= $lid ?>">
                                    <input type="hidden" name="return_to" value="/admin">
                                    <button type="submit" class="btn btn-danger btn-sm"><?= e(I18n::get('admin_force_terminate', $currentLang)) ?></button>
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
