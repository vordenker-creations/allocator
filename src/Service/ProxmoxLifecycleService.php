<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

use Throwable;

class ProxmoxLifecycleService
{
    private string $host;
    private string $node;
    private string $tokenId;
    private string $tokenSecret;
    private ?int $leaseVmid = null;
    private string $guestType = 'qemu';
    private ?int $hostVmid = null;
    private ?string $cleanSnapshot = null;
    /** @var callable|null */
    private $httpExecutor;

    public function __construct(
        ?string $host = null,
        ?string $node = null,
        ?string $tokenId = null,
        ?string $tokenSecret = null,
        ?int $leaseVmid = null,
        ?string $guestType = null,
        ?int $hostVmid = null,
        ?callable $httpExecutor = null,
        ?string $cleanSnapshot = null,
    ) {
        $resolvedHost = ($host !== null && $host !== '')
            ? $host
            : ($httpExecutor === null ? (string) ($_ENV['PROXMOX_HOST'] ?? getenv('PROXMOX_HOST') ?: 'https://192.168.1.200:8006') : 'https://192.168.1.200:8006');
        $this->host = rtrim($resolvedHost, '/');

        $this->node = ($node !== null && $node !== '')
            ? $node
            : ($httpExecutor === null ? (string) ($_ENV['PROXMOX_NODE'] ?? getenv('PROXMOX_NODE') ?: 'vor-server') : 'vor-server');

        $this->tokenId = $tokenId !== null
            ? $tokenId
            : ($httpExecutor === null ? (string) ($_ENV['PROXMOX_TOKEN_ID'] ?? getenv('PROXMOX_TOKEN_ID') ?: '') : '');

        $this->tokenSecret = $tokenSecret !== null
            ? $tokenSecret
            : ($httpExecutor === null ? (string) ($_ENV['PROXMOX_TOKEN_SECRET'] ?? getenv('PROXMOX_TOKEN_SECRET') ?: '') : '');

        $rawLeaseVmid = $leaseVmid !== null
            ? $leaseVmid
            : ($httpExecutor === null ? ($_ENV['PROXMOX_LEASE_VMID'] ?? getenv('PROXMOX_LEASE_VMID') ?: null) : null);
        if ($rawLeaseVmid !== null && is_numeric($rawLeaseVmid) && (int) $rawLeaseVmid > 0) {
            $this->leaseVmid = (int) $rawLeaseVmid;
        } else {
            $this->leaseVmid = null;
        }

        $rawGuestType = ($guestType !== null && $guestType !== '')
            ? $guestType
            : ($httpExecutor === null ? (string) ($_ENV['PROXMOX_LEASE_GUEST_TYPE'] ?? getenv('PROXMOX_LEASE_GUEST_TYPE') ?: ($_ENV['PROXMOX_GUEST_TYPE'] ?? getenv('PROXMOX_GUEST_TYPE') ?: 'qemu')) : 'qemu');
        $cleanGuestType = strtolower(trim($rawGuestType));
        $this->guestType = in_array($cleanGuestType, ['qemu', 'lxc'], true) ? $cleanGuestType : 'qemu';

        $rawHostVmid = $hostVmid !== null
            ? $hostVmid
            : ($httpExecutor === null ? ($_ENV['PROXMOX_HOST_VMID'] ?? getenv('PROXMOX_HOST_VMID') ?: null) : null);
        if ($rawHostVmid !== null && is_numeric($rawHostVmid) && (int) $rawHostVmid > 0) {
            $this->hostVmid = (int) $rawHostVmid;
        } else {
            $this->hostVmid = null;
        }

        $rawCleanSnap = $cleanSnapshot !== null
            ? $cleanSnapshot
            : ($httpExecutor === null
                ? (string) ($_ENV['PROXMOX_CLEAN_SNAPSHOT'] ?? getenv('PROXMOX_CLEAN_SNAPSHOT') ?: '')
                : '');
        $trimmedSnap = trim($rawCleanSnap);
        $this->cleanSnapshot = $trimmedSnap !== '' ? $trimmedSnap : null;

        $this->httpExecutor = $httpExecutor;
    }

    /**
     * Guardrail: Validates whether a target VMID is strictly allowed for lifecycle operations.
     */
    public function isTargetAllowed(int $vmid): bool
    {
        if ($this->leaseVmid === null || $this->leaseVmid <= 0) {
            return false;
        }
        if ($vmid !== $this->leaseVmid) {
            return false;
        }
        if ($this->hostVmid !== null && $vmid === $this->hostVmid) {
            return false;
        }
        return true;
    }

    /**
     * Resolves the active Proxmox VE node dynamically via /api2/json/nodes.
     */
    public function resolveNodeName(): string
    {
        $defaultFallback = $this->node !== '' ? $this->node : 'vor-server';

        if (trim($this->tokenId) === '' || trim($this->tokenSecret) === '') {
            return $defaultFallback;
        }

        $nodesUrl = $this->host . '/api2/json/nodes';
        $nodesPayload = $this->executeGetRequest($nodesUrl);
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
     * Starts the configured Proxmox guest.
     *
     * @return array{
     *   ok: bool,
     *   action: string,
     *   vmid: int|null,
     *   node: string|null,
     *   guest_type: string,
     *   status: string,
     *   upid: string|null,
     *   error: string|null,
     *   skipped?: bool
     * }
     */
    public function startGuest(?int $vmid = null): array
    {
        $targetVmid = $vmid ?? $this->leaseVmid;

        // Guardrail: Unconfigured check
        if ($this->leaseVmid === null || $this->leaseVmid <= 0) {
            return [
                'ok' => false,
                'skipped' => true,
                'action' => 'start',
                'vmid' => $targetVmid,
                'node' => null,
                'guest_type' => $this->guestType,
                'status' => 'skipped',
                'upid' => null,
                'error' => 'No target lease VMID configured',
            ];
        }

        // Guardrail: Target validation (host VM protection & whitelist)
        if ($targetVmid === null || !$this->isTargetAllowed($targetVmid)) {
            return [
                'ok' => false,
                'action' => 'start',
                'vmid' => $targetVmid,
                'node' => null,
                'guest_type' => $this->guestType,
                'status' => 'rejected',
                'upid' => null,
                'error' => ($this->hostVmid !== null && $targetVmid === $this->hostVmid)
                    ? 'Target VMID matches protected host VM'
                    : 'Target VMID is not allowed by guardrail',
            ];
        }

        // Token check
        if (trim($this->tokenId) === '' || trim($this->tokenSecret) === '') {
            return [
                'ok' => false,
                'skipped' => true,
                'action' => 'start',
                'vmid' => $targetVmid,
                'node' => $this->node,
                'guest_type' => $this->guestType,
                'status' => 'skipped',
                'upid' => null,
                'error' => 'Proxmox API tokens not configured',
            ];
        }

        $node = $this->resolveNodeName();

        // Optional ephemeral state wipe: rollback to clean snapshot before booting if configured.
        // Pass start=1 so Proxmox's vzrollback/qmrollback worker boots the guest atomically on completion,
        // and wait for the rollback lock to clear before issuing /status/start as a guarantee.
        if ($this->cleanSnapshot !== null && $this->cleanSnapshot !== '') {
            $rollbackRes = $this->rollbackGuest($targetVmid, $this->cleanSnapshot, true, $node);
            if ($this->httpExecutor === null && ($rollbackRes['ok'] ?? false)) {
                $this->waitForTaskOrUnlock($node, $targetVmid, $rollbackRes['upid'] ?? null, 4000);
            }
        }

        $url = sprintf(
            '%s/api2/json/nodes/%s/%s/%s/status/start',
            $this->host,
            rawurlencode($node),
            rawurlencode($this->guestType),
            rawurlencode((string) $targetVmid)
        );

        $res = $this->executePostRequest($url, [], 'start');

        // If Proxmox is still finishing a background rollback/shutdown lock, retry start briefly
        if (!$res['ok'] && $this->httpExecutor === null) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $errLower = strtolower((string) ($res['error'] ?? ''));
                if (!str_contains($errLower, 'lock') && !str_contains($errLower, 'timeout') && !str_contains($errLower, '500')) {
                    break;
                }
                usleep(400000); // 400ms
                $current = $this->getGuestStatus($targetVmid, $node);
                if (is_array($current) && (($current['data']['status'] ?? '') === 'running') && empty($current['data']['lock'])) {
                    $res = [
                        'ok' => true,
                        'status' => 'already_running',
                        'upid' => null,
                        'error' => null,
                    ];
                    break;
                }
                $res = $this->executePostRequest($url, [], 'start');
                if ($res['ok']) {
                    break;
                }
            }
        }

        return [
            'ok' => $res['ok'],
            'action' => 'start',
            'vmid' => $targetVmid,
            'node' => $node,
            'guest_type' => $this->guestType,
            'status' => $res['status'],
            'upid' => $res['upid'],
            'error' => $res['error'],
        ];
    }

    /**
     * Stops or shuts down the configured Proxmox guest.
     *
     * @return array{
     *   ok: bool,
     *   action: string,
     *   vmid: int|null,
     *   node: string|null,
     *   guest_type: string,
     *   status: string,
     *   upid: string|null,
     *   error: string|null,
     *   skipped?: bool
     * }
     */
    public function stopGuest(?int $vmid = null, bool $force = false): array
    {
        $targetVmid = $vmid ?? $this->leaseVmid;
        $actionName = $force ? 'stop' : 'shutdown';

        // Guardrail: Unconfigured check
        if ($this->leaseVmid === null || $this->leaseVmid <= 0) {
            return [
                'ok' => false,
                'skipped' => true,
                'action' => $actionName,
                'vmid' => $targetVmid,
                'node' => null,
                'guest_type' => $this->guestType,
                'status' => 'skipped',
                'upid' => null,
                'error' => 'No target lease VMID configured',
            ];
        }

        // Guardrail: Target validation (host VM protection & whitelist)
        if ($targetVmid === null || !$this->isTargetAllowed($targetVmid)) {
            return [
                'ok' => false,
                'action' => $actionName,
                'vmid' => $targetVmid,
                'node' => null,
                'guest_type' => $this->guestType,
                'status' => 'rejected',
                'upid' => null,
                'error' => ($this->hostVmid !== null && $targetVmid === $this->hostVmid)
                    ? 'Target VMID matches protected host VM'
                    : 'Target VMID is not allowed by guardrail',
            ];
        }

        // Token check
        if (trim($this->tokenId) === '' || trim($this->tokenSecret) === '') {
            return [
                'ok' => false,
                'skipped' => true,
                'action' => $actionName,
                'vmid' => $targetVmid,
                'node' => $this->node,
                'guest_type' => $this->guestType,
                'status' => 'skipped',
                'upid' => null,
                'error' => 'Proxmox API tokens not configured',
            ];
        }

        $node = $this->resolveNodeName();
        $endpoint = $force ? 'stop' : 'shutdown';
        $url = sprintf(
            '%s/api2/json/nodes/%s/%s/%s/status/%s',
            $this->host,
            rawurlencode($node),
            rawurlencode($this->guestType),
            rawurlencode((string) $targetVmid),
            $endpoint
        );

        $res = $this->executePostRequest($url, [], $actionName);

        // Optional ephemeral state wipe: wait for shutdown task to release lock before rolling back
        if ($this->cleanSnapshot !== null && $this->cleanSnapshot !== '') {
            if ($this->httpExecutor === null && ($res['ok'] ?? false) && !empty($res['upid'])) {
                $this->waitForTaskOrUnlock($node, $targetVmid, $res['upid'], 3500);
            }
            $this->rollbackGuest($targetVmid, $this->cleanSnapshot, false, $node);
        }

        return [
            'ok' => $res['ok'],
            'action' => $actionName,
            'vmid' => $targetVmid,
            'node' => $node,
            'guest_type' => $this->guestType,
            'status' => $res['status'],
            'upid' => $res['upid'],
            'error' => $res['error'],
        ];
    }

    /**
     * Rolls back the configured Proxmox guest to a named LVM-Thin/ZFS snapshot (e.g. 'clean').
     */
    public function rollbackGuest(
        ?int $vmid = null,
        ?string $snapshot = null,
        bool $startAfterRollback = false,
        ?string $resolvedNode = null,
    ): array {
        $targetVmid = $vmid ?? $this->leaseVmid;
        $snapName = trim((string) ($snapshot ?? $this->cleanSnapshot ?? ''));

        if ($this->leaseVmid === null || $this->leaseVmid <= 0 || $snapName === '') {
            return [
                'ok' => false,
                'skipped' => true,
                'action' => 'rollback',
                'vmid' => $targetVmid,
                'node' => null,
                'guest_type' => $this->guestType,
                'status' => 'skipped',
                'upid' => null,
                'error' => 'No target lease VMID or snapshot configured',
            ];
        }

        if ($targetVmid === null || !$this->isTargetAllowed($targetVmid)) {
            return [
                'ok' => false,
                'action' => 'rollback',
                'vmid' => $targetVmid,
                'node' => null,
                'guest_type' => $this->guestType,
                'status' => 'rejected',
                'upid' => null,
                'error' => ($this->hostVmid !== null && $targetVmid === $this->hostVmid)
                    ? 'Target VMID matches protected host VM'
                    : 'Target VMID is not allowed by guardrail',
            ];
        }

        if (trim($this->tokenId) === '' || trim($this->tokenSecret) === '') {
            return [
                'ok' => false,
                'skipped' => true,
                'action' => 'rollback',
                'vmid' => $targetVmid,
                'node' => $this->node,
                'guest_type' => $this->guestType,
                'status' => 'skipped',
                'upid' => null,
                'error' => 'Proxmox API tokens not configured',
            ];
        }

        $node = ($resolvedNode !== null && $resolvedNode !== '') ? $resolvedNode : $this->resolveNodeName();
        $url = sprintf(
            '%s/api2/json/nodes/%s/%s/%s/snapshot/%s/rollback',
            $this->host,
            rawurlencode($node),
            rawurlencode($this->guestType),
            rawurlencode((string) $targetVmid),
            rawurlencode($snapName)
        );

        $params = $startAfterRollback ? ['start' => 1] : [];
        $res = $this->executePostRequest($url, $params, 'rollback');

        return [
            'ok' => $res['ok'],
            'action' => 'rollback',
            'vmid' => $targetVmid,
            'node' => $node,
            'guest_type' => $this->guestType,
            'status' => $res['status'],
            'upid' => $res['upid'],
            'error' => $res['error'],
        ];
    }

    /**
     * Retrieves current status for the target guest.
     */
    public function getGuestStatus(?int $vmid = null, ?string $resolvedNode = null): ?array
    {
        $targetVmid = $vmid ?? $this->leaseVmid;
        if ($targetVmid === null || !$this->isTargetAllowed($targetVmid)) {
            return null;
        }

        if (trim($this->tokenId) === '' || trim($this->tokenSecret) === '') {
            return null;
        }

        $node = ($resolvedNode !== null && $resolvedNode !== '') ? $resolvedNode : $this->resolveNodeName();
        $url = sprintf(
            '%s/api2/json/nodes/%s/%s/%s/status/current',
            $this->host,
            rawurlencode($node),
            rawurlencode($this->guestType),
            rawurlencode((string) $targetVmid)
        );

        return $this->executeGetRequest($url);
    }

    /**
     * Polls Proxmox task status and guest lock until the background task finishes or timeout elapses.
     */
    private function waitForTaskOrUnlock(string $node, int $vmid, ?string $upid, int $maxWaitMs = 3500): void
    {
        $elapsedMs = 0;
        $stepMs = 250;

        while ($elapsedMs < $maxWaitMs) {
            usleep($stepMs * 1000);
            $elapsedMs += $stepMs;

            if ($upid !== null && $upid !== '') {
                $taskUrl = sprintf(
                    '%s/api2/json/nodes/%s/tasks/%s/status',
                    $this->host,
                    rawurlencode($node),
                    rawurlencode($upid)
                );
                $taskStatus = $this->executeGetRequest($taskUrl);
                if (is_array($taskStatus) && isset($taskStatus['data']['status'])) {
                    if ($taskStatus['data']['status'] === 'stopped') {
                        break;
                    }
                    continue;
                }
            }

            $guestStatus = $this->getGuestStatus($vmid, $node);
            if (is_array($guestStatus) && isset($guestStatus['data']) && is_array($guestStatus['data'])) {
                if (empty($guestStatus['data']['lock'])) {
                    break;
                }
            }
        }
    }

    public function getLeaseVmid(): ?int
    {
        return $this->leaseVmid;
    }

    public function getHostVmid(): ?int
    {
        return $this->hostVmid;
    }

    public function getGuestType(): string
    {
        return $this->guestType;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getNode(): string
    {
        return $this->node;
    }

    private function executeGetRequest(string $url): ?array
    {
        $headers = [
            'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
            'Accept: application/json',
        ];

        if ($this->httpExecutor !== null) {
            try {
                $executor = $this->httpExecutor;
                $res = $executor($url, $headers, 'GET', []);
                if (is_array($res)) {
                    return $res;
                }
                if (is_string($res)) {
                    $decoded = json_decode($res, true);
                    return is_array($decoded) ? $decoded : null;
                }
            } catch (Throwable) {
                return null;
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

    private function executePostRequest(string $url, array $params = [], string $action = 'start'): array
    {
        $headers = [
            'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
            'Accept: application/json',
        ];

        if ($this->httpExecutor !== null) {
            try {
                $executor = $this->httpExecutor;
                $res = $executor($url, $headers, 'POST', $params);
                return $this->parsePostResponse($res, $action);
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'status' => 'failed',
                    'upid' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'status' => 'failed',
                'upid' => null,
                'error' => 'cURL extension is not installed',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'status' => 'failed',
                'upid' => null,
                'error' => $curlErr !== '' ? 'cURL error: ' . $curlErr : 'Network timeout or connection failure',
            ];
        }

        $parsed = $this->parsePostResponse($response, $action);

        if ($httpCode >= 400) {
            // Check if error response contains benign state
            if ($parsed['status'] === 'already_running' || $parsed['status'] === 'already_stopped') {
                return $parsed;
            }
            return [
                'ok' => false,
                'status' => 'failed',
                'upid' => null,
                'error' => $parsed['error'] ?? sprintf('HTTP %d Error', $httpCode),
            ];
        }

        return $parsed;
    }

    private function parsePostResponse(mixed $res, string $action): array
    {
        if ($res === null || $res === false) {
            return [
                'ok' => false,
                'status' => 'failed',
                'upid' => null,
                'error' => 'Empty or null response from Proxmox API',
            ];
        }

        $msg = '';
        $upid = null;

        if (is_string($res)) {
            $decoded = json_decode($res, true);
            if (is_array($decoded)) {
                if (isset($decoded['data']) && is_string($decoded['data'])) {
                    $upid = $decoded['data'];
                }
                $msg = (string) ($decoded['errors'] ?? $decoded['message'] ?? $decoded['error'] ?? '');
            } else {
                $msg = $res;
                if (str_starts_with($res, 'UPID:')) {
                    $upid = trim($res);
                }
            }
        } elseif (is_array($res)) {
            if (isset($res['data']) && is_string($res['data'])) {
                $upid = $res['data'];
            } elseif (isset($res['upid']) && is_string($res['upid'])) {
                $upid = $res['upid'];
            } elseif (isset($res['task_id']) && is_string($res['task_id'])) {
                $upid = $res['task_id'];
            }
            $msg = (string) ($res['errors'] ?? $res['message'] ?? $res['error'] ?? '');
        }

        // Check if UPID was returned
        if ($upid !== null && $upid !== '') {
            return [
                'ok' => true,
                'status' => 'dispatched',
                'upid' => $upid,
                'error' => null,
            ];
        }

        // Check for benign "already running" or "already stopped" / "not running"
        $lowerMsg = strtolower($msg);
        if ($action === 'start' && (str_contains($lowerMsg, 'already running') || str_contains($lowerMsg, 'is running'))) {
            return [
                'ok' => true,
                'status' => 'already_running',
                'upid' => null,
                'error' => null,
            ];
        }

        if (($action === 'stop' || $action === 'shutdown') && (str_contains($lowerMsg, 'not running') || str_contains($lowerMsg, 'already stopped') || str_contains($lowerMsg, 'not active'))) {
            return [
                'ok' => true,
                'status' => 'already_stopped',
                'upid' => null,
                'error' => null,
            ];
        }

        return [
            'ok' => false,
            'status' => 'failed',
            'upid' => null,
            'error' => $msg !== '' ? $msg : 'Request failed without valid UPID task ID',
        ];
    }
}
