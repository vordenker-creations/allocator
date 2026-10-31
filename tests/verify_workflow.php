<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use VKU\Allocator\Domain\Enum\RuntimePreset;
use VKU\Allocator\Infrastructure\Database;
use VKU\Allocator\Repository\AllocationRepository;
use VKU\Allocator\Repository\NodeRepository;
use VKU\Allocator\Repository\UserRepository;
use VKU\Allocator\Service\ProvisioningService;
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
