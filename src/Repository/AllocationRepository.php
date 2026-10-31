<?php

declare(strict_types=1);

namespace VKU\Allocator\Repository;

use DateTimeImmutable;
use PDO;
use Throwable;
use VKU\Allocator\Service\ProxmoxLifecycleService;

final readonly class AllocationRepository
{
    public function __construct(
        private PDO $pdo,
        private ?ProxmoxLifecycleService $lifecycleService = null,
    ) {}

    /**
     * Synchronizes SCHEDULED -> ACTIVE and SCHEDULED/ACTIVE -> EXPIRED leases based on current wall-clock time.
     */
    public function syncTemporalStatuses(?DateTimeImmutable $now = null): void
    {
        $currentTs = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        // 1. Query candidate expiring leases joining compute_nodes
        $expiringStmt = $this->pdo->prepare(
            "SELECT al.id, n.hostname
             FROM allocation_leases al
             INNER JOIN compute_nodes n ON n.id = al.node_id
             WHERE al.lease_status IN ('SCHEDULED', 'ACTIVE')
               AND al.end_time <= :now_ts"
        );
        $expiringStmt->execute([':now_ts' => $currentTs]);
        $expiringLeases = $expiringStmt->fetchAll(PDO::FETCH_ASSOC);

        // Flip leases whose end_time has passed to EXPIRED
        $expireStmt = $this->pdo->prepare(
            "UPDATE allocation_leases
             SET lease_status = 'EXPIRED'
             WHERE lease_status IN ('SCHEDULED', 'ACTIVE')
               AND end_time <= :now_ts"
        );
        $expireStmt->execute([':now_ts' => $currentTs]);

        // 2. Query candidate activating scheduled leases joining compute_nodes
        $activatingStmt = $this->pdo->prepare(
            "SELECT al.id, n.hostname
             FROM allocation_leases al
             INNER JOIN compute_nodes n ON n.id = al.node_id
             WHERE al.lease_status = 'SCHEDULED'
               AND al.start_time <= :now_ts_1
               AND al.end_time > :now_ts_2"
        );
        $activatingStmt->execute([
            ':now_ts_1' => $currentTs,
            ':now_ts_2' => $currentTs,
        ]);
        $activatingLeases = $activatingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Flip SCHEDULED leases whose start_time has arrived to ACTIVE
        $activateStmt = $this->pdo->prepare(
            "UPDATE allocation_leases
             SET lease_status = 'ACTIVE'
             WHERE lease_status = 'SCHEDULED'
               AND start_time <= :now_ts_1
               AND end_time > :now_ts_2"
        );
        $activateStmt->execute([
            ':now_ts_1' => $currentTs,
            ':now_ts_2' => $currentTs,
        ]);

        if ($this->lifecycleService !== null) {
            $hasVorServerExpired = false;
            foreach ($expiringLeases as $l) {
                if (strcasecmp((string) $l['hostname'], 'vor-server') === 0) {
                    $hasVorServerExpired = true;
                    break;
                }
            }
            if ($hasVorServerExpired) {
                try {
                    $this->lifecycleService->stopGuest();
                } catch (Throwable) {
                }
            }

            $hasVorServerActivated = false;
            foreach ($activatingLeases as $l) {
                if (strcasecmp((string) $l['hostname'], 'vor-server') === 0) {
                    $hasVorServerActivated = true;
                    break;
                }
            }
            if ($hasVorServerActivated) {
                try {
                    $this->lifecycleService->startGuest();
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * Counts how many SCHEDULED or ACTIVE leases a user currently holds across the entire system.
     */
    public function countUserActiveOrScheduledLeases(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM allocation_leases al
             INNER JOIN allocations a ON a.id = al.allocation_id
             WHERE a.user_id = :user_id
               AND al.lease_status IN ('SCHEDULED', 'ACTIVE')"
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Checks if a specific node has any overlapping SCHEDULED or ACTIVE lease in [startTime, endTime].
     * Uses standard interval overlap predicate: StartA < EndB AND EndA > StartB.
     */
    public function hasOverlappingLease(
        int $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
    ): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM allocation_leases
             WHERE node_id = :node_id
               AND lease_status IN ('SCHEDULED', 'ACTIVE')
               AND start_time < :req_end
               AND end_time > :req_start"
        );
        $stmt->execute([
            ':node_id' => $nodeId,
            ':req_start' => $startTime->format('Y-m-d H:i:s'),
            ':req_end' => $endTime->format('Y-m-d H:i:s'),
        ]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Checks if an ephemeral port is already in use by any SCHEDULED or ACTIVE lease.
     */
    public function isPortInUse(int $port): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM allocation_leases
             WHERE assigned_port = :port
               AND lease_status IN ('SCHEDULED', 'ACTIVE')"
        );
        $stmt->execute([':port' => $port]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public function createAllocationHeader(
        int $userId,
        string $projectName,
        string $dockerImage,
        ?string $startupScript,
        float $totalCreditsCost,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO allocations (user_id, project_name, docker_image, startup_script, total_credits_cost)
             VALUES (:user_id, :project_name, :docker_image, :startup_script, :total_credits_cost)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':project_name' => $projectName,
            ':docker_image' => $dockerImage,
            ':startup_script' => ($startupScript !== null && trim($startupScript) !== '') ? trim($startupScript) : null,
            ':total_credits_cost' => number_format($totalCreditsCost, 2, '.', ''),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createLease(
        int $allocationId,
        int $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float $hourlyRateSnapshot,
        int $assignedPort,
        string $accessToken,
        string $leaseStatus,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO allocation_leases
                (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, assigned_port, access_token, lease_status)
             VALUES
                (:allocation_id, :node_id, :start_time, :end_time, :hourly_rate_snapshot, :assigned_port, :access_token, :lease_status)'
        );
        $stmt->execute([
            ':allocation_id' => $allocationId,
            ':node_id' => $nodeId,
            ':start_time' => $startTime->format('Y-m-d H:i:s'),
            ':end_time' => $endTime->format('Y-m-d H:i:s'),
            ':hourly_rate_snapshot' => number_format($hourlyRateSnapshot, 2, '.', ''),
            ':assigned_port' => $assignedPort,
            ':access_token' => $accessToken,
            ':lease_status' => $leaseStatus,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Locks a specific lease row FOR UPDATE along with its allocation owner.
     *
     * @return array<string, mixed>|null
     */
    public function lockLeaseForUpdate(int $leaseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                al.id,
                al.allocation_id,
                al.node_id,
                al.start_time,
                al.end_time,
                al.hourly_rate_snapshot,
                al.refunded_credits,
                al.assigned_port,
                al.access_token,
                al.lease_status,
                al.terminated_at,
                a.user_id,
                a.project_name,
                n.hostname
             FROM allocation_leases al
             INNER JOIN allocations a ON a.id = al.allocation_id
             INNER JOIN compute_nodes n ON n.id = al.node_id
             WHERE al.id = :lease_id
             FOR UPDATE'
        );
        $stmt->execute([':lease_id' => $leaseId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function markLeaseTerminated(
        int $leaseId,
        float $refundedCredits,
        DateTimeImmutable $terminatedAt,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE allocation_leases
             SET lease_status = 'CANCELLED',
                 refunded_credits = :refunded_credits,
                 terminated_at = :terminated_at
             WHERE id = :id"
        );
        $stmt->execute([
            ':refunded_credits' => number_format($refundedCredits, 2, '.', ''),
            ':terminated_at' => $terminatedAt->format('Y-m-d H:i:s'),
            ':id' => $leaseId,
        ]);
    }

    /**
     * Fetches all leases for a specific user (for the Console Dashboard).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findLeasesByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                al.id AS lease_id,
                al.allocation_id,
                al.node_id,
                al.start_time,
                al.end_time,
                al.hourly_rate_snapshot,
                al.refunded_credits,
                al.assigned_port,
                al.access_token,
                al.lease_status,
                al.terminated_at,
                a.project_name,
                a.docker_image,
                a.startup_script,
                a.total_credits_cost,
                a.created_at AS allocation_created_at,
                n.hostname,
                n.ip_address,
                n.gpu_model,
                n.vram_gb,
                n.ram_gb,
                n.cpu_cores,
                t.name AS tier_name,
                t.code AS tier_code
             FROM allocation_leases al
             INNER JOIN allocations a ON a.id = al.allocation_id
             INNER JOIN compute_nodes n ON n.id = al.node_id
             INNER JOIN resource_tiers t ON t.id = n.tier_id
             WHERE a.user_id = :user_id
             ORDER BY
                CASE al.lease_status
                    WHEN "ACTIVE" THEN 1
                    WHEN "SCHEDULED" THEN 2
                    WHEN "EXPIRED" THEN 3
                    ELSE 4
                END ASC,
                al.start_time DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Fetches recent cluster-wide leases for the Admin overview.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllRecentLeases(int $limit = 30): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                al.id AS lease_id,
                al.allocation_id,
                al.start_time,
                al.end_time,
                al.hourly_rate_snapshot,
                al.refunded_credits,
                al.assigned_port,
                al.lease_status,
                al.terminated_at,
                a.project_name,
                u.student_code,
                u.full_name,
                u.role AS user_role,
                n.hostname,
                t.name AS tier_name
             FROM allocation_leases al
             INNER JOIN allocations a ON a.id = al.allocation_id
             INNER JOIN users u ON u.id = a.user_id
             INNER JOIN compute_nodes n ON n.id = al.node_id
             INNER JOIN resource_tiers t ON t.id = n.tier_id
             ORDER BY al.id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Verifies a 32-character hex access_token belongs to a currently ACTIVE lease on the target node.
     *
     * @return array<string, mixed>|null
     */
    public function verifyActiveShellToken(
        string $token,
        string $hostname = 'vor-server',
        ?DateTimeImmutable $now = null,
    ): ?array {
        $cleanToken = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{32}$/', $cleanToken) !== 1) {
            return null;
        }

        $currentTs = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            "SELECT
                al.id AS lease_id,
                al.allocation_id,
                al.start_time,
                al.end_time,
                al.assigned_port,
                al.lease_status,
                a.project_name,
                u.student_code,
                n.hostname
             FROM allocation_leases al
             INNER JOIN allocations a ON a.id = al.allocation_id
             INNER JOIN users u ON u.id = a.user_id
             INNER JOIN compute_nodes n ON n.id = al.node_id
             WHERE al.access_token = :token
               AND al.lease_status = 'ACTIVE'
               AND al.start_time <= :now_ts_1
               AND al.end_time > :now_ts_2
               AND LOWER(n.hostname) = LOWER(:hostname)
             LIMIT 1"
        );
        $stmt->execute([
            ':token' => $cleanToken,
            ':now_ts_1' => $currentTs,
            ':now_ts_2' => $currentTs,
            ':hostname' => trim($hostname),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
