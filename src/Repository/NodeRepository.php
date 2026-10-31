<?php

declare(strict_types=1);

namespace VKU\Allocator\Repository;

use DateTimeImmutable;
use PDO;
use VKU\Allocator\Domain\Enum\NodeStatus;

final readonly class NodeRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllTiers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, code, name, description, max_allocation_hours
             FROM resource_tiers
             ORDER BY id ASC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Fetches all nodes with tier metadata and checks if each node has an overlapping lease
     * in the requested [startTime, endTime] window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findNodesWithWindowAvailability(
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        int $minVramGb = 0,
        ?int $tierId = null,
    ): array {
        $sql = <<<'SQL'
            SELECT
                n.id,
                n.tier_id,
                n.hostname,
                n.ip_address,
                n.gpu_model,
                n.vram_gb,
                n.ram_gb,
                n.cpu_cores,
                n.credits_per_hour,
                n.status,
                t.code AS tier_code,
                t.name AS tier_name,
                t.description AS tier_description,
                t.max_allocation_hours,
                (
                    SELECT COUNT(*)
                    FROM allocation_leases al
                    WHERE al.node_id = n.id
                      AND al.lease_status IN ('SCHEDULED', 'ACTIVE')
                      AND al.start_time < :win_end
                      AND al.end_time > :win_start
                ) AS overlapping_leases_count,
                (
                    SELECT MIN(al2.end_time)
                    FROM allocation_leases al2
                    WHERE al2.node_id = n.id
                      AND al2.lease_status IN ('SCHEDULED', 'ACTIVE')
                      AND al2.start_time < :win_end_2
                      AND al2.end_time > :win_start_2
                ) AS busy_until
            FROM compute_nodes n
            INNER JOIN resource_tiers t ON t.id = n.tier_id
            WHERE n.vram_gb >= :min_vram
        SQL;

        $params = [
            ':win_start' => $windowStart->format('Y-m-d H:i:s'),
            ':win_end' => $windowEnd->format('Y-m-d H:i:s'),
            ':win_start_2' => $windowStart->format('Y-m-d H:i:s'),
            ':win_end_2' => $windowEnd->format('Y-m-d H:i:s'),
            ':min_vram' => $minVramGb,
        ];

        if ($tierId !== null && $tierId > 0) {
            $sql .= ' AND n.tier_id = :tier_id';
            $params[':tier_id'] = $tierId;
        }

        $sql .= ' ORDER BY t.id ASC, n.hostname ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdWithTier(int $nodeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                n.id,
                n.tier_id,
                n.hostname,
                n.ip_address,
                n.gpu_model,
                n.vram_gb,
                n.ram_gb,
                n.cpu_cores,
                n.credits_per_hour,
                n.status,
                t.code AS tier_code,
                t.name AS tier_name,
                t.max_allocation_hours
             FROM compute_nodes n
             INNER JOIN resource_tiers t ON t.id = n.tier_id
             WHERE n.id = :id'
        );
        $stmt->execute([':id' => $nodeId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Locks requested compute_nodes rows FOR UPDATE inside a transaction to serialize bookings per node.
     *
     * @param list<int> $nodeIds
     * @return array<int, array<string, mixed>> Indexed by node ID
     */
    public function lockNodesForUpdate(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $uniqueIds = array_values(array_unique(array_map('intval', $nodeIds)));
        sort($uniqueIds);

        $placeholders = implode(', ', array_fill(0, count($uniqueIds), '?'));
        $sql = sprintf(
            'SELECT
                n.id,
                n.tier_id,
                n.hostname,
                n.ip_address,
                n.gpu_model,
                n.vram_gb,
                n.ram_gb,
                n.cpu_cores,
                n.credits_per_hour,
                n.status,
                t.name AS tier_name,
                t.max_allocation_hours
             FROM compute_nodes n
             INNER JOIN resource_tiers t ON t.id = n.tier_id
             WHERE n.id IN (%s)
             ORDER BY n.id ASC
             FOR UPDATE',
            $placeholders
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($uniqueIds);

        $indexed = [];
        foreach ($stmt->fetchAll() as $row) {
            $indexed[(int) $row['id']] = $row;
        }
        return $indexed;
    }

    public function updateStatus(int $nodeId, NodeStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE compute_nodes SET status = :status WHERE id = :id');
        $stmt->execute([
            ':status' => $status->value,
            ':id' => $nodeId,
        ]);
    }

    public function createNode(
        int $tierId,
        string $hostname,
        string $ipAddress,
        ?string $gpuModel,
        int $vramGb,
        int $ramGb,
        int $cpuCores,
        float $creditsPerHour,
        NodeStatus $status = NodeStatus::Online,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO compute_nodes
                (tier_id, hostname, ip_address, gpu_model, vram_gb, ram_gb, cpu_cores, credits_per_hour, status)
             VALUES
                (:tier_id, :hostname, :ip_address, :gpu_model, :vram_gb, :ram_gb, :cpu_cores, :credits_per_hour, :status)'
        );
        $stmt->execute([
            ':tier_id' => $tierId,
            ':hostname' => trim($hostname),
            ':ip_address' => trim($ipAddress),
            ':gpu_model' => ($gpuModel !== null && trim($gpuModel) !== '') ? trim($gpuModel) : null,
            ':vram_gb' => $vramGb,
            ':ram_gb' => $ramGb,
            ':cpu_cores' => $cpuCores,
            ':credits_per_hour' => number_format($creditsPerHour, 2, '.', ''),
            ':status' => $status->value,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
