<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

class ProxmoxTelemetryService
{
    private string $host;
    private string $node;
    private string $tokenId;
    private string $tokenSecret;
    /** @var callable|null */
    private $httpExecutor;

    public function __construct(
        ?string $host = null,
        ?string $node = null,
        ?string $tokenId = null,
        ?string $tokenSecret = null,
        ?callable $httpExecutor = null
    ) {
        $resolvedHost = ($host !== null && $host !== '')
            ? $host
            : (string) ($_ENV['PROXMOX_HOST'] ?? getenv('PROXMOX_HOST') ?: 'https://192.168.1.200:8006');
        $this->host = rtrim($resolvedHost, '/');

        $this->node = ($node !== null && $node !== '')
            ? $node
            : (string) ($_ENV['PROXMOX_NODE'] ?? getenv('PROXMOX_NODE') ?: 'vor-server');

        $this->tokenId = $tokenId !== null
            ? $tokenId
            : (string) ($_ENV['PROXMOX_TOKEN_ID'] ?? getenv('PROXMOX_TOKEN_ID') ?: '');

        $this->tokenSecret = $tokenSecret !== null
            ? $tokenSecret
            : (string) ($_ENV['PROXMOX_TOKEN_SECRET'] ?? getenv('PROXMOX_TOKEN_SECRET') ?: '');

        $this->httpExecutor = $httpExecutor;
    }

    /**
     * Retrieves hypervisor telemetry from live Proxmox REST API or simulated fallback.
     *
     * @return array{
     *   is_live: bool,
     *   telemetry_source: string,
     *   status_message: string,
     *   node_name: string,
     *   cpu_model: string,
     *   cpu_cores: int,
     *   cpu_threads: int,
     *   cpu_usage_pct: float,
     *   ram_used_gb: float,
     *   ram_total_gb: float,
     *   ram_usage_pct: float,
     *   vram_gb: int,
     *   vram_allocated_gb: int,
     *   uptime_seconds: int,
     *   uptime_formatted: string,
     *   load_average: string,
     *   running_vms: int,
     *   total_vms: int
     * }
     */
    public function getNodeTelemetry(): array
    {
        $defaultNode = $this->node !== '' ? $this->node : 'vor-server';

        if (trim($this->tokenId) === '' || trim($this->tokenSecret) === '') {
            return self::getSimulatedFallbackState($defaultNode);
        }

        $nodeName = $this->resolveNodeName();

        $statusUrl = $this->host . '/api2/json/nodes/' . rawurlencode($nodeName) . '/status';
        $statusPayload = $this->executeRequest($statusUrl);
        if ($statusPayload === null) {
            return self::getSimulatedFallbackState($nodeName);
        }

        $qemuUrl = $this->host . '/api2/json/nodes/' . rawurlencode($nodeName) . '/qemu';
        $qemuPayload = $this->executeRequest($qemuUrl);
        if ($qemuPayload === null) {
            return self::getSimulatedFallbackState($nodeName);
        }

        return self::parseLiveResponses($statusPayload, $qemuPayload, $nodeName);
    }

    /**
     * Auto-detects the active node identifier via /api2/json/nodes.
     * Falls back to configured node, then vor-server, then pve.
     */
    public function resolveNodeName(): string
    {
        $defaultFallback = $this->node !== '' ? $this->node : 'vor-server';

        if (trim($this->tokenId) === '' || trim($this->tokenSecret) === '') {
            return $defaultFallback;
        }

        $nodesUrl = $this->host . '/api2/json/nodes';
        $nodesPayload = $this->executeRequest($nodesUrl);
        if ($nodesPayload === null) {
            return $defaultFallback;
        }

        $nodesList = isset($nodesPayload['data']) && is_array($nodesPayload['data'])
            ? $nodesPayload['data']
            : (is_array($nodesPayload) ? $nodesPayload : []);

        if (empty($nodesList)) {
            return $defaultFallback;
        }

        // 1. Look for matching node name (case-insensitive)
        foreach ($nodesList as $item) {
            if (is_array($item) && isset($item['node'])) {
                if (strcasecmp((string) $item['node'], $this->node) === 0) {
                    return (string) $item['node'];
                }
            }
        }

        // 2. Look for any node with status 'online'
        foreach ($nodesList as $item) {
            if (is_array($item) && isset($item['node']) && ($item['status'] ?? '') === 'online') {
                return (string) $item['node'];
            }
        }

        // 3. Fallback to first available node in list
        foreach ($nodesList as $item) {
            if (is_array($item) && isset($item['node']) && (string) $item['node'] !== '') {
                return (string) $item['node'];
            }
        }

        return $defaultFallback;
    }

    /**
     * Generates standard fallback state dictionary.
     */
    public static function getSimulatedFallbackState(string $nodeName = 'vor-server'): array
    {
        $effectiveNode = $nodeName !== '' ? $nodeName : 'vor-server';
        $statusMsg = 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)';

        return [
            'is_live' => false,
            'telemetry_source' => $statusMsg,
            'status_message' => $statusMsg,
            'node_name' => $effectiveNode,
            'cpu_model' => 'Intel Core i5-3470 (4C/4T @ 3.20GHz) • Proxmox VE Host',
            'cpu_cores' => 4,
            'cpu_threads' => 4,
            'cpu_usage_pct' => 18.5,
            'ram_used_gb' => 6.2,
            'ram_total_gb' => 24.0,
            'ram_usage_pct' => 25.8,
            'vram_gb' => 0,
            'vram_allocated_gb' => 0,
            'uptime_seconds' => 1051200,
            'uptime_formatted' => '12d 4h 0m',
            'load_average' => '0.28, 0.35, 0.31',
            'running_vms' => 2,
            'total_vms' => 3,
        ];
    }

    /**
     * Parses raw JSON responses from /status and /qemu endpoints.
     */
    public static function parseLiveResponses(
        array $statusPayload,
        array $qemuPayload,
        string $nodeName = 'vor-server'
    ): array {
        $statusData = isset($statusPayload['data']) && is_array($statusPayload['data'])
            ? $statusPayload['data']
            : $statusPayload;
        $qemuData = isset($qemuPayload['data']) && is_array($qemuPayload['data'])
            ? $qemuPayload['data']
            : $qemuPayload;

        $cpuinfo = isset($statusData['cpuinfo']) && is_array($statusData['cpuinfo'])
            ? $statusData['cpuinfo']
            : [];

        $cpuModel = (string) ($cpuinfo['model'] ?? 'Intel Core i5-3470 (4C/4T @ 3.20GHz) • Proxmox VE Host');
        $cpuCores = (int) ($cpuinfo['cores'] ?? 4);
        $cpuThreads = (int) ($cpuinfo['cpus'] ?? $cpuCores);

        $rawCpu = (float) ($statusData['cpu'] ?? 0.0);
        $cpuUsagePct = ($rawCpu <= 1.0 && $rawCpu > 0) ? round($rawCpu * 100, 1) : round($rawCpu, 1);

        $memInfo = isset($statusData['memory']) && is_array($statusData['memory'])
            ? $statusData['memory']
            : [];

        $totalBytes = (float) ($memInfo['total'] ?? $statusData['maxmem'] ?? (24 * 1024 * 1024 * 1024));
        $usedBytes = (float) ($memInfo['used'] ?? $statusData['mem'] ?? 0);

        $ramTotalGb = $totalBytes > 1000 ? round($totalBytes / (1024 ** 3), 1) : round($totalBytes, 1);
        $ramUsedGb = $usedBytes > 1000 ? round($usedBytes / (1024 ** 3), 1) : round($usedBytes, 1);
        $ramUsagePct = $ramTotalGb > 0 ? round(($ramUsedGb / $ramTotalGb) * 100, 1) : 0.0;

        $uptimeSeconds = (int) ($statusData['uptime'] ?? 0);
        $days = intdiv($uptimeSeconds, 86400);
        $hours = intdiv($uptimeSeconds % 86400, 3600);
        $minutes = intdiv($uptimeSeconds % 3600, 60);
        $uptimeFormatted = sprintf('%dd %dh %dm', $days, $hours, $minutes);

        $rawLoadavg = $statusData['loadavg'] ?? ['0.00', '0.00', '0.00'];
        $loadAverage = is_array($rawLoadavg) ? implode(', ', $rawLoadavg) : (string) $rawLoadavg;

        $runningVms = 0;
        $totalVms = is_array($qemuData) ? count($qemuData) : 0;
        if (is_array($qemuData)) {
            foreach ($qemuData as $vm) {
                if (is_array($vm) && ($vm['status'] ?? '') === 'running') {
                    $runningVms++;
                }
            }
        }

        return [
            'is_live' => true,
            'telemetry_source' => 'LIVE PVE API',
            'status_message' => 'LIVE PVE API',
            'node_name' => $nodeName !== '' ? $nodeName : 'vor-server',
            'cpu_model' => $cpuModel,
            'cpu_cores' => $cpuCores,
            'cpu_threads' => $cpuThreads,
            'cpu_usage_pct' => $cpuUsagePct,
            'ram_used_gb' => $ramUsedGb,
            'ram_total_gb' => $ramTotalGb,
            'ram_usage_pct' => $ramUsagePct,
            'vram_gb' => 0,
            'vram_allocated_gb' => 0,
            'uptime_seconds' => $uptimeSeconds,
            'uptime_formatted' => $uptimeFormatted,
            'load_average' => $loadAverage,
            'running_vms' => $runningVms,
            'total_vms' => $totalVms,
        ];
    }

    /**
     * Simulates state-aware telemetry for non-Proxmox campus nodes.
     * (75-92% CPU load when active lease exists, 3-12% idle when free, 0% maintenance).
     */
    public static function simulateCampusNodeTelemetry(array $node, bool $hasActiveLease): array
    {
        $status = (string) ($node['status'] ?? 'ONLINE');
        $nodeId = (int) ($node['id'] ?? 1);
        $ramTotal = (int) ($node['ram_gb'] ?? 64);
        $vramTotal = (int) ($node['vram_gb'] ?? 0);

        if ($status !== 'ONLINE') {
            return [
                'cpu_usage_pct' => 0.0,
                'ram_used_gb' => 0.0,
                'ram_total_gb' => (float) $ramTotal,
                'ram_usage_pct' => 0.0,
                'vram_allocated_gb' => 0,
                'vram_total_gb' => $vramTotal,
                'vram_usage_pct' => 0.0,
            ];
        }

        if ($hasActiveLease) {
            // Active Lease: 75% - 92% CPU load
            $cpuPct = 75.0 + (($nodeId * 7) % 18);
            // RAM: 65% - 85%
            $ramPct = 65.0 + (($nodeId * 5) % 21);
            $ramUsed = round(($ramPct / 100.0) * $ramTotal, 1);
            // VRAM: 100% allocated if GPU node
            $vramAllocated = $vramTotal;
            $vramPct = $vramTotal > 0 ? 100.0 : 0.0;
        } else {
            // Idle/Free: 3% - 12% CPU load
            $cpuPct = 3.0 + (($nodeId * 3) % 10);
            // RAM: 15% - 28%
            $ramPct = 15.0 + (($nodeId * 4) % 14);
            $ramUsed = round(($ramPct / 100.0) * $ramTotal, 1);
            // VRAM: 0 GB allocated
            $vramAllocated = 0;
            $vramPct = 0.0;
        }

        return [
            'cpu_usage_pct' => round($cpuPct, 1),
            'ram_used_gb' => $ramUsed,
            'ram_total_gb' => (float) $ramTotal,
            'ram_usage_pct' => round($ramPct, 1),
            'vram_allocated_gb' => $vramAllocated,
            'vram_total_gb' => $vramTotal,
            'vram_usage_pct' => round($vramPct, 1),
        ];
    }

    /**
     * Executes an HTTP GET request against the Proxmox REST API.
     */
    private function executeRequest(string $url): ?array
    {
        $headers = [
            'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
            'Accept: application/json',
        ];

        if ($this->httpExecutor !== null) {
            $executor = $this->httpExecutor;
            $res = $executor($url, $headers);
            if (is_array($res)) {
                return $res;
            }
            if (is_string($res)) {
                $decoded = json_decode($res, true);
                return is_array($decoded) ? $decoded : null;
            }
            return null;
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT_MS => 1500,
            CURLOPT_CONNECTTIMEOUT_MS => 1500,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
