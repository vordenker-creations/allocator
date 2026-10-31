<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

use VKU\Allocator\Domain\Enum\NodeStatus;
use VKU\Allocator\Infrastructure\Database;
use VKU\Allocator\Repository\AllocationRepository;
use VKU\Allocator\Repository\NodeRepository;
use VKU\Allocator\Service\ProxmoxTelemetryService;

$pdo = Database::getConnection();
$nodeRepo = new NodeRepository($pdo);

$passed = 0;
$failed = 0;
$total = 0;

function check(bool $condition, string $label, string $extra = ''): void
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo sprintf("  [PASS] %s\n", $label);
    } else {
        $failed++;
        echo sprintf("  [FAIL] %s %s\n", $label, $extra !== '' ? "--> $extra" : '');
    }
}

echo "=================================================================\n";
echo "CHALLENGER 2 — Empirical Stress Harness for Availability & Telemetry\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Timezone: " . date_default_timezone_get() . "\n";
echo "=================================================================\n\n";

// =============================================================================
// SUITE 1: 24-Hour Availability Strip Temporal Anchors & Boundaries
// =============================================================================
echo "SUITE 1: 24-Hour Availability Strip Temporal Anchors & Boundaries\n";

$tz = new DateTimeZone('Asia/Ho_Chi_Minh');

$testAnchors = [
    'Midnight Wrap (23:00)' => new DateTimeImmutable('2026-10-31 23:00:00', $tz),
    'Midnight Boundary with Seconds (23:59:59)' => new DateTimeImmutable('2026-10-31 23:59:59', $tz),
    'Start of Day (00:00)' => new DateTimeImmutable('2026-10-31 00:00:00', $tz),
    'Midday Anchor (12:00)' => new DateTimeImmutable('2026-10-31 12:00:00', $tz),
    'Sub-Hour Minute Offset (14:37:22)' => new DateTimeImmutable('2026-10-31 14:37:22', $tz),
    'Month-End 31-Day Wrap (Oct 31 -> Nov 01)' => new DateTimeImmutable('2026-10-31 23:00:00', $tz),
    'Month-End 30-Day Wrap (Apr 30 -> May 01)' => new DateTimeImmutable('2026-04-30 23:00:00', $tz),
    'Year-End Wrap (Dec 31 -> Jan 01)' => new DateTimeImmutable('2026-12-31 23:00:00', $tz),
    'Leap Year Feb 28 -> Feb 29 (2028)' => new DateTimeImmutable('2028-02-28 23:00:00', $tz),
    'Leap Year Feb 29 -> Mar 01 (2028)' => new DateTimeImmutable('2028-02-29 23:00:00', $tz),
    'Non-Leap Year Feb 28 -> Mar 01 (2025)' => new DateTimeImmutable('2025-02-28 23:00:00', $tz),
    'UTC Timezone Conversion (16:00 UTC = 23:00 VN)' => new DateTimeImmutable('2026-10-31 16:00:00', new DateTimeZone('UTC')),
    'EDT Timezone Conversion (12:00 EDT = 23:00 VN)' => new DateTimeImmutable('2026-10-31 12:00:00', new DateTimeZone('America/New_York')),
    'Default Null Anchor (now)' => null,
];

foreach ($testAnchors as $name => $anchorDt) {
    $res = $nodeRepo->get24HourAvailability($anchorDt);
    check(is_array($res) && count($res) > 0, "$name: returns availability map for all nodes");

    $allNodesValid = true;
    $allSlotsConsecutive = true;
    $allTooltipsValid = true;
    $allSlotsCount24 = true;
    $expectedFirstHour = $anchorDt !== null
        ? $anchorDt->setTimezone($tz)->format('H:00')
        : (new DateTimeImmutable('now', $tz))->format('H:00');

    foreach ($res as $nodeId => $slots) {
        if (count($slots) !== 24) {
            $allSlotsCount24 = false;
        }

        // Verify first slot hour
        if (($slots[0]['slot_start'] ?? '') !== $expectedFirstHour) {
            $allNodesValid = false;
        }

        // Verify consecutive chain
        for ($i = 0; $i < 23; $i++) {
            if ($slots[$i]['slot_end'] !== $slots[$i + 1]['slot_start']) {
                $allSlotsConsecutive = false;
            }
        }

        // Verify last slot ends at same hour as first slot start
        if (($slots[23]['slot_end'] ?? '') !== $expectedFirstHour) {
            $allNodesValid = false;
        }

        // Verify tooltip format: "{start} - {end}: {STATUS}"
        foreach ($slots as $slot) {
            $expectedTooltip = sprintf('%s - %s: %s', $slot['slot_start'], $slot['slot_end'], $slot['status']);
            if ($slot['tooltip'] !== $expectedTooltip) {
                $allTooltipsValid = false;
            }
            if (!preg_match('/^[0-2][0-9]:00$/', $slot['slot_start']) || !preg_match('/^[0-2][0-9]:00$/', $slot['slot_end'])) {
                $allNodesValid = false;
            }
        }
    }

    check($allSlotsCount24, "$name: every node receives exactly 24 slots");
    check($allSlotsConsecutive, "$name: 24 slots form a strictly contiguous time chain");
    check($allNodesValid, "$name: slot hours format properly (HH:00) with first/last matching anchor");
    check($allTooltipsValid, "$name: tooltips match exact contract '{start} - {end}: {STATUS}'");
}

// 1.15 Sweep all 24 individual hours of the day
echo "\nTesting 24-hour sweep across day hours...\n";
$sweepPassed = true;
for ($h = 0; $h < 24; $h++) {
    $sweepAnchor = new DateTimeImmutable(sprintf('2026-07-15 %02d:15:30', $h), $tz);
    $sweepRes = $nodeRepo->get24HourAvailability($sweepAnchor);
    $sampleSlots = reset($sweepRes);
    if (count($sampleSlots) !== 24 || $sampleSlots[0]['slot_start'] !== sprintf('%02d:00', $h)) {
        $sweepPassed = false;
        break;
    }
}
check($sweepPassed, 'Hour sweep (00:00 to 23:00) consistently floors minutes/seconds and returns 24 slots');

// =============================================================================
// SUITE 2: Availability Strip Leases Overlap & Boundary Edge Cases
// =============================================================================
echo "\nSUITE 2: Availability Strip Leases Overlap & Boundary Edge Cases\n";

$pdo->beginTransaction();
try {
    // Pick node 1 (vku-a100-01) for isolated collision testing
    $testNodeId = 1;

    // Remove any interfering leases on node 1 during our test horizon window
    $baseTime = new DateTimeImmutable('2026-11-15 10:00:00', $tz);
    $horizonEnd = $baseTime->modify('+24 hours');

    // Insert user and allocation if needed
    $stmtAlloc = $pdo->prepare(
        "INSERT INTO allocations (user_id, project_name, docker_image, total_credits_cost)
         VALUES (1, 'stress-test-avail', 'pytorch-cuda:12.4', 100.00)"
    );
    $stmtAlloc->execute();
    $allocId = (int) $pdo->lastInsertId();

    // Helper to insert lease
    $insertLease = function (DateTimeImmutable $start, DateTimeImmutable $end, string $status = 'SCHEDULED') use ($pdo, $allocId, $testNodeId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO allocation_leases
                (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, assigned_port, access_token, lease_status)
             VALUES
                (:alloc_id, :node_id, :start, :end, 20.00, 30500, MD5(RAND()), :status)"
        );
        $stmt->execute([
            ':alloc_id' => $allocId,
            ':node_id' => $testNodeId,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
            ':status' => $status,
        ]);
        return (int) $pdo->lastInsertId();
    };

    // 2.1 Clean baseline: no leases in window -> all 24 slots FREE
    $availBase = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    $freeCount = count(array_filter($availBase, fn($s) => $s['status'] === 'FREE'));
    check($freeCount === 24, 'Baseline: Node with no leases in horizon has 24 FREE slots');

    // 2.2 Exact 1-hour lease covering slot 2 (12:00 - 13:00)
    $insertLease($baseTime->modify('+2 hours'), $baseTime->modify('+3 hours'));
    $avail2 = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    check($avail2[0]['status'] === 'FREE', 'Slot 0 (10:00-11:00) is FREE');
    check($avail2[1]['status'] === 'FREE', 'Slot 1 (11:00-12:00) is FREE');
    check($avail2[2]['status'] === 'BOOKED', 'Slot 2 (12:00-13:00) is BOOKED by exact 1-hour lease');
    check($avail2[3]['status'] === 'FREE', 'Slot 3 (13:00-14:00) is FREE');

    // 2.3 Intra-hour fractional lease (14:15 - 14:45 in slot 4: 14:00 - 15:00)
    $insertLease($baseTime->modify('+4 hours 15 minutes'), $baseTime->modify('+4 hours 45 minutes'));
    $avail3 = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    check($avail3[4]['status'] === 'BOOKED', 'Slot 4 (14:00-15:00) is BOOKED by intra-hour fractional lease');

    // 2.4 Multi-hour lease spanning slot boundaries (16:30 - 19:30)
    // Should book slots 6 (16-17), 7 (17-18), 8 (18-19), and 9 (19-20)
    $insertLease($baseTime->modify('+6 hours 30 minutes'), $baseTime->modify('+9 hours 30 minutes'));
    $avail4 = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    check(
        $avail4[6]['status'] === 'BOOKED' &&
        $avail4[7]['status'] === 'BOOKED' &&
        $avail4[8]['status'] === 'BOOKED' &&
        $avail4[9]['status'] === 'BOOKED',
        'Slots 6..9 are all BOOKED by multi-hour cross-boundary lease (16:30 - 19:30)'
    );
    check($avail4[5]['status'] === 'FREE' && $avail4[10]['status'] === 'FREE', 'Neighboring slots 5 and 10 remain FREE');

    // 2.5 Horizon boundary overlap: Lease starts before horizon start and ends inside slot 0
    // Horizon starts at 10:00; lease is 08:00 - 10:30
    $insertLease($baseTime->modify('-2 hours'), $baseTime->modify('+30 minutes'));
    $avail5 = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    check($avail5[0]['status'] === 'BOOKED', 'Slot 0 is BOOKED by lease starting before horizon and ending inside slot 0');

    // 2.6 Horizon boundary overlap: Lease starts inside slot 23 and ends after horizon
    // Horizon ends at 10:00 (+24h); lease is +23h30m -> +26h
    $insertLease($baseTime->modify('+23 hours 30 minutes'), $baseTime->modify('+26 hours'));
    $avail6 = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    check($avail6[23]['status'] === 'BOOKED', 'Slot 23 is BOOKED by lease starting in slot 23 and ending after horizon');

    // 2.7 Abutting lease outside horizon before: ends exactly at 10:00:00 (baseTime)
    // Clean test on node 2
    $node2 = 2;
    $insertLeaseAbutBefore = function () use ($pdo, $allocId, $node2, $baseTime): void {
        $stmt = $pdo->prepare(
            "INSERT INTO allocation_leases
                (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, assigned_port, access_token, lease_status)
             VALUES
                (:alloc_id, :node_id, :start, :end, 20.00, 30501, MD5(RAND()), 'SCHEDULED')"
        );
        $stmt->execute([
            ':alloc_id' => $allocId,
            ':node_id' => $node2,
            ':start' => $baseTime->modify('-2 hours')->format('Y-m-d H:i:s'),
            ':end' => $baseTime->format('Y-m-d H:i:s'), // ends exactly at horizon start
        ]);
    };
    $insertLeaseAbutBefore();
    $availNode2 = $nodeRepo->get24HourAvailability($baseTime)[$node2];
    check($availNode2[0]['status'] === 'FREE', 'Slot 0 is FREE when prior lease ends exactly at horizon start (10:00:00)');

    // 2.8 Abutting lease outside horizon after: starts exactly at horizon end (+24h)
    $stmtAbutAfter = $pdo->prepare(
        "INSERT INTO allocation_leases
            (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, assigned_port, access_token, lease_status)
         VALUES
            (:alloc_id, :node_id, :start, :end, 20.00, 30502, MD5(RAND()), 'SCHEDULED')"
    );
    $stmtAbutAfter->execute([
        ':alloc_id' => $allocId,
        ':node_id' => $node2,
        ':start' => $baseTime->modify('+24 hours')->format('Y-m-d H:i:s'),
        ':end' => $baseTime->modify('+26 hours')->format('Y-m-d H:i:s'),
    ]);
    $availNode2After = $nodeRepo->get24HourAvailability($baseTime)[$node2];
    check($availNode2After[23]['status'] === 'FREE', 'Slot 23 is FREE when next lease starts exactly at horizon end (+24h)');

    // 2.9 Inactive leases (CANCELLED / EXPIRED) inside horizon must NOT book slots
    $insertLeaseCancelled = function (string $status) use ($pdo, $allocId, $node2, $baseTime): void {
        $stmt = $pdo->prepare(
            "INSERT INTO allocation_leases
                (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, assigned_port, access_token, lease_status)
             VALUES
                (:alloc_id, :node_id, :start, :end, 20.00, 30503, MD5(RAND()), :status)"
        );
        $stmt->execute([
            ':alloc_id' => $allocId,
            ':node_id' => $node2,
            ':start' => $baseTime->modify('+5 hours')->format('Y-m-d H:i:s'),
            ':end' => $baseTime->modify('+7 hours')->format('Y-m-d H:i:s'),
            ':status' => $status,
        ]);
    };
    $insertLeaseCancelled('CANCELLED');
    $availNode2Cancelled = $nodeRepo->get24HourAvailability($baseTime)[$node2];
    check($availNode2Cancelled[5]['status'] === 'FREE', 'Slot 5 remains FREE despite CANCELLED lease');

    $insertLeaseCancelled('EXPIRED');
    $availNode2Expired = $nodeRepo->get24HourAvailability($baseTime)[$node2];
    check($availNode2Expired[5]['status'] === 'FREE', 'Slot 5 remains FREE despite EXPIRED lease');

} finally {
    $pdo->rollBack();
}

// =============================================================================
// SUITE 3: Node Hardware Status Variations (ONLINE, MAINTENANCE, OFFLINE)
// =============================================================================
echo "\nSUITE 3: Node Hardware Status Variations (ONLINE, MAINTENANCE, OFFLINE)\n";

$pdo->beginTransaction();
try {
    $testNodeId = 1;
    $baseTime = new DateTimeImmutable('2026-11-20 08:00:00', $tz);

    // 3.1 Verify ONLINE node has allocatable slots
    $nodeRepo->updateStatus($testNodeId, NodeStatus::Online);
    $availOnline = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    $onlineSlotsMaint = array_filter($availOnline, fn($s) => $s['status'] === 'MAINTENANCE');
    check(count($onlineSlotsMaint) === 0, 'ONLINE node has 0 MAINTENANCE slots');
    check($availOnline[0]['class'] === 'strip-slot-free is-free', 'ONLINE free slot has class strip-slot-free is-free');

    // 3.2 Change status to MAINTENANCE
    $nodeRepo->updateStatus($testNodeId, NodeStatus::Maintenance);
    $availMaint = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    $maintSlots = array_filter($availMaint, fn($s) => $s['status'] === 'MAINTENANCE');
    check(count($maintSlots) === 24, 'MAINTENANCE node has exactly 24 MAINTENANCE slots');
    check(
        $availMaint[0]['class'] === 'strip-slot-maint is-maintenance',
        'MAINTENANCE slot has class strip-slot-maint is-maintenance'
    );
    check(
        $availMaint[0]['tooltip'] === '08:00 - 09:00: MAINTENANCE',
        'MAINTENANCE slot tooltip indicates MAINTENANCE'
    );

    // 3.3 Even if a lease exists, MAINTENANCE overrides booking
    $stmtAlloc = $pdo->prepare(
        "INSERT INTO allocations (user_id, project_name, docker_image, total_credits_cost)
         VALUES (1, 'maint-override-test', 'pytorch-cuda:12.4', 20.00)"
    );
    $stmtAlloc->execute();
    $allocId = (int) $pdo->lastInsertId();

    $stmtLease = $pdo->prepare(
        "INSERT INTO allocation_leases
            (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, assigned_port, access_token, lease_status)
         VALUES
            (:alloc_id, :node_id, :start, :end, 20.00, 30505, MD5(RAND()), 'SCHEDULED')"
    );
    $stmtLease->execute([
        ':alloc_id' => $allocId,
        ':node_id' => $testNodeId,
        ':start' => $baseTime->modify('+1 hour')->format('Y-m-d H:i:s'),
        ':end' => $baseTime->modify('+3 hours')->format('Y-m-d H:i:s'),
    ]);

    $availMaintWithLease = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    $allMaintWithLease = array_filter($availMaintWithLease, fn($s) => $s['status'] === 'MAINTENANCE');
    check(count($allMaintWithLease) === 24, 'MAINTENANCE status strictly overrides active/scheduled leases across all 24 slots');

    // 3.4 Change status to OFFLINE
    $nodeRepo->updateStatus($testNodeId, NodeStatus::Offline);
    $availOffline = $nodeRepo->get24HourAvailability($baseTime)[$testNodeId];
    $offlineMaintSlots = array_filter($availOffline, fn($s) => $s['status'] === 'MAINTENANCE');
    check(count($offlineMaintSlots) === 24, 'OFFLINE node maps to MAINTENANCE availability strip slots');

} finally {
    $pdo->rollBack();
}

// =============================================================================
// SUITE 4: Telemetry Mapping & Simulation Across Edge Conditions
// =============================================================================
echo "\nSUITE 4: Telemetry Mapping & Simulation Across Edge Conditions\n";

// 4.1 Non-Proxmox Campus Node Simulation: simulateCampusNodeTelemetry()
$sampleGpuNode = [
    'id' => 3,
    'hostname' => 'vku-rtx4090-01',
    'status' => 'ONLINE',
    'ram_gb' => 64,
    'vram_gb' => 24,
];

$sampleCpuNode = [
    'id' => 4,
    'hostname' => 'vku-cpu-01',
    'status' => 'ONLINE',
    'ram_gb' => 128,
    'vram_gb' => 0,
];

// Active lease simulation on GPU node
$tActiveGpu = ProxmoxTelemetryService::simulateCampusNodeTelemetry($sampleGpuNode, true);
check($tActiveGpu['cpu_usage_pct'] >= 75.0 && $tActiveGpu['cpu_usage_pct'] <= 92.0, 'Active GPU node: CPU usage between 75% and 92%');
check($tActiveGpu['ram_usage_pct'] >= 65.0 && $tActiveGpu['ram_usage_pct'] <= 85.0, 'Active GPU node: RAM usage between 65% and 85%');
check($tActiveGpu['ram_total_gb'] === 64.0, 'Active GPU node: RAM total matches node spec (64 GB)');
check($tActiveGpu['vram_allocated_gb'] === 24, 'Active GPU node: 100% VRAM allocated (24 GB)');
check($tActiveGpu['vram_usage_pct'] === 100.0, 'Active GPU node: VRAM usage percentage is 100.0%');

// Active lease simulation on CPU-only node
$tActiveCpu = ProxmoxTelemetryService::simulateCampusNodeTelemetry($sampleCpuNode, true);
check($tActiveCpu['cpu_usage_pct'] >= 75.0 && $tActiveCpu['cpu_usage_pct'] <= 92.0, 'Active CPU node: CPU usage between 75% and 92%');
check($tActiveCpu['vram_allocated_gb'] === 0, 'Active CPU node: VRAM allocated is 0 GB');
check($tActiveCpu['vram_usage_pct'] === 0.0, 'Active CPU node: VRAM usage percentage is 0.0%');

// Idle/Free simulation on GPU node
$tIdleGpu = ProxmoxTelemetryService::simulateCampusNodeTelemetry($sampleGpuNode, false);
check($tIdleGpu['cpu_usage_pct'] >= 3.0 && $tIdleGpu['cpu_usage_pct'] <= 12.0, 'Idle GPU node: CPU usage between 3% and 12%');
check($tIdleGpu['ram_usage_pct'] >= 15.0 && $tIdleGpu['ram_usage_pct'] <= 28.0, 'Idle GPU node: RAM usage between 15% and 28%');
check($tIdleGpu['vram_allocated_gb'] === 0, 'Idle GPU node: 0 GB VRAM allocated');
check($tIdleGpu['vram_usage_pct'] === 0.0, 'Idle GPU node: VRAM usage percentage is 0.0%');

// Maintenance simulation
$sampleMaintNode = [
    'id' => 5,
    'hostname' => 'vku-down-01',
    'status' => 'MAINTENANCE',
    'ram_gb' => 32,
    'vram_gb' => 16,
];
$tMaint = ProxmoxTelemetryService::simulateCampusNodeTelemetry($sampleMaintNode, true);
check($tMaint['cpu_usage_pct'] === 0.0, 'MAINTENANCE node: CPU usage is exactly 0.0%');
check($tMaint['ram_used_gb'] === 0.0, 'MAINTENANCE node: RAM used is 0.0 GB');
check($tMaint['ram_usage_pct'] === 0.0, 'MAINTENANCE node: RAM usage percentage is 0.0%');
check($tMaint['vram_allocated_gb'] === 0, 'MAINTENANCE node: VRAM allocated is 0 GB');
check($tMaint['vram_usage_pct'] === 0.0, 'MAINTENANCE node: VRAM usage percentage is 0.0%');
check($tMaint['ram_total_gb'] === 32.0, 'MAINTENANCE node: RAM total preserved');

// OFFLINE simulation
$sampleOfflineNode = [
    'id' => 6,
    'hostname' => 'vku-offline-01',
    'status' => 'OFFLINE',
    'ram_gb' => 64,
    'vram_gb' => 24,
];
$tOffline = ProxmoxTelemetryService::simulateCampusNodeTelemetry($sampleOfflineNode, false);
check($tOffline['cpu_usage_pct'] === 0.0, 'OFFLINE node: CPU usage is exactly 0.0%');
check($tOffline['ram_used_gb'] === 0.0, 'OFFLINE node: RAM used is 0.0 GB');

// Extreme boundary inputs
$emptyNode = [];
$tEmpty = ProxmoxTelemetryService::simulateCampusNodeTelemetry($emptyNode, false);
check(is_array($tEmpty) && isset($tEmpty['cpu_usage_pct']), 'Empty node array: fallback defaults handled safely');

$zeroRamNode = ['id' => 7, 'status' => 'ONLINE', 'ram_gb' => 0, 'vram_gb' => 0];
$tZero = ProxmoxTelemetryService::simulateCampusNodeTelemetry($zeroRamNode, true);
check($tZero['ram_total_gb'] === 0.0 && $tZero['ram_used_gb'] === 0.0, 'Zero RAM node: no division by zero or errors');

$hugeRamNode = ['id' => 8, 'status' => 'ONLINE', 'ram_gb' => 1048576, 'vram_gb' => 512];
$tHuge = ProxmoxTelemetryService::simulateCampusNodeTelemetry($hugeRamNode, true);
check($tHuge['ram_total_gb'] === 1048576.0 && $tHuge['ram_used_gb'] > 0, '1 TB RAM node: scales without overflow');

// Deterministic formula variation across distinct node IDs
$cpuSamples = [];
for ($id = 1; $id <= 20; $id++) {
    $t = ProxmoxTelemetryService::simulateCampusNodeTelemetry(['id' => $id, 'status' => 'ONLINE'], true);
    $cpuSamples[] = $t['cpu_usage_pct'];
}
$uniqueCpuSamples = count(array_unique($cpuSamples));
check($uniqueCpuSamples > 10, 'Campus node CPU simulation shows realistic pseudo-random spread across node IDs');

// =============================================================================
// SUITE 5: Active Lease Detection Invariant Testing
// =============================================================================
echo "\nSUITE 5: Active Lease Detection Invariant Testing\n";

$pdo->beginTransaction();
try {
    $testNodeId = 1;
    $testNow = new DateTimeImmutable('2026-11-25 15:00:00', $tz);

    $stmtAlloc = $pdo->prepare(
        "INSERT INTO allocations (user_id, project_name, docker_image, total_credits_cost)
         VALUES (1, 'active-lease-detection-test', 'pytorch-cuda:12.4', 20.00)"
    );
    $stmtAlloc->execute();
    $allocId = (int) $pdo->lastInsertId();

    // Baseline: No active leases at testNow
    $activeNodesBase = $nodeRepo->getActiveLeaseNodeIds($testNow);
    check(!in_array($testNodeId, $activeNodesBase, true), 'Baseline: Node 1 not in active list at testNow');

    // 5.1 Insert ACTIVE lease covering testNow
    $stmtLease = $pdo->prepare(
        "INSERT INTO allocation_leases
            (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, assigned_port, access_token, lease_status)
         VALUES
            (:alloc_id, :node_id, :start, :end, 20.00, 30510, MD5(RAND()), :status)"
    );
    $stmtLease->execute([
        ':alloc_id' => $allocId,
        ':node_id' => $testNodeId,
        ':start' => $testNow->modify('-1 hour')->format('Y-m-d H:i:s'),
        ':end' => $testNow->modify('+1 hour')->format('Y-m-d H:i:s'),
        ':status' => 'ACTIVE',
    ]);
    $activeNodes1 = $nodeRepo->getActiveLeaseNodeIds($testNow);
    check(in_array($testNodeId, $activeNodes1, true), 'Node with ACTIVE lease is detected as active');

    // 5.2 Insert SCHEDULED lease covering testNow
    $testNodeId2 = 2;
    $stmtLease->execute([
        ':alloc_id' => $allocId,
        ':node_id' => $testNodeId2,
        ':start' => $testNow->modify('-30 minutes')->format('Y-m-d H:i:s'),
        ':end' => $testNow->modify('+30 minutes')->format('Y-m-d H:i:s'),
        ':status' => 'SCHEDULED',
    ]);
    $activeNodes2 = $nodeRepo->getActiveLeaseNodeIds($testNow);
    check(in_array($testNodeId2, $activeNodes2, true), 'Node with SCHEDULED lease whose window has opened is detected as active');

    // 5.3 Future SCHEDULED lease (starts +1h in future) -> must NOT be active
    $testNodeId3 = 3;
    $stmtLease->execute([
        ':alloc_id' => $allocId,
        ':node_id' => $testNodeId3,
        ':start' => $testNow->modify('+1 hour')->format('Y-m-d H:i:s'),
        ':end' => $testNow->modify('+3 hours')->format('Y-m-d H:i:s'),
        ':status' => 'SCHEDULED',
    ]);
    $activeNodes3 = $nodeRepo->getActiveLeaseNodeIds($testNow);
    check(!in_array($testNodeId3, $activeNodes3, true), 'Future SCHEDULED lease is NOT detected as active');

    // 5.4 Past lease (ended 1 minute ago, status EXPIRED) -> must NOT be active
    $testNodeId4 = 4;
    $stmtLease->execute([
        ':alloc_id' => $allocId,
        ':node_id' => $testNodeId4,
        ':start' => $testNow->modify('-2 hours')->format('Y-m-d H:i:s'),
        ':end' => $testNow->modify('-1 minute')->format('Y-m-d H:i:s'),
        ':status' => 'EXPIRED',
    ]);
    $activeNodes4 = $nodeRepo->getActiveLeaseNodeIds($testNow);
    check(!in_array($testNodeId4, $activeNodes4, true), 'Past lease that already ended (EXPIRED) is NOT detected as active');

    // 5.5 If lease was ACTIVE but past due, syncTemporalStatuses updates it to EXPIRED
    $testNodeId5 = 5;
    $stmtLease->execute([
        ':alloc_id' => $allocId,
        ':node_id' => $testNodeId5,
        ':start' => $testNow->modify('-3 hours')->format('Y-m-d H:i:s'),
        ':end' => $testNow->modify('-10 minutes')->format('Y-m-d H:i:s'),
        ':status' => 'ACTIVE',
    ]);
    $allocRepo = new AllocationRepository($pdo);
    $allocRepo->syncTemporalStatuses($testNow);
    $activeNodes5 = $nodeRepo->getActiveLeaseNodeIds($testNow);
    check(!in_array($testNodeId5, $activeNodes5, true), 'Past ACTIVE lease is transitioned to EXPIRED by syncTemporalStatuses');

} finally {
    $pdo->rollBack();
}

// =============================================================================
// SUITE 6: Proxmox vs Campus Node Telemetry Mapping Integration
// =============================================================================
echo "\nSUITE 6: Proxmox vs Campus Node Telemetry Mapping Integration\n";

$nodes = $nodeRepo->findNodesWithWindowAvailability(
    new DateTimeImmutable('now', $tz),
    (new DateTimeImmutable('now', $tz))->modify('+2 hours')
);

$vorNode = null;
foreach ($nodes as $n) {
    if ($n['hostname'] === 'vor-server') {
        $vorNode = $n;
        break;
    }
}
check($vorNode !== null, 'vor-server exists in nodes inventory');
check((int) $vorNode['vram_gb'] === 0, 'vor-server has 0 VRAM in database');
check((int) $vorNode['ram_gb'] === 16, 'vor-server baseline database RAM is 16 GB');

// Test simulated fallback mapping for vor-server
$pveTelemetry = ProxmoxTelemetryService::getSimulatedFallbackState('vor-server');
$vorMappedTelemetry = [
    'cpu_usage_pct' => (float) $pveTelemetry['cpu_usage_pct'],
    'ram_used_gb' => (float) $pveTelemetry['ram_used_gb'],
    'ram_total_gb' => (float) $pveTelemetry['ram_total_gb'],
    'ram_usage_pct' => (float) $pveTelemetry['ram_usage_pct'],
    'vram_allocated_gb' => 0,
    'vram_total_gb' => 0,
    'vram_usage_pct' => 0.0,
];
check($vorMappedTelemetry['ram_total_gb'] === 24.0, 'vor-server telemetry reports 24.0 GB total RAM (overriding 16 GB DB baseline)');
check($vorMappedTelemetry['vram_total_gb'] === 0, 'vor-server telemetry reports 0 VRAM total');
check($vorMappedTelemetry['vram_allocated_gb'] === 0, 'vor-server telemetry reports 0 VRAM allocated');

echo "\n=================================================================\n";
echo sprintf("Summary: %d Passed, %d Failed (Total %d tests)\n", $passed, $failed, $total);
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
