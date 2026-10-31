<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

use VKU\Allocator\Domain\Enum\RuntimePreset;
use VKU\Allocator\Infrastructure\Database;
use VKU\Allocator\Repository\AllocationRepository;
use VKU\Allocator\Repository\NodeRepository;
use VKU\Allocator\Repository\UserRepository;
use VKU\Allocator\Service\ProvisioningService;
use VKU\Allocator\Service\ProxmoxTelemetryService;
use VKU\Allocator\Service\SshKeyValidator;
use VKU\Allocator\Service\StagingTrayService;

$pdo = Database::getConnection();
$nodeRepo = new NodeRepository($pdo);
$userRepo = new UserRepository($pdo);
$allocationRepo = new AllocationRepository($pdo);
$provisioningService = new ProvisioningService($pdo, $nodeRepo, $userRepo, $allocationRepo);

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo sprintf("  [PASS] %s\n", $label);
    } else {
        $failed++;
        echo sprintf("  [FAIL] %s %s\n", $label, $detail !== '' ? "($detail)" : '');
    }
}

echo "=================================================================\n";
echo "VKU's Allocator — Automated Integration & Invariant Test Suite\n";
echo "PHP Runtime: " . PHP_VERSION . "\n";
echo "=================================================================\n\n";

$now = new DateTimeImmutable();

// 1. SSH Public Key Validator Tests
echo "1. Testing SSH Public Key Validator...\n";
assertCheck(
    SshKeyValidator::isValid('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl student@vku.udn.vn'),
    'Accepts valid ssh-ed25519 public key'
);
assertCheck(
    !SshKeyValidator::isValid('invalid-ssh-key-content-12345'),
    'Rejects malformed SSH key string'
);

// 2. 24-Hour Booking Horizon Tests
echo "\n2. Testing 24-Hour Booking Horizon Enforcement...\n";
$withinHorizon = $now->modify('+4 hours')->format('Y-m-d\TH:i');
$beyondHorizon = $now->modify('+26 hours')->format('Y-m-d\TH:i');
$inPast = $now->modify('-2 hours')->format('Y-m-d\TH:i');

assertCheck(
    StagingTrayService::validateStartTimeHorizon($withinHorizon, $now)['ok'] === true,
    'Allows start_time within 24h horizon (+4h)'
);
assertCheck(
    StagingTrayService::validateStartTimeHorizon($beyondHorizon, $now)['ok'] === false,
    'Rejects start_time beyond 24h horizon (+26h)'
);
assertCheck(
    StagingTrayService::validateStartTimeHorizon($inPast, $now)['ok'] === false,
    'Rejects start_time in the past (-2h)'
);

// 3. Tier Max Allocation Hours Validation
echo "\n3. Testing Resource Tier Max Allocation Hours Cap...\n";
// Node #3 (vku-rtx4090-01) is in tier CUDA_COMPUTE (max_allocation_hours = 6)
$overTierCapResult = $provisioningService->provisionJob(
    userId: 1, // Student 22IT001
    projectName: 'test-over-tier-cap',
    dockerImage: RuntimePreset::PyTorchCuda->value,
    rawStartTime: $now->format('Y-m-d\TH:i'),
    startupScript: null,
    stagedNodeHours: [3 => 8], // 8h > 6h max
    submittedSshKey: null,
    nowOverride: $now,
);
assertCheck(
    $overTierCapResult['ok'] === false && str_contains((string) ($overTierCapResult['error'] ?? ''), 'between 1 and 6 hours'),
    'Rejects 8-hour lease on CUDA_COMPUTE node (max 6h)',
    $overTierCapResult['error'] ?? ''
);

// 4. Role-Based Anti-Hoarding Policy (STUDENT vs RESEARCHER)
echo "\n4. Testing Role-Based Anti-Hoarding Quotas...\n";
$studentMultiNodeResult = $provisioningService->provisionJob(
    userId: 1, // Student 22IT001
    projectName: 'student-multi-node-attempt',
    dockerImage: RuntimePreset::PyTorchCuda->value,
    rawStartTime: $now->format('Y-m-d\TH:i'),
    startupScript: null,
    stagedNodeHours: [3 => 2, 5 => 2], // 2 nodes > 1 allowed for STUDENT
    submittedSshKey: null,
    nowOverride: $now,
);
assertCheck(
    $studentMultiNodeResult['ok'] === false && str_contains((string) ($studentMultiNodeResult['error'] ?? ''), 'at most 1 node(s) per job'),
    'Blocks STUDENT from leasing 2 nodes in a single job',
    $studentMultiNodeResult['error'] ?? ''
);

// 5. Valid Atomic Provisioning by STUDENT (1 Node, 3 Hours on vku-rtx4090-01 @ 20 CR/h = 60 CR)
echo "\n5. Testing Atomic Provisioning & Credit Deduction (Loop C)...\n";
$studentBefore = $userRepo->findById(1);
$balanceBefore = (float) $studentBefore['credit_balance'];

$validStudentJob = $provisioningService->provisionJob(
    userId: 1,
    projectName: 'student-cv-training',
    dockerImage: RuntimePreset::PyTorchCuda->value,
    rawStartTime: $now->format('Y-m-d\TH:i'),
    startupScript: "python3 train.py --batch-size 32",
    stagedNodeHours: [3 => 3], // Node #3 for 3 hours = 60.00 CR
    submittedSshKey: null,
    nowOverride: $now,
);

assertCheck($validStudentJob['ok'] === true, 'Provisions valid 1-node STUDENT job atomically', $validStudentJob['error'] ?? '');

$studentAfter = $userRepo->findById(1);
$balanceAfter = (float) $studentAfter['credit_balance'];
assertCheck(
    abs(($balanceBefore - $balanceAfter) - 60.00) < 0.001,
    sprintf('Deducted exact 60.00 CR from student balance (%.2f -> %.2f)', $balanceBefore, $balanceAfter)
);

$createdLease = $validStudentJob['leases'][0] ?? [];
$leaseId = (int) ($createdLease['lease_id'] ?? 0);
$port = (int) ($createdLease['assigned_port'] ?? 0);
$token = (string) ($createdLease['access_token'] ?? '');

assertCheck($port >= 20000 && $port <= 60000, "Assigned ephemeral SSH port in 20000..60000 ($port)");
assertCheck(strlen($token) === 32 && ctype_xdigit($token), "Generated 32-char hex access token ($token)");

// 6. Test STUDENT Global 1-Active-Lease Limit
echo "\n6. Testing STUDENT System-Wide Max 1 Active/Scheduled Lease Limit...\n";
$secondStudentJob = $provisioningService->provisionJob(
    userId: 1,
    projectName: 'student-second-concurrent-job',
    dockerImage: RuntimePreset::UbuntuBuild->value,
    rawStartTime: $now->format('Y-m-d\TH:i'),
    startupScript: null,
    stagedNodeHours: [6 => 2], // Try to book a free CPU node while holding lease #1
    submittedSshKey: null,
    nowOverride: $now,
);
assertCheck(
    $secondStudentJob['ok'] === false && str_contains((string) ($secondStudentJob['error'] ?? ''), 'at most 1 active/scheduled lease across the system'),
    'Blocks STUDENT from holding a second concurrent lease across the system',
    $secondStudentJob['error'] ?? ''
);

// 7. Test Overlapping Time-Window Conflict Detection (Researcher tries to book Node #3 during Student's window)
echo "\n7. Testing Time-Window Overlap Collision Rejection...\n";
$overlappingJob = $provisioningService->provisionJob(
    userId: 3, // Researcher RES104
    projectName: 'researcher-conflict-test',
    dockerImage: RuntimePreset::PyTorchCuda->value,
    rawStartTime: $now->modify('+1 hour')->format('Y-m-d\TH:i'), // Overlaps [now, now+3h]
    startupScript: null,
    stagedNodeHours: [3 => 2], // Same Node #3
    submittedSshKey: null,
    nowOverride: $now,
);
assertCheck(
    $overlappingJob['ok'] === false && str_contains((string) ($overlappingJob['error'] ?? ''), 'Schedule conflict'),
    'Rejects overlapping time-window booking on Node #3 atomically',
    $overlappingJob['error'] ?? ''
);

// 8. Test Early Termination & Pro-Rata Refund (Simulate 45 minutes elapsed on 3-hour lease -> 2 full hours unused = 40.00 CR refund)
echo "\n8. Testing Early Termination & Pro-Rata Unused Full Hour Refund (Loop D)...\n";
$fortyFiveMinsLater = $now->modify('+45 minutes');
$terminateResult = $provisioningService->terminateLeaseEarly(
    leaseId: $leaseId,
    actingUserId: 1,
    isAdmin: false,
    nowOverride: $fortyFiveMinsLater,
);

assertCheck($terminateResult['ok'] === true, 'Terminates active lease early', $terminateResult['error'] ?? '');
assertCheck(
    (int) ($terminateResult['unused_hours'] ?? -1) === 2 && abs(((float) ($terminateResult['refunded_credits'] ?? 0.0)) - 40.00) < 0.001,
    sprintf('Calculated exact 2 unused full hours and refunded 40.00 CR (got %d hrs / %.2f CR)', (int) ($terminateResult['unused_hours'] ?? 0), (float) ($terminateResult['refunded_credits'] ?? 0.0))
);

$studentFinal = $userRepo->findById(1);
$balanceFinal = (float) $studentFinal['credit_balance'];
assertCheck(
    abs(($balanceFinal - $balanceAfter) - 40.00) < 0.001,
    sprintf('Restored 40.00 CR to student balance (%.2f -> %.2f)', $balanceAfter, $balanceFinal)
);

// 9. Proxmox VE Telemetry Service Tests
echo "\n9. Testing Proxmox VE Hypervisor Telemetry Service (Path 2)...\n";

// 9.1 Unconfigured / Fallback Handling
$unconfiguredService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: '',
    tokenSecret: ''
);
$fallbackTelemetry = $unconfiguredService->getNodeTelemetry();
assertCheck(
    $fallbackTelemetry['is_live'] === false,
    'Unconfigured Proxmox service marks is_live as false'
);
assertCheck(
    $fallbackTelemetry['telemetry_source'] === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)'
    && $fallbackTelemetry['status_message'] === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)',
    'Unconfigured Proxmox service returns standard simulated notice banner'
);
assertCheck(
    $fallbackTelemetry['node_name'] === 'vor-server'
    && $fallbackTelemetry['cpu_cores'] === 4
    && $fallbackTelemetry['ram_total_gb'] == 24.0
    && $fallbackTelemetry['running_vms'] === 2,
    'Fallback telemetry provides complete hardware and VM metrics (4 cores, 24GB RAM, 2 VMs)'
);

// 9.2 Parsing Live Responses
$mockStatusPayload = [
    'data' => [
        'cpuinfo' => [
            'model' => 'Intel(R) Core(TM) i5-3470 CPU @ 3.20GHz',
            'cpus' => 4,
            'cores' => 4,
            'sockets' => 1,
        ],
        'cpu' => 0.142,
        'memory' => [
            'total' => 25769803776, // 24 GB
            'used' => 8589934592,   // 8 GB
            'free' => 17179869184,
        ],
        'uptime' => 1051200, // 12d 4h 0m
        'loadavg' => ['0.42', '0.38', '0.35'],
    ],
];
$mockQemuPayload = [
    'data' => [
        ['vmid' => 100, 'name' => 'build-vm-01', 'status' => 'running'],
        ['vmid' => 101, 'name' => 'sim-worker-01', 'status' => 'running'],
        ['vmid' => 102, 'name' => 'ci-runner', 'status' => 'stopped'],
    ],
];

$parsedTelemetry = ProxmoxTelemetryService::parseLiveResponses($mockStatusPayload, $mockQemuPayload, 'vor-server');
assertCheck(
    $parsedTelemetry['is_live'] === true && $parsedTelemetry['telemetry_source'] === 'LIVE PVE API',
    'parseLiveResponses sets is_live=true and telemetry_source="LIVE PVE API"'
);
assertCheck(
    $parsedTelemetry['cpu_model'] === 'Intel(R) Core(TM) i5-3470 CPU @ 3.20GHz'
    && $parsedTelemetry['cpu_cores'] === 4
    && $parsedTelemetry['cpu_threads'] === 4
    && abs($parsedTelemetry['cpu_usage_pct'] - 14.2) < 0.1,
    'parseLiveResponses extracts CPU model, core/thread count, and 14.2% usage'
);
assertCheck(
    abs($parsedTelemetry['ram_total_gb'] - 24.0) < 0.1
    && abs($parsedTelemetry['ram_used_gb'] - 8.0) < 0.1
    && abs($parsedTelemetry['ram_usage_pct'] - 33.3) < 0.1,
    'parseLiveResponses converts RAM bytes to GB (8.0 / 24.0 GB, 33.3%)'
);
assertCheck(
    $parsedTelemetry['uptime_formatted'] === '12d 4h 0m'
    && $parsedTelemetry['load_average'] === '0.42, 0.38, 0.35',
    'parseLiveResponses formats uptime (12d 4h 0m) and loadavg string'
);
assertCheck(
    $parsedTelemetry['running_vms'] === 2 && $parsedTelemetry['total_vms'] === 3,
    'parseLiveResponses correctly filters running QEMU VMs (2 running / 3 total)'
);

// 9.3 Node Auto-Detection via mock HTTP executor
$mockExecutor = function (string $url, array $headers) use ($mockStatusPayload, $mockQemuPayload): ?array {
    if (str_ends_with($url, '/api2/json/nodes')) {
        return [
            'data' => [
                ['node' => 'secondary-node', 'status' => 'offline'],
                ['node' => 'vor-server', 'status' => 'online'],
            ],
        ];
    }
    if (str_ends_with($url, '/api2/json/nodes/vor-server/status')) {
        return $mockStatusPayload;
    }
    if (str_ends_with($url, '/api2/json/nodes/vor-server/qemu')) {
        return $mockQemuPayload;
    }
    return null;
};

$mockService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'test@pam!token',
    tokenSecret: '12345678-1234-1234-1234-123456789abc',
    httpExecutor: $mockExecutor
);
assertCheck(
    $mockService->resolveNodeName() === 'vor-server',
    'resolveNodeName detects online node from /api2/json/nodes response'
);
$liveTelemetry = $mockService->getNodeTelemetry();
assertCheck(
    $liveTelemetry['is_live'] === true && $liveTelemetry['node_name'] === 'vor-server' && $liveTelemetry['running_vms'] === 2,
    'getNodeTelemetry executes mock workflow end-to-end and returns live telemetry'
);

// 9.4 Node Auto-Detection Fallback when node list empty or unreachable
$failingExecutor = function (string $url, array $headers): ?array {
    return null; // Simulate network connection failure or timeout
};
$fallbackNodeService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'test@pam!token',
    tokenSecret: 'secret',
    httpExecutor: $failingExecutor
);
assertCheck(
    $fallbackNodeService->resolveNodeName() === 'vor-server',
    'resolveNodeName falls back to configured node on network failure'
);
$unreachableTelemetry = $fallbackNodeService->getNodeTelemetry();
assertCheck(
    $unreachableTelemetry['is_live'] === false
    && $unreachableTelemetry['telemetry_source'] === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)',
    'getNodeTelemetry cleanly catches HTTP failure and returns simulated fallback'
);

// 9.5 Campus Node Telemetry Simulation
$gpuNode = ['id' => 3, 'hostname' => 'vku-rtx4090-01', 'status' => 'ONLINE', 'ram_gb' => 128, 'vram_gb' => 24];
$activeGpuTelemetry = ProxmoxTelemetryService::simulateCampusNodeTelemetry($gpuNode, true);
assertCheck(
    $activeGpuTelemetry['cpu_usage_pct'] >= 75.0 && $activeGpuTelemetry['cpu_usage_pct'] <= 92.0
    && $activeGpuTelemetry['vram_allocated_gb'] === 24
    && $activeGpuTelemetry['vram_usage_pct'] == 100.0,
    'simulateCampusNodeTelemetry active lease: 75-92% CPU, 100% VRAM allocation'
);

$idleGpuTelemetry = ProxmoxTelemetryService::simulateCampusNodeTelemetry($gpuNode, false);
assertCheck(
    $idleGpuTelemetry['cpu_usage_pct'] >= 3.0 && $idleGpuTelemetry['cpu_usage_pct'] <= 12.0
    && $idleGpuTelemetry['vram_allocated_gb'] === 0
    && $idleGpuTelemetry['vram_usage_pct'] == 0.0,
    'simulateCampusNodeTelemetry idle/free: 3-12% CPU, 0% VRAM allocation'
);

$maintNode = ['id' => 4, 'hostname' => 'vku-rtx4090-02', 'status' => 'MAINTENANCE', 'ram_gb' => 128, 'vram_gb' => 24];
$maintTelemetry = ProxmoxTelemetryService::simulateCampusNodeTelemetry($maintNode, false);
assertCheck(
    $maintTelemetry['cpu_usage_pct'] == 0.0
    && $maintTelemetry['ram_used_gb'] == 0.0
    && $maintTelemetry['vram_allocated_gb'] === 0,
    'simulateCampusNodeTelemetry maintenance: 0% CPU, 0 GB RAM used, 0 GB VRAM'
);

// 10. SSH Receipt Public Host Resolution
echo "\n10. Testing SSH Receipt Host Snippet Resolution...\n";
$currentHost = $_ENV['ALLOCATOR_PUBLIC_HOST'] ?? getenv('ALLOCATOR_PUBLIC_HOST') ?: 'allocator.vku.udn.vn';
assertCheck(
    $currentHost === 'allocator.vku.udn.vn' || $currentHost === 'allocator.vordenker.work',
    sprintf('Public SSH host resolves correctly (%s)', $currentHost)
);

// 11. Database Seed Verification for vor-server (Node 10)
echo "\n11. Testing vor-server Tier 2 Database Seed...\n";
$vorNode = $nodeRepo->findByIdWithTier(10);
assertCheck(
    $vorNode !== null && $vorNode['hostname'] === 'vor-server',
    'Node 10 (vor-server) exists in compute_nodes'
);
assertCheck(
    $vorNode !== null && (int) $vorNode['tier_id'] === 2 && (int) $vorNode['ram_gb'] === 16 && (int) $vorNode['cpu_cores'] === 4,
    'vor-server seeded in Tier 2 with 16 GB baseline RAM and 4 CPU cores'
);

// 12. Testing UI Quality Control & Spec Jargon Removal
echo "\n12. Testing UI Quality Control & Spec Jargon Removal...\n";
$layoutContent = (string) file_get_contents(dirname(__DIR__) . '/views/layout_top.php');
assertCheck(
    str_contains($layoutContent, 'Node Matrix')
    && str_contains($layoutContent, 'Staging Tray')
    && str_contains($layoutContent, 'Active Console')
    && str_contains($layoutContent, 'Cluster Admin'),
    'Navbar links render clean titles (Node Matrix, Staging Tray, Active Console, Cluster Admin)'
);
assertCheck(
    !preg_match('/\[[ABCD](\/[ABCD])?\]/', $layoutContent),
    'Navbar links contain no bracket spec labels like [A], [B], [C/D], [D]'
);

$viewFiles = [
    'matrix' => dirname(__DIR__) . '/views/matrix.php',
    'tray' => dirname(__DIR__) . '/views/tray.php',
    'console' => dirname(__DIR__) . '/views/console.php',
    'admin' => dirname(__DIR__) . '/views/admin.php',
];
$viewsCleanOfLoopPrefixes = true;
foreach ($viewFiles as $name => $filePath) {
    $content = (string) file_get_contents($filePath);
    if (preg_match('/Loop [ABCD]/i', $content)) {
        $viewsCleanOfLoopPrefixes = false;
        break;
    }
}
assertCheck($viewsCleanOfLoopPrefixes, 'All view templates are clean of subtitle Loop prefixes (Loop A, B, C, D)');

$matrixContent = (string) file_get_contents($viewFiles['matrix']);
$trayContent = (string) file_get_contents($viewFiles['tray']);
assertCheck(
    !str_contains($matrixContent, '&le; 24h') && !str_contains($matrixContent, '<= 24h')
    && !str_contains($trayContent, '&le; 24h') && !str_contains($trayContent, '<= 24h'),
    'Matrix and Tray views eliminate raw SQL horizon copy (&le; 24h from NOW)'
);
assertCheck(
    str_contains($matrixContent, '24-Hour Reservation Horizon')
    && str_contains($trayContent, '24-Hour Reservation Horizon'),
    'Matrix and Tray views use clean operational copy (24-Hour Reservation Horizon)'
);
assertCheck(
    !str_contains($matrixContent, 'Open Staging Tray'),
    'Redundant Open Staging Tray button removed from below Matrix page title'
);
assertCheck(
    str_contains($matrixContent, 'pve-banner')
    && str_contains($matrixContent, 'PROXMOX VE HYPERVISOR TELEMETRY'),
    'Matrix view includes dedicated Live Proxmox VE Hypervisor Telemetry Banner'
);
assertCheck(
    str_contains($matrixContent, 'meter-track')
    && str_contains($matrixContent, 'meter-fill')
    && str_contains($matrixContent, 'telemetry-block'),
    'Matrix view includes 4px node card progress meters for CPU, RAM, and VRAM'
);
assertCheck(
    str_contains($matrixContent, 'availability-strip')
    && str_contains($matrixContent, 'availability-segment')
    && str_contains($matrixContent, 'availability-legend'),
    'Matrix view includes 24-hour availability strip with segment tooltips and legend'
);

$cssContent = (string) file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
assertCheck(
    str_contains($cssContent, '@media (min-width: 1024px)')
    && str_contains($cssContent, 'flex-wrap: nowrap'),
    'style.css contains single-row desktop flexbox navigation rule'
);
assertCheck(
    str_contains($cssContent, '.status-dot')
    && str_contains($cssContent, 'pulse-green'),
    'style.css contains 6px status indicator dot and pulsing green animation'
);

// 13. Testing 24-Hour Availability Strip Algorithm
echo "\n13. Testing 24-Hour Availability Strip Algorithm...\n";
$availability = $nodeRepo->get24HourAvailability($now);
assertCheck(
    count($availability) === 10,
    sprintf('Availability calculated for all 10 compute nodes (got %d)', count($availability))
);

$all24Slots = true;
$validTooltipFormat = true;
foreach ($availability as $nid => $slots) {
    if (count($slots) !== 24) {
        $all24Slots = false;
    }
    foreach ($slots as $slot) {
        if (!preg_match('/^[0-9]{2}:00 - [0-9]{2}:00: (FREE|BOOKED|MAINTENANCE)$/', (string) ($slot['tooltip'] ?? ''))) {
            $validTooltipFormat = false;
        }
    }
}
assertCheck($all24Slots, 'Every compute node has exactly 24 hourly availability slots');
assertCheck($validTooltipFormat, 'All availability slots follow exact tooltip contract ({start}:00 - {end}:00: STATUS)');

// Node 4 is MAINTENANCE in seed data
$node4Slots = $availability[4] ?? [];
$allMaintenance = count($node4Slots) === 24;
foreach ($node4Slots as $slot) {
    if ($slot['status'] !== 'MAINTENANCE') {
        $allMaintenance = false;
        break;
    }
}
assertCheck($allMaintenance, 'Node in MAINTENANCE state marks all 24 slots as MAINTENANCE');

// Cleanup test allocation and restore Student #1 initial credit balance so test suite is idempotent
if (!empty($validStudentJob['allocation_id'])) {
    $cleanupStmt = $pdo->prepare('DELETE FROM allocations WHERE id = :id');
    $cleanupStmt->execute([':id' => (int) $validStudentJob['allocation_id']]);
}
$resetUserStmt = $pdo->prepare('UPDATE users SET credit_balance = :bal WHERE id = 1');
$resetUserStmt->execute([':bal' => number_format($balanceBefore, 2, '.', '')]);

echo "\n=================================================================\n";
echo sprintf("Test Summary: %d Passed, %d Failed\n", $passed, $failed);
echo "=================================================================\n";

exit($failed > 0 ? 1 : 0);
