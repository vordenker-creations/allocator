<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

use DateTimeImmutable;
use Exception;
use VKU\Allocator\Domain\Enum\NodeStatus;
use VKU\Allocator\Domain\Enum\RuntimePreset;
use VKU\Allocator\Domain\Enum\UserRole;
use VKU\Allocator\Repository\NodeRepository;

final readonly class StagingTrayService
{
    public function __construct(
        private NodeRepository $nodeRepository,
    ) {}

    /**
     * Initializes and returns the current session-backed job spec tray ($_SESSION['job_spec']).
     *
     * @return array{
     *   project_name: string,
     *   docker_image: string,
     *   start_time: string,
     *   startup_script: string,
     *   nodes: array<int, array{node_id: int, hours: int}>
     * }
     */
    public function getTray(): array
    {
        $defaultStart = (new DateTimeImmutable())->format('Y-m-d\TH:i');

        if (!isset($_SESSION['job_spec']) || !is_array($_SESSION['job_spec'])) {
            $_SESSION['job_spec'] = [
                'project_name' => 'vku-research-job',
                'docker_image' => RuntimePreset::PyTorchCuda->value,
                'start_time' => $defaultStart,
                'startup_script' => '',
                'nodes' => [],
            ];
        }

        // Ensure start_time is never stale in the past if left in session
        $now = new DateTimeImmutable('-2 minutes');
        try {
            $parsed = new DateTimeImmutable((string) ($_SESSION['job_spec']['start_time'] ?? $defaultStart));
            if ($parsed < $now) {
                $_SESSION['job_spec']['start_time'] = $defaultStart;
            }
        } catch (Exception) {
            $_SESSION['job_spec']['start_time'] = $defaultStart;
        }

        return $_SESSION['job_spec'];
    }

    /**
     * Adds or updates a node in the staging tray while enforcing role-based node caps and tier max_allocation_hours.
     *
     * @return array{ok: bool, message: string}
     */
    public function stageNode(
        int $nodeId,
        int $requestedHours,
        ?UserRole $userRole = null,
        ?string $requestedStartTime = null,
    ): array {
        $tray = $this->getTray();
        $node = $this->nodeRepository->findByIdWithTier($nodeId);

        if ($node === null) {
            return ['ok' => false, 'message' => 'Selected compute node does not exist.'];
        }

        $status = NodeStatus::tryFrom((string) $node['status']);
        if ($status === null || !$status->isAllocatable()) {
            return [
                'ok' => false,
                'message' => sprintf('Node %s is currently in %s mode and cannot be staged.', $node['hostname'], $node['status']),
            ];
        }

        $maxHoursForTier = (int) $node['max_allocation_hours'];
        if ($requestedHours < 1 || $requestedHours > $maxHoursForTier) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Duration for %s (%s) must be between 1 and %d hours.',
                    $node['hostname'],
                    $node['tier_name'],
                    $maxHoursForTier
                ),
            ];
        }

        // Enforce role-based per-job staging limit if adding a new node
        $effectiveRole = $userRole ?? UserRole::Student;
        $maxNodesPerJob = $effectiveRole->maxNodesPerJob();
        $alreadyStaged = isset($tray['nodes'][$nodeId]);

        if (!$alreadyStaged && count($tray['nodes']) >= $maxNodesPerJob) {
            $roleName = $effectiveRole->value;
            return [
                'ok' => false,
                'message' => sprintf(
                    'Anti-hoarding policy: %s accounts can stage at most %d node(s) per job.',
                    $roleName,
                    $maxNodesPerJob
                ),
            ];
        }

        if ($requestedStartTime !== null && trim($requestedStartTime) !== '') {
            $validation = self::validateStartTimeHorizon($requestedStartTime);
            if ($validation['ok'] && isset($validation['dt'])) {
                $tray['start_time'] = $validation['dt']->format('Y-m-d\TH:i');
            }
        }

        $tray['nodes'][$nodeId] = [
            'node_id' => $nodeId,
            'hours' => $requestedHours,
        ];

        $_SESSION['job_spec'] = $tray;

        return [
            'ok' => true,
            'message' => sprintf('Staged %s for %d hour(s) into Job Spec Tray.', $node['hostname'], $requestedHours),
        ];
    }

    public function removeNode(int $nodeId): void
    {
        $tray = $this->getTray();
        unset($tray['nodes'][$nodeId]);
        $_SESSION['job_spec'] = $tray;
    }

    public function clearTray(): void
    {
        unset($_SESSION['job_spec']);
    }

    /**
     * Updates job configuration parameters (project_name, docker_image, start_time, startup_script, per-node hours).
     *
     * @param array<int|string, int|string> $nodeHoursMap
     * @return array{ok: bool, message: string}
     */
    public function updateJobSpec(
        string $projectName,
        string $dockerImage,
        string $startTimeRaw,
        string $startupScript,
        array $nodeHoursMap,
        ?UserRole $userRole = null,
    ): array {
        $tray = $this->getTray();

        $cleanProject = preg_replace('/[^a-zA-Z0-9._-]/', '-', trim($projectName)) ?? '';
        if ($cleanProject === '' || strlen($cleanProject) > 100) {
            return ['ok' => false, 'message' => 'Project label must be 1–100 characters (alphanumeric, dashes, dots).'];
        }

        $preset = RuntimePreset::tryFrom($dockerImage);
        if ($preset === null) {
            return ['ok' => false, 'message' => 'Invalid runtime container environment preset selected.'];
        }

        $horizonCheck = self::validateStartTimeHorizon($startTimeRaw);
        if (!$horizonCheck['ok'] || !isset($horizonCheck['dt'])) {
            return ['ok' => false, 'message' => $horizonCheck['error'] ?? 'Invalid start time.'];
        }

        $effectiveRole = $userRole ?? UserRole::Student;
        if (count($tray['nodes']) > $effectiveRole->maxNodesPerJob()) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Anti-hoarding policy: %s accounts can stage at most %d node(s) per job. Remove excess nodes first.',
                    $effectiveRole->value,
                    $effectiveRole->maxNodesPerJob()
                ),
            ];
        }

        foreach ($tray['nodes'] as $nodeId => $spec) {
            $node = $this->nodeRepository->findByIdWithTier((int) $nodeId);
            if ($node === null) {
                unset($tray['nodes'][$nodeId]);
                continue;
            }

            $hours = isset($nodeHoursMap[$nodeId]) ? (int) $nodeHoursMap[$nodeId] : (int) $spec['hours'];
            $maxHours = (int) $node['max_allocation_hours'];

            if ($hours < 1 || $hours > $maxHours) {
                return [
                    'ok' => false,
                    'message' => sprintf(
                        'Node %s (%s) duration must be between 1 and %d hours.',
                        $node['hostname'],
                        $node['tier_name'],
                        $maxHours
                    ),
                ];
            }

            $tray['nodes'][$nodeId]['hours'] = $hours;
        }

        $tray['project_name'] = $cleanProject;
        $tray['docker_image'] = $preset->value;
        $tray['start_time'] = $horizonCheck['dt']->format('Y-m-d\TH:i');
        $tray['startup_script'] = trim($startupScript);

        $_SESSION['job_spec'] = $tray;

        return ['ok' => true, 'message' => 'Job configuration updated.'];
    }

    /**
     * Hydrates the staged nodes with live database info, start/end timestamps, and dynamic credit cost breakdown.
     *
     * @return array{
     *   project_name: string,
     *   docker_image: string,
     *   start_time_input: string,
     *   start_time_dt: DateTimeImmutable,
     *   startup_script: string,
     *   items: list<array<string, mixed>>,
     *   total_credits: float,
     *   node_count: int
     * }
     */
    public function getHydratedTray(): array
    {
        $tray = $this->getTray();
        $horizon = self::validateStartTimeHorizon($tray['start_time']);
        $startTimeDt = $horizon['dt'] ?? new DateTimeImmutable();

        $items = [];
        $totalCredits = 0.0;

        foreach ($tray['nodes'] as $nodeId => $spec) {
            $node = $this->nodeRepository->findByIdWithTier((int) $nodeId);
            if ($node === null) {
                continue;
            }

            $maxHours = (int) $node['max_allocation_hours'];
            $hours = max(1, min((int) $spec['hours'], $maxHours));
            $rate = (float) $node['credits_per_hour'];
            $lineCost = round($hours * $rate, 2);
            $endTimeDt = $startTimeDt->modify(sprintf('+%d hours', $hours));

            $items[] = [
                'node_id' => (int) $node['id'],
                'hostname' => (string) $node['hostname'],
                'ip_address' => (string) $node['ip_address'],
                'gpu_model' => $node['gpu_model'],
                'vram_gb' => (int) $node['vram_gb'],
                'ram_gb' => (int) $node['ram_gb'],
                'cpu_cores' => (int) $node['cpu_cores'],
                'tier_name' => (string) $node['tier_name'],
                'max_allocation_hours' => $maxHours,
                'credits_per_hour' => $rate,
                'hours' => $hours,
                'line_cost' => $lineCost,
                'start_time' => $startTimeDt,
                'end_time' => $endTimeDt,
                'status' => (string) $node['status'],
            ];

            $totalCredits += $lineCost;
        }

        return [
            'project_name' => $tray['project_name'],
            'docker_image' => $tray['docker_image'],
            'start_time_input' => $startTimeDt->format('Y-m-d\TH:i'),
            'start_time_dt' => $startTimeDt,
            'startup_script' => $tray['startup_script'],
            'items' => $items,
            'total_credits' => round($totalCredits, 2),
            'node_count' => count($items),
        ];
    }

    /**
     * Validates that a requested job start time is within [NOW() - 5 mins, NOW() + 24 hours].
     *
     * @return array{ok: bool, dt?: DateTimeImmutable, error?: string}
     */
    public static function validateStartTimeHorizon(string $rawStartTime, ?DateTimeImmutable $now = null): array
    {
        $current = $now ?? new DateTimeImmutable();

        try {
            $parsed = new DateTimeImmutable(trim($rawStartTime));
        } catch (Exception) {
            return ['ok' => false, 'error' => 'Malformed start timestamp format.'];
        }

        $earliestAllowed = $current->modify('-5 minutes');
        $latestAllowed = $current->modify('+24 hours');

        if ($parsed < $earliestAllowed) {
            return [
                'ok' => false,
                'error' => 'Job start time cannot be in the past.',
            ];
        }

        if ($parsed > $latestAllowed) {
            return [
                'ok' => false,
                'error' => 'Booking horizon exceeded: Jobs can only be scheduled within 24 hours from NOW().',
            ];
        }

        // Clamp immediate bookings within the 5-minute grace window to $current so start_time >= NOW()
        if ($parsed < $current) {
            $parsed = $current;
        }

        return ['ok' => true, 'dt' => $parsed];
    }
}
