<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

use DateTimeImmutable;
use PDO;
use Throwable;
use VKU\Allocator\Domain\Enum\LeaseStatus;
use VKU\Allocator\Domain\Enum\NodeStatus;
use VKU\Allocator\Domain\Enum\RuntimePreset;
use VKU\Allocator\Domain\Enum\UserRole;
use VKU\Allocator\Repository\AllocationRepository;
use VKU\Allocator\Repository\NodeRepository;
use VKU\Allocator\Repository\UserRepository;
use VKU\Allocator\Service\ProxmoxLifecycleService;

final readonly class ProvisioningService
{
    public function __construct(
        private PDO $pdo,
        private NodeRepository $nodeRepository,
        private UserRepository $userRepository,
        private AllocationRepository $allocationRepository,
        private ?ProxmoxLifecycleService $lifecycleService = null,
    ) {}

    /**
     * Loop C: Atomic Transactional Validation, Lock & Provisioning.
     *
     * @param array<int, int> $stagedNodeHours Map of node_id => hours
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   allocation_id?: int,
     *   total_cost?: float,
     *   leases?: list<array<string, mixed>>
     * }
     */
    public function provisionJob(
        int $userId,
        string $projectName,
        string $dockerImage,
        string $rawStartTime,
        ?string $startupScript,
        array $stagedNodeHours,
        ?string $submittedSshKey = null,
        ?DateTimeImmutable $nowOverride = null,
    ): array {
        $now = $nowOverride ?? new DateTimeImmutable();

        // 1. Validate project name and runtime image preset
        $cleanProject = preg_replace('/[^a-zA-Z0-9._-]/', '-', trim($projectName)) ?? '';
        if ($cleanProject === '' || strlen($cleanProject) > 100) {
            return ['ok' => false, 'error' => 'Project name must be between 1 and 100 valid characters.'];
        }

        $preset = RuntimePreset::tryFrom($dockerImage);
        if ($preset === null) {
            return ['ok' => false, 'error' => 'Selected container runtime preset is invalid.'];
        }

        if ($stagedNodeHours === []) {
            return ['ok' => false, 'error' => 'Your Job Spec Tray is empty. Stage at least one hardware node.'];
        }

        // 2. Validate 24-hour booking horizon
        $horizonCheck = StagingTrayService::validateStartTimeHorizon($rawStartTime, $now);
        if (!$horizonCheck['ok'] || !isset($horizonCheck['dt'])) {
            return ['ok' => false, 'error' => $horizonCheck['error'] ?? 'Invalid booking time window.'];
        }
        $startTime = $horizonCheck['dt'];

        // Begin Atomic SQL Transaction
        $this->pdo->beginTransaction();

        try {
            // Sync temporal statuses first so expired leases don't block or count against quotas
            $this->allocationRepository->syncTemporalStatuses($now);

            // 3. Lock the User row FOR UPDATE
            $user = $this->userRepository->lockUserForUpdate($userId);
            if ($user === null) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Authenticated user account not found.'];
            }

            // 4. Validate SSH Public Key (either submitted inline or already on profile)
            $effectiveSshKey = ($submittedSshKey !== null && trim($submittedSshKey) !== '')
                ? trim($submittedSshKey)
                : (string) ($user['ssh_public_key'] ?? '');

            if (!SshKeyValidator::isValid($effectiveSshKey)) {
                $this->pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Valid OpenSSH public key (ssh-ed25519, ssh-rsa, or ecdsa-sha2-nistp*) is required to provision hardware.',
                ];
            }

            if ($submittedSshKey !== null && trim($submittedSshKey) !== '' && $effectiveSshKey !== ($user['ssh_public_key'] ?? '')) {
                $this->userRepository->updateSshKey($userId, $effectiveSshKey);
            }

            // 5. Enforce Role-Based Hoarding Prevention Limits
            $role = UserRole::from((string) $user['role']);
            $requestedNodeCount = count($stagedNodeHours);
            $maxNodesPerJob = $role->maxNodesPerJob();

            if ($requestedNodeCount > $maxNodesPerJob) {
                $this->pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => sprintf(
                        'Hoarding prevention policy: %s accounts can lease at most %d node(s) per job (requested %d).',
                        $role->value,
                        $maxNodesPerJob,
                        $requestedNodeCount
                    ),
                ];
            }

            $maxConcurrentSystemLeases = $role->maxConcurrentActiveLeases();
            if ($maxConcurrentSystemLeases !== null) {
                $currentActiveCount = $this->allocationRepository->countUserActiveOrScheduledLeases($userId);
                if (($currentActiveCount + $requestedNodeCount) > $maxConcurrentSystemLeases) {
                    $this->pdo->rollBack();
                    return [
                        'ok' => false,
                        'error' => sprintf(
                            'Hoarding prevention policy: %s accounts may hold at most %d active/scheduled lease across the system at any time (you currently have %d). Terminate your existing lease before booking another.',
                            $role->value,
                            $maxConcurrentSystemLeases,
                            $currentActiveCount
                        ),
                    ];
                }
            }

            // 6. Lock all requested compute_nodes rows FOR UPDATE in deterministic order
            $nodeIds = array_map('intval', array_keys($stagedNodeHours));
            $lockedNodes = $this->nodeRepository->lockNodesForUpdate($nodeIds);

            if (count($lockedNodes) !== count($nodeIds)) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'One or more requested hardware nodes no longer exist.'];
            }

            // 7. Validate each node's status, tier max_allocation_hours, and time-window collision
            $totalCost = 0.0;
            $preparedLeases = [];

            foreach ($stagedNodeHours as $rawNodeId => $rawHours) {
                $nodeId = (int) $rawNodeId;
                $hours = (int) $rawHours;
                $node = $lockedNodes[$nodeId];

                $nodeStatus = NodeStatus::from((string) $node['status']);
                if (!$nodeStatus->isAllocatable()) {
                    $this->pdo->rollBack();
                    return [
                        'ok' => false,
                        'error' => sprintf(
                            'Node %s is currently %s and cannot be allocated.',
                            $node['hostname'],
                            $nodeStatus->value
                        ),
                    ];
                }

                $maxTierHours = (int) $node['max_allocation_hours'];
                if ($hours < 1 || $hours > $maxTierHours) {
                    $this->pdo->rollBack();
                    return [
                        'ok' => false,
                        'error' => sprintf(
                            'Duration for %s (%s) must be between 1 and %d hours (requested %d hours).',
                            $node['hostname'],
                            $node['tier_name'],
                            $maxTierHours,
                            $hours
                        ),
                    ];
                }

                $endTime = $startTime->modify(sprintf('+%d hours', $hours));

                if ($this->allocationRepository->hasOverlappingLease($nodeId, $startTime, $endTime)) {
                    $this->pdo->rollBack();
                    return [
                        'ok' => false,
                        'error' => sprintf(
                            'Schedule conflict: Node %s is already booked during %s → %s.',
                            $node['hostname'],
                            $startTime->format('Y-m-d H:i'),
                            $endTime->format('Y-m-d H:i')
                        ),
                    ];
                }

                $hourlyRate = (float) $node['credits_per_hour'];
                $lineCost = round($hours * $hourlyRate, 2);
                $totalCost = round($totalCost + $lineCost, 2);

                $preparedLeases[] = [
                    'node_id' => $nodeId,
                    'hostname' => (string) $node['hostname'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'hourly_rate' => $hourlyRate,
                    'hours' => $hours,
                    'line_cost' => $lineCost,
                ];
            }

            // 8. Verify user credit balance >= total_cost
            $currentBalance = (float) $user['credit_balance'];
            if ($currentBalance < $totalCost) {
                $this->pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => sprintf(
                        'Insufficient Compute Credits: Job requires %.2f CR, but your available balance is %.2f CR.',
                        $totalCost,
                        $currentBalance
                    ),
                ];
            }

            // 9. Deduct credits & insert allocation header + lease line items
            $this->userRepository->adjustCredits($userId, -$totalCost);

            $allocationId = $this->allocationRepository->createAllocationHeader(
                userId: $userId,
                projectName: $cleanProject,
                dockerImage: $preset->value,
                startupScript: $startupScript,
                totalCreditsCost: $totalCost,
            );

            $initialStatus = ($startTime <= $now->modify('+1 minute'))
                ? LeaseStatus::Active->value
                : LeaseStatus::Scheduled->value;

            $createdLeases = [];
            foreach ($preparedLeases as $item) {
                $port = $this->generateUniqueEphemeralPort();
                $token = bin2hex(random_bytes(16)); // 32-character hex token

                $leaseId = $this->allocationRepository->createLease(
                    allocationId: $allocationId,
                    nodeId: $item['node_id'],
                    startTime: $item['start_time'],
                    endTime: $item['end_time'],
                    hourlyRateSnapshot: $item['hourly_rate'],
                    assignedPort: $port,
                    accessToken: $token,
                    leaseStatus: $initialStatus,
                );

                $createdLeases[] = [
                    'lease_id' => $leaseId,
                    'node_id' => $item['node_id'],
                    'hostname' => $item['hostname'],
                    'assigned_port' => $port,
                    'access_token' => $token,
                    'start_time' => $item['start_time']->format('Y-m-d H:i:s'),
                    'end_time' => $item['end_time']->format('Y-m-d H:i:s'),
                    'lease_status' => $initialStatus,
                    'line_cost' => $item['line_cost'],
                ];
            }

            $this->pdo->commit();

            if ($this->lifecycleService !== null && $initialStatus === LeaseStatus::Active->value) {
                foreach ($createdLeases as $leaseItem) {
                    if (strcasecmp((string) $leaseItem['hostname'], 'vor-server') === 0) {
                        try {
                            $this->lifecycleService->startGuest();
                        } catch (Throwable) {
                        }
                        break;
                    }
                }
            }

            return [
                'ok' => true,
                'allocation_id' => $allocationId,
                'total_cost' => $totalCost,
                'leases' => $createdLeases,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'ok' => false,
                'error' => 'Transaction aborted: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Loop D: Early Termination & Pro-Rata Refund of Unused Full Hours.
     * Uses DateTimeImmutable::diff() to compute exact unused full hours and credits the user's balance.
     *
     * @return array{ok: bool, error?: string, refunded_credits?: float, unused_hours?: int, hostname?: string}
     */
    public function terminateLeaseEarly(
        int $leaseId,
        int $actingUserId,
        bool $isAdmin = false,
        ?DateTimeImmutable $nowOverride = null,
    ): array {
        $now = $nowOverride ?? new DateTimeImmutable();

        $this->pdo->beginTransaction();

        try {
            $this->allocationRepository->syncTemporalStatuses($now);

            $lease = $this->allocationRepository->lockLeaseForUpdate($leaseId);
            if ($lease === null) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Lease record not found.'];
            }

            $ownerId = (int) $lease['user_id'];
            if ($ownerId !== $actingUserId && !$isAdmin) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Unauthorized: You do not own this hardware lease.'];
            }

            $status = LeaseStatus::tryFrom((string) $lease['lease_status']);
            if ($status === null || !$status->isTerminable()) {
                $this->pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => sprintf('Lease #%d is already %s and cannot be terminated.', $leaseId, $lease['lease_status']),
                ];
            }

            // Lock owner user row FOR UPDATE before refunding credits
            $this->userRepository->lockUserForUpdate($ownerId);

            $startTime = new DateTimeImmutable((string) $lease['start_time']);
            $endTime = new DateTimeImmutable((string) $lease['end_time']);
            $hourlyRate = (float) $lease['hourly_rate_snapshot'];

            // Clamp reference start point to max($now, $startTime) so cancelling a SCHEDULED future lease
            // refunds at most the total booked hours (never more than 100%).
            $effectiveReference = $now < $startTime ? $startTime : $now;

            $unusedFullHours = 0;
            if ($effectiveReference < $endTime) {
                $diff = $effectiveReference->diff($endTime);
                $unusedFullHours = ($diff->days * 24) + $diff->h;
            }

            $refundAmount = round($unusedFullHours * $hourlyRate, 2);

            $this->allocationRepository->markLeaseTerminated($leaseId, $refundAmount, $now);

            if ($refundAmount > 0.0) {
                $this->userRepository->adjustCredits($ownerId, $refundAmount);
            }

            $this->pdo->commit();

            if ($this->lifecycleService !== null && strcasecmp((string) $lease['hostname'], 'vor-server') === 0) {
                try {
                    $this->lifecycleService->stopGuest();
                } catch (Throwable) {
                }
            }

            return [
                'ok' => true,
                'refunded_credits' => $refundAmount,
                'unused_hours' => $unusedFullHours,
                'hostname' => (string) $lease['hostname'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return [
                'ok' => false,
                'error' => 'Failed to terminate lease: ' . $e->getMessage(),
            ];
        }
    }

    private function generateUniqueEphemeralPort(): int
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $port = random_int(20000, 60000);
            if (!$this->allocationRepository->isPortInUse($port)) {
                return $port;
            }
        }
        return random_int(20000, 60000);
    }
}
