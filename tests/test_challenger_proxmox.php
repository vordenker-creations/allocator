<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use VKU\Allocator\Domain\Enum\LeaseStatus;
use VKU\Allocator\Domain\Enum\RuntimePreset;
use VKU\Allocator\Infrastructure\Database;
use VKU\Allocator\Repository\AllocationRepository;
use VKU\Allocator\Repository\NodeRepository;
use VKU\Allocator\Repository\UserRepository;
use VKU\Allocator\Service\ProvisioningService;
use VKU\Allocator\Service\ProxmoxLifecycleService;
use VKU\Allocator\Service\ProxmoxTelemetryService;

$passed = 0;
$failed = 0;
$total = 0;

function check(bool $condition, string $testName, string $extra = ''): void
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo sprintf("  [PASS] %s\n", $testName);
    } else {
        $failed++;
        echo sprintf("  [FAIL] %s %s\n", $testName, $extra !== '' ? "--> $extra" : '');
    }
}

echo "=================================================================\n";
echo "CHALLENGER 1 — Empirical Stress Harness for ProxmoxTelemetryService\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "=================================================================\n\n";

// =============================================================================
// SUITE 1: Authentication & Token Edge Cases
// =============================================================================
echo "SUITE 1: Authentication & Token Edge Cases\n";

$emptyTokens = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: '',
    tokenSecret: ''
);
$res1 = $emptyTokens->getNodeTelemetry();
check($res1['is_live'] === false, 'Empty tokens trigger fallback');
check($res1['telemetry_source'] === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)', 'Empty tokens return correct fallback source notice');

$whitespaceTokens = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: "   \t\n  ",
    tokenSecret: "   "
);
$res2 = $whitespaceTokens->getNodeTelemetry();
check($res2['is_live'] === false, 'Whitespace-only tokens trigger fallback');

$halfToken1 = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'user@pam!token',
    tokenSecret: ''
);
check($halfToken1->getNodeTelemetry()['is_live'] === false, 'Missing tokenSecret triggers fallback');

$halfToken2 = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: '',
    tokenSecret: 'secret123'
);
check($halfToken2->getNodeTelemetry()['is_live'] === false, 'Missing tokenId triggers fallback');

// Verify authorization header format passed to executor
$capturedHeaders = [];
$headerCaptor = function (string $url, array $headers) use (&$capturedHeaders): ?array {
    $capturedHeaders = $headers;
    return ['data' => []];
};
$authService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'root@pam!allocator',
    tokenSecret: 'abc-123-uuid',
    httpExecutor: $headerCaptor
);
$authService->getNodeTelemetry();
$expectedAuth = 'Authorization: PVEAPIToken=root@pam!allocator=abc-123-uuid';
check(in_array($expectedAuth, $capturedHeaders, true), 'Authorization header formatted as PVEAPIToken=ID=SECRET');


// =============================================================================
// SUITE 2: HTTP Transport, Dead Hosts, Timeouts & Error Codes
// =============================================================================
echo "\nSUITE 2: HTTP Transport, Dead Hosts, Timeouts & Error Codes\n";

// 2.1 Refused connection (immediate fallback)
$refusedService = new ProxmoxTelemetryService(
    host: 'http://127.0.0.1:54321', // No service running here
    node: 'vor-server',
    tokenId: 'id',
    tokenSecret: 'secret'
);
$start = microtime(true);
$refusedRes = $refusedService->getNodeTelemetry();
$durationRefused = microtime(true) - $start;
check($refusedRes['is_live'] === false, 'Refused port returns simulated fallback');
check($durationRefused < 2.0, sprintf('Refused port fails fast (< 2.0s, took %.3fs)', $durationRefused));

// 2.2 Unresolvable host
$badHostService = new ProxmoxTelemetryService(
    host: 'http://nonexistent-proxmox-domain-xyz.local:8006',
    node: 'vor-server',
    tokenId: 'id',
    tokenSecret: 'secret'
);
$badHostRes = $badHostService->getNodeTelemetry();
check($badHostRes['is_live'] === false, 'Unresolvable host returns simulated fallback');

// 2.3 Dropping / Blackhole IP (Non-routable TEST-NET-1) to verify 1500ms timeout
$blackholeService = new ProxmoxTelemetryService(
    host: 'http://192.0.2.1:8006', // TEST-NET-1 (RFC 5737), blackholed
    node: 'vor-server',
    tokenId: 'id',
    tokenSecret: 'secret'
);
$start = microtime(true);
$blackholeRes = $blackholeService->getNodeTelemetry();
$durationBlackhole = microtime(true) - $start;
check($blackholeRes['is_live'] === false, 'Blackhole host returns simulated fallback');
// curl timeout is 1500ms for nodes endpoint, so should terminate in ~1.5s - 3.0s total
check($durationBlackhole >= 1.0 && $durationBlackhole <= 4.0, sprintf('cURL timeout enforces ~1.5s limit (took %.3fs)', $durationBlackhole));

// 2.4 HTTP error codes via executor
foreach ([401, 403, 404, 500, 502, 503] as $httpCode) {
    $errService = new ProxmoxTelemetryService(
        host: 'https://192.168.1.200:8006',
        node: 'vor-server',
        tokenId: 'id',
        tokenSecret: 'secret',
        httpExecutor: function (string $url, array $headers): ?array {
            return null; // Simulates non-200 HTTP code
        }
    );
    $res = $errService->getNodeTelemetry();
    check($res['is_live'] === false, sprintf('HTTP %d response falls back to simulated telemetry', $httpCode));
}

// 2.5 Partial failure: /nodes succeeds, /status succeeds, but /qemu fails
$partialFailExecutor = function (string $url, array $headers): ?array {
    if (str_ends_with($url, '/api2/json/nodes')) {
        return ['data' => [['node' => 'vor-server', 'status' => 'online']]];
    }
    if (str_ends_with($url, '/status')) {
        return ['data' => ['cpu' => 0.20, 'uptime' => 1000]];
    }
    if (str_ends_with($url, '/qemu')) {
        return null; // QEMU endpoint drops/fails
    }
    return null;
};
$partialService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'id',
    tokenSecret: 'secret',
    httpExecutor: $partialFailExecutor
);
$partialRes = $partialService->getNodeTelemetry();
check($partialRes['is_live'] === false, 'Failure on /qemu endpoint triggers clean fallback');


// =============================================================================
// SUITE 3: Proxmox JSON Parsing Stress & Boundary Conditions
// =============================================================================
echo "\nSUITE 3: Proxmox JSON Parsing Stress & Boundary Conditions\n";

// 3.1 Zero CPU & 100% CPU
$zeroCpuPayload = ['data' => ['cpu' => 0.0, 'uptime' => 100]];
$zeroCpuRes = ProxmoxTelemetryService::parseLiveResponses($zeroCpuPayload, ['data' => []]);
check($zeroCpuRes['cpu_usage_pct'] === 0.0, 'Zero CPU parses as 0.0%');

$fullCpuPayload = ['data' => ['cpu' => 1.0, 'uptime' => 100]];
$fullCpuRes = ProxmoxTelemetryService::parseLiveResponses($fullCpuPayload, ['data' => []]);
check($fullCpuRes['cpu_usage_pct'] === 100.0, '1.0 CPU parses as 100.0%');

$scaledCpuPayload = ['data' => ['cpu' => 0.4578, 'uptime' => 100]];
$scaledCpuRes = ProxmoxTelemetryService::parseLiveResponses($scaledCpuPayload, ['data' => []]);
check($scaledCpuRes['cpu_usage_pct'] === 45.8, '0.4578 CPU scales and rounds to 45.8%');

$rawPctPayload = ['data' => ['cpu' => 67.4, 'uptime' => 100]];
$rawPctRes = ProxmoxTelemetryService::parseLiveResponses($rawPctPayload, ['data' => []]);
check($rawPctRes['cpu_usage_pct'] === 67.4, 'Pre-scaled 67.4% CPU parses correctly');

// 3.2 RAM metrics: byte conversion, memory block vs maxmem/mem
$byteMemPayload = [
    'data' => [
        'memory' => [
            'total' => 34359738368, // 32 GB
            'used' => 17179869184,  // 16 GB
        ],
    ],
];
$byteMemRes = ProxmoxTelemetryService::parseLiveResponses($byteMemPayload, []);
check($byteMemRes['ram_total_gb'] === 32.0, 'RAM total converted from bytes to 32.0 GB');
check($byteMemRes['ram_used_gb'] === 16.0, 'RAM used converted from bytes to 16.0 GB');
check($byteMemRes['ram_usage_pct'] === 50.0, 'RAM usage percentage calculated as 50.0%');

$fallbackMemPayload = [
    'data' => [
        'maxmem' => 17179869184, // 16 GB
        'mem' => 4294967296,     // 4 GB
    ],
];
$fallbackMemRes = ProxmoxTelemetryService::parseLiveResponses($fallbackMemPayload, []);
check($fallbackMemRes['ram_total_gb'] === 16.0, 'maxmem used when memory block missing (16.0 GB)');
check($fallbackMemRes['ram_used_gb'] === 4.0, 'mem used when memory block missing (4.0 GB)');
check($fallbackMemRes['ram_usage_pct'] === 25.0, 'RAM usage calculated correctly from maxmem/mem (25.0%)');

// 3.3 Large RAM (1 TB server)
$terabytePayload = [
    'data' => [
        'memory' => [
            'total' => 1099511627776, // 1024 GB (1 TB)
            'used' => 549755813888,   // 512 GB
        ],
    ],
];
$tbRes = ProxmoxTelemetryService::parseLiveResponses($terabytePayload, []);
check($tbRes['ram_total_gb'] === 1024.0, '1 TB server RAM parsed as 1024.0 GB');
check($tbRes['ram_used_gb'] === 512.0, '512 GB used RAM parsed as 512.0 GB');

// 3.4 Zero RAM / Div by Zero safety
$zeroMemPayload = [
    'data' => [
        'memory' => [
            'total' => 0,
            'used' => 0,
        ],
    ],
];
$zeroMemRes = ProxmoxTelemetryService::parseLiveResponses($zeroMemPayload, []);
check($zeroMemRes['ram_usage_pct'] === 0.0, 'Zero RAM total handled without division by zero');

// 3.5 Uptime formatting boundaries
$uptimeCases = [
    0 => '0d 0h 0m',
    59 => '0d 0h 0m',
    60 => '0d 0h 1m',
    3600 => '0d 1h 0m',
    3665 => '0d 1h 1m',
    86400 => '1d 0h 0m',
    90060 => '1d 1h 1m',
    31536000 => '365d 0h 0m',
];
foreach ($uptimeCases as $seconds => $expected) {
    $uRes = ProxmoxTelemetryService::parseLiveResponses(['data' => ['uptime' => $seconds]], []);
    check($uRes['uptime_formatted'] === $expected, sprintf('Uptime %d sec -> "%s"', $seconds, $expected));
}

// 3.6 Load Average formats
$loadArray = ['data' => ['loadavg' => ['1.25', '0.80', '0.65']]];
$lRes1 = ProxmoxTelemetryService::parseLiveResponses($loadArray, []);
check($lRes1['load_average'] === '1.25, 0.80, 0.65', 'Array loadavg formatted with comma separation');

$loadString = ['data' => ['loadavg' => '2.10, 1.95, 1.50']];
$lRes2 = ProxmoxTelemetryService::parseLiveResponses($loadString, []);
check($lRes2['load_average'] === '2.10, 1.95, 1.50', 'String loadavg preserved');

$loadMissing = ['data' => []];
$lRes3 = ProxmoxTelemetryService::parseLiveResponses($loadMissing, []);
check($lRes3['load_average'] === '0.00, 0.00, 0.00', 'Missing loadavg defaults to 0.00, 0.00, 0.00');

// 3.7 QEMU VM count variations: 0 VMs, 100 VMs, malformed items
$qemuZero = ProxmoxTelemetryService::parseLiveResponses(['data' => []], ['data' => []]);
check($qemuZero['running_vms'] === 0 && $qemuZero['total_vms'] === 0, '0 VMs handles cleanly');

$vms100 = [];
for ($i = 1; $i <= 100; $i++) {
    $vms100[] = [
        'vmid' => 100 + $i,
        'name' => "vm-$i",
        'status' => ($i <= 42) ? 'running' : 'stopped',
    ];
}
$qemu100 = ProxmoxTelemetryService::parseLiveResponses(['data' => []], ['data' => $vms100]);
check($qemu100['running_vms'] === 42 && $qemu100['total_vms'] === 100, '100 VMs correctly filters 42 running / 100 total');

$qemuMalformed = [
    'data' => [
        'not_an_array',
        ['vmid' => 101], // Missing status
        ['vmid' => 102, 'status' => 'running'],
        null,
        ['vmid' => 103, 'status' => 'paused'],
    ],
];
$qemuMalRes = ProxmoxTelemetryService::parseLiveResponses(['data' => []], $qemuMalformed);
check($qemuMalRes['running_vms'] === 1 && $qemuMalRes['total_vms'] === 5, 'Malformed VM array does not crash and filters running status');

// 3.8 Missing cpuinfo fields
$minimalStatus = ['data' => []];
$minRes = ProxmoxTelemetryService::parseLiveResponses($minimalStatus, []);
check($minRes['cpu_cores'] === 4 && $minRes['cpu_threads'] === 4, 'Missing cpuinfo defaults cores & threads to 4');
check(str_contains($minRes['cpu_model'], 'Intel Core i5-3470'), 'Missing cpuinfo defaults to vor-server CPU model');


// =============================================================================
// SUITE 4: Node Auto-Detection (`resolveNodeName`) Edge Cases
// =============================================================================
echo "\nSUITE 4: Node Auto-Detection (resolveNodeName) Edge Cases\n";

// 4.1 Case-insensitive node matching
$caseExec = function (string $url, array $headers): ?array {
    return [
        'data' => [
            ['node' => 'VOR-SERVER', 'status' => 'online'],
            ['node' => 'pve-secondary', 'status' => 'online'],
        ],
    ];
};
$caseService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'id',
    tokenSecret: 'secret',
    httpExecutor: $caseExec
);
check($caseService->resolveNodeName() === 'VOR-SERVER', 'resolveNodeName matches node name case-insensitively');

// 4.2 Configured node not in list -> picks online node
$onlineExec = function (string $url, array $headers): ?array {
    return [
        'data' => [
            ['node' => 'dead-node', 'status' => 'offline'],
            ['node' => 'pve-active', 'status' => 'online'],
        ],
    ];
};
$onlineService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server', // Not in list
    tokenId: 'id',
    tokenSecret: 'secret',
    httpExecutor: $onlineExec
);
check($onlineService->resolveNodeName() === 'pve-active', 'resolveNodeName selects active online node if configured node missing');

// 4.3 All nodes offline -> falls back to first node in list
$offlineExec = function (string $url, array $headers): ?array {
    return [
        'data' => [
            ['node' => 'node-first-offline', 'status' => 'offline'],
            ['node' => 'node-second-offline', 'status' => 'offline'],
        ],
    ];
};
$offlineService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'id',
    tokenSecret: 'secret',
    httpExecutor: $offlineExec
);
check($offlineService->resolveNodeName() === 'node-first-offline', 'resolveNodeName selects first node when all nodes offline');

// 4.4 Empty array -> fallback to configured node
$emptyExec = function (string $url, array $headers): ?array {
    return ['data' => []];
};
$emptyListService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-custom',
    tokenId: 'id',
    tokenSecret: 'secret',
    httpExecutor: $emptyExec
);
check($emptyListService->resolveNodeName() === 'vor-custom', 'resolveNodeName falls back to configured node when node list empty');

// 4.5 Malformed node items
$malformedNodesExec = function (string $url, array $headers): ?array {
    return [
        'data' => [
            'invalid_item',
            ['foo' => 'bar'],
            ['node' => ''],
            ['node' => 'valid-node', 'status' => 'online'],
        ],
    ];
};
$malNodeService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'id',
    tokenSecret: 'secret',
    httpExecutor: $malformedNodesExec
);
check($malNodeService->resolveNodeName() === 'valid-node', 'resolveNodeName skips malformed items and finds valid online node');

// 4.6 URL encoding of special characters in node name
$requestedUrls = [];
$urlTrackingExec = function (string $url, array $headers) use (&$requestedUrls): ?array {
    $requestedUrls[] = $url;
    if (str_ends_with($url, '/api2/json/nodes')) {
        return ['data' => [['node' => 'pve node#1', 'status' => 'online']]];
    }
    return ['data' => []];
};
$encodedService = new ProxmoxTelemetryService(
    host: 'https://192.168.1.200:8006',
    node: 'pve node#1',
    tokenId: 'id',
    tokenSecret: 'secret',
    httpExecutor: $urlTrackingExec
);
$encodedService->getNodeTelemetry();
$hasRawUrlEncoded = false;
foreach ($requestedUrls as $u) {
    if (str_contains($u, 'pve%20node%231')) {
        $hasRawUrlEncoded = true;
    }
}
check($hasRawUrlEncoded, 'Node name with spaces/special characters is rawurlencoded in REST endpoints');


// =============================================================================
// SUITE 5: Campus Node Simulation Boundary Testing
// =============================================================================
echo "\nSUITE 5: Campus Node Simulation Boundary Testing\n";

// 5.1 Test all node IDs 1 through 100 for Active and Idle bounds
$allActiveCpuInBounds = true;
$allActiveRamInBounds = true;
$allIdleCpuInBounds = true;
$allIdleRamInBounds = true;

for ($id = 1; $id <= 100; $id++) {
    $node = ['id' => $id, 'status' => 'ONLINE', 'ram_gb' => 128, 'vram_gb' => 24];
    
    $act = ProxmoxTelemetryService::simulateCampusNodeTelemetry($node, true);
    if ($act['cpu_usage_pct'] < 75.0 || $act['cpu_usage_pct'] > 92.0) {
        $allActiveCpuInBounds = false;
    }
    if ($act['ram_usage_pct'] < 65.0 || $act['ram_usage_pct'] > 85.0) {
        $allActiveRamInBounds = false;
    }
    
    $idl = ProxmoxTelemetryService::simulateCampusNodeTelemetry($node, false);
    if ($idl['cpu_usage_pct'] < 3.0 || $idl['cpu_usage_pct'] > 12.0) {
        $allIdleCpuInBounds = false;
    }
    if ($idl['ram_usage_pct'] < 15.0 || $idl['ram_usage_pct'] > 28.0) {
        $allIdleRamInBounds = false;
    }
}

check($allActiveCpuInBounds, 'simulateCampusNodeTelemetry active lease CPU is strictly in [75.0%, 92.0%] across 100 node IDs');
check($allActiveRamInBounds, 'simulateCampusNodeTelemetry active lease RAM is strictly in [65.0%, 85.0%] across 100 node IDs');
check($allIdleCpuInBounds, 'simulateCampusNodeTelemetry idle lease CPU is strictly in [3.0%, 12.0%] across 100 node IDs');
check($allIdleRamInBounds, 'simulateCampusNodeTelemetry idle lease RAM is strictly in [15.0%, 28.0%] across 100 node IDs');

// 5.2 Non-GPU nodes (vram_gb = 0)
$cpuOnlyNode = ['id' => 6, 'status' => 'ONLINE', 'ram_gb' => 256, 'vram_gb' => 0];
$cpuOnlyAct = ProxmoxTelemetryService::simulateCampusNodeTelemetry($cpuOnlyNode, true);
check($cpuOnlyAct['vram_allocated_gb'] === 0 && $cpuOnlyAct['vram_usage_pct'] === 0.0, 'Non-GPU node has 0 GB VRAM and 0% VRAM usage under active lease');

$cpuOnlyIdl = ProxmoxTelemetryService::simulateCampusNodeTelemetry($cpuOnlyNode, false);
check($cpuOnlyIdl['vram_allocated_gb'] === 0 && $cpuOnlyIdl['vram_usage_pct'] === 0.0, 'Non-GPU node has 0 GB VRAM and 0% VRAM usage under idle state');

// 5.3 Non-ONLINE statuses: MAINTENANCE, OFFLINE, DRAIN
foreach (['MAINTENANCE', 'OFFLINE', 'DRAIN', 'UNKNOWN'] as $nonOnline) {
    $offNode = ['id' => 7, 'status' => $nonOnline, 'ram_gb' => 128, 'vram_gb' => 24];
    $offSim = ProxmoxTelemetryService::simulateCampusNodeTelemetry($offNode, false);
    $allZero = $offSim['cpu_usage_pct'] === 0.0
        && $offSim['ram_used_gb'] === 0.0
        && $offSim['ram_usage_pct'] === 0.0
        && $offSim['vram_allocated_gb'] === 0
        && $offSim['vram_usage_pct'] === 0.0;
    check($allZero, sprintf('Status "%s" produces 0%% CPU, 0 RAM, and 0%% VRAM', $nonOnline));
}

// 5.4 Missing fields in node array
$emptyNode = [];
$emptySim = ProxmoxTelemetryService::simulateCampusNodeTelemetry($emptyNode, false);
check($emptySim['ram_total_gb'] === 64.0, 'Missing ram_gb defaults to 64 GB');
check($emptySim['vram_total_gb'] === 0, 'Missing vram_gb defaults to 0 GB');


// =============================================================================
// SUITE 6: Fallback State Schema Completeness
// =============================================================================
echo "\nSUITE 6: Fallback State Schema Completeness\n";

$fallback = ProxmoxTelemetryService::getSimulatedFallbackState('vor-custom-node');
$requiredKeys = [
    'is_live',
    'telemetry_source',
    'status_message',
    'node_name',
    'cpu_model',
    'cpu_cores',
    'cpu_threads',
    'cpu_usage_pct',
    'ram_used_gb',
    'ram_total_gb',
    'ram_usage_pct',
    'vram_gb',
    'vram_allocated_gb',
    'uptime_seconds',
    'uptime_formatted',
    'load_average',
    'running_vms',
    'total_vms',
];
$allKeysPresent = true;
foreach ($requiredKeys as $k) {
    if (!array_key_exists($k, $fallback)) {
        $allKeysPresent = false;
        echo "Missing key in fallback state: $k\n";
    }
}
check($allKeysPresent, 'Fallback dictionary contains all 18 required schema keys');
check($fallback['node_name'] === 'vor-custom-node', 'Fallback uses requested node name');
check($fallback['is_live'] === false, 'Fallback has is_live = false');
check($fallback['telemetry_source'] === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)', 'Fallback telemetry_source matches spec verbatim');


// =============================================================================
// SUITE 7: Guest Lifecycle Start and Shutdown/Stop API Dispatch
// =============================================================================
echo "\nSUITE 7: Guest Lifecycle Start and Shutdown/Stop API Dispatch\n";

$capturedCalls = [];
$mockExecutor = function (string $url, array $headers, string $method = 'POST', array $params = []) use (&$capturedCalls): array {
    $capturedCalls[] = [
        'url' => $url,
        'headers' => $headers,
        'method' => $method,
        'params' => $params,
    ];
    if (str_contains($url, '/api2/json/nodes') && !str_contains($url, '/status/')) {
        return ['data' => [['node' => 'vor-server', 'status' => 'online']]];
    }
    return ['data' => 'UPID:vor-server:00001234:00112233:659F2537:qmstart:105:root@pam!allocator:'];
};

$lifecycle = new ProxmoxLifecycleService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'root@pam!allocator',
    tokenSecret: 'secret123',
    leaseVmid: 105,
    guestType: 'qemu',
    hostVmid: 100,
    httpExecutor: $mockExecutor
);

// 7.1 Start Guest (QEMU)
$capturedCalls = [];
$startRes = $lifecycle->startGuest();
check($startRes['ok'] === true, 'startGuest returns ok=true');
check($startRes['action'] === 'start', 'startGuest action is start');
check($startRes['vmid'] === 105, 'startGuest targets configured lease VMID 105');
check($startRes['status'] === 'dispatched', 'startGuest status is dispatched');
check($startRes['upid'] === 'UPID:vor-server:00001234:00112233:659F2537:qmstart:105:root@pam!allocator:', 'startGuest extracts task UPID');
check(
    !empty($capturedCalls) && end($capturedCalls)['url'] === 'https://192.168.1.200:8006/api2/json/nodes/vor-server/qemu/105/status/start',
    'startGuest dispatches HTTP POST to /api2/json/nodes/{node}/qemu/{vmid}/status/start'
);
check(
    in_array('Authorization: PVEAPIToken=root@pam!allocator=secret123', end($capturedCalls)['headers'], true),
    'startGuest includes correct PVEAPIToken Authorization header'
);

// 7.2 Stop Guest (Graceful Shutdown)
$capturedCalls = [];
$shutdownRes = $lifecycle->stopGuest(force: false);
check($shutdownRes['ok'] === true, 'stopGuest (shutdown) returns ok=true');
check($shutdownRes['action'] === 'shutdown', 'stopGuest action is shutdown');
check(
    !empty($capturedCalls) && end($capturedCalls)['url'] === 'https://192.168.1.200:8006/api2/json/nodes/vor-server/qemu/105/status/shutdown',
    'stopGuest dispatches HTTP POST to /api2/json/nodes/{node}/qemu/{vmid}/status/shutdown'
);

// 7.3 Stop Guest (Forced Stop)
$capturedCalls = [];
$stopRes = $lifecycle->stopGuest(force: true);
check($stopRes['ok'] === true, 'stopGuest (force=true) returns ok=true');
check($stopRes['action'] === 'stop', 'stopGuest (force=true) action is stop');
check(
    !empty($capturedCalls) && end($capturedCalls)['url'] === 'https://192.168.1.200:8006/api2/json/nodes/vor-server/qemu/105/status/stop',
    'stopGuest (force=true) dispatches HTTP POST to /status/stop'
);

// 7.4 LXC Guest Type Support
$lxcLifecycle = new ProxmoxLifecycleService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-server',
    tokenId: 'root@pam!allocator',
    tokenSecret: 'secret123',
    leaseVmid: 200,
    guestType: 'lxc',
    hostVmid: 100,
    httpExecutor: $mockExecutor
);
$capturedCalls = [];
$lxcStartRes = $lxcLifecycle->startGuest();
check($lxcStartRes['ok'] === true && $lxcStartRes['guest_type'] === 'lxc', 'LXC start targets guest_type lxc');
check(
    !empty($capturedCalls) && end($capturedCalls)['url'] === 'https://192.168.1.200:8006/api2/json/nodes/vor-server/lxc/200/status/start',
    'LXC start dispatches to /api2/json/nodes/{node}/lxc/{vmid}/status/start'
);

// 7.5 Guest Type Normalization & Whitespace Trimming
$trimQemu = new ProxmoxLifecycleService(leaseVmid: 105, guestType: '  QEMU  ');
check($trimQemu->getGuestType() === 'qemu', 'guestType "  QEMU  " is trimmed and normalized to "qemu"');
$trimLxc = new ProxmoxLifecycleService(leaseVmid: 105, guestType: '  LXC  ');
check($trimLxc->getGuestType() === 'lxc', 'guestType "  LXC  " is trimmed and normalized to "lxc"');
$invalidType = new ProxmoxLifecycleService(leaseVmid: 105, guestType: 'docker');
check($invalidType->getGuestType() === 'qemu', 'Invalid guestType defaults safely to "qemu"');

// 7.6 Benign "Already Running" State
$alreadyRunningExecutor = function (string $url, array $headers): array {
    return ['errors' => 'VM 105 already running'];
};
$alreadyRunningService = new ProxmoxLifecycleService(
    tokenId: 'tok',
    tokenSecret: 'sec',
    leaseVmid: 105,
    httpExecutor: $alreadyRunningExecutor
);
$arRes = $alreadyRunningService->startGuest();
check($arRes['ok'] === true, 'Already running response is treated as benign ok=true');
check($arRes['status'] === 'already_running', 'Already running response returns status=already_running');

// 7.7 Benign "Already Stopped" / "Not Running" State
$notRunningExecutor = function (string $url, array $headers): array {
    return ['errors' => 'VM 105 not running'];
};
$alreadyStoppedService = new ProxmoxLifecycleService(
    tokenId: 'tok',
    tokenSecret: 'sec',
    leaseVmid: 105,
    httpExecutor: $notRunningExecutor
);
$asRes = $alreadyStoppedService->stopGuest();
check($asRes['ok'] === true, 'Not running response is treated as benign ok=true');
check($asRes['status'] === 'already_stopped', 'Not running response returns status=already_stopped');

// 7.8 getGuestStatus current query
$statusExecutor = function (string $url, array $headers): array {
    return ['data' => ['status' => 'running', 'vmid' => 105, 'cpu' => 0.05]];
};
$statusService = new ProxmoxLifecycleService(
    tokenId: 'tok',
    tokenSecret: 'sec',
    leaseVmid: 105,
    httpExecutor: $statusExecutor
);
$guestStatus = $statusService->getGuestStatus();
check(is_array($guestStatus) && ($guestStatus['data']['status'] ?? '') === 'running', 'getGuestStatus returns current status array');


// =============================================================================
// SUITE 8: Target Guest Isolation and Hard Host Protection Guardrails
// =============================================================================
echo "\nSUITE 8: Target Guest Isolation and Hard Host Protection Guardrails\n";

$guardrailService = new ProxmoxLifecycleService(
    leaseVmid: 105,
    hostVmid: 100,
    tokenId: 'tok',
    tokenSecret: 'sec'
);

// 8.1 isTargetAllowed Whitelist and Host Blacklist
check($guardrailService->isTargetAllowed(105) === true, 'isTargetAllowed returns true for configured lease VMID 105');
check($guardrailService->isTargetAllowed(100) === false, 'isTargetAllowed returns false for protected host VMID 100');
check($guardrailService->isTargetAllowed(101) === false, 'isTargetAllowed returns false for unauthorized VMID 101');
check($guardrailService->isTargetAllowed(0) === false, 'isTargetAllowed returns false for VMID 0');
check($guardrailService->isTargetAllowed(-1) === false, 'isTargetAllowed returns false for negative VMID');

// 8.2 Host VM Protection Guardrail Collision (leaseVmid === hostVmid)
$dispatched = false;
$neverCallExecutor = function () use (&$dispatched): array {
    $dispatched = true;
    return ['data' => 'UPID:...'];
};
$hostCollisionService = new ProxmoxLifecycleService(
    leaseVmid: 100,
    hostVmid: 100,
    tokenId: 'tok',
    tokenSecret: 'sec',
    httpExecutor: $neverCallExecutor
);
check($hostCollisionService->isTargetAllowed(100) === false, 'Host VM collision invalidates target permission');
$collisionStart = $hostCollisionService->startGuest();
check($collisionStart['ok'] === false, 'Host VM collision rejects startGuest');
check($collisionStart['status'] === 'rejected', 'Host VM collision sets status=rejected');
check($dispatched === false, 'Host VM collision dispatches 0 HTTP requests');

$collisionStop = $hostCollisionService->stopGuest();
check($collisionStop['ok'] === false && $collisionStop['status'] === 'rejected', 'Host VM collision rejects stopGuest');
check($dispatched === false, 'Host VM collision dispatches 0 HTTP requests on stop');

// 8.3 Targeting Disallowed VMID explicitly
$guardrailHttpCalls = 0;
$countingExecutor = function () use (&$guardrailHttpCalls): array {
    $guardrailHttpCalls++;
    return ['data' => 'UPID:...'];
};
$strictService = new ProxmoxLifecycleService(
    leaseVmid: 105,
    hostVmid: 100,
    tokenId: 'tok',
    tokenSecret: 'sec',
    httpExecutor: $countingExecutor
);
$rejectedStart = $strictService->startGuest(100);
check($rejectedStart['ok'] === false && $rejectedStart['status'] === 'rejected', 'startGuest rejects explicit host VMID parameter');
check($guardrailHttpCalls === 0, 'Explicit host VMID parameter dispatches 0 HTTP requests');

$foreignStop = $strictService->stopGuest(999);
check($foreignStop['ok'] === false && $foreignStop['status'] === 'rejected', 'stopGuest rejects non-configured target VMID 999');
check($guardrailHttpCalls === 0, 'Disallowed target VMID dispatches 0 HTTP requests');

// 8.4 Unconfigured Lease VMID Safe Abort
$unconfiguredService = new ProxmoxLifecycleService(
    leaseVmid: null,
    tokenId: 'tok',
    tokenSecret: 'sec',
    httpExecutor: $countingExecutor
);
$unconfiguredStart = $unconfiguredService->startGuest();
check($unconfiguredStart['ok'] === false, 'Unconfigured lease VMID returns ok=false');
check($unconfiguredStart['skipped'] === true, 'Unconfigured lease VMID returns skipped=true');
check($unconfiguredStart['status'] === 'skipped', 'Unconfigured lease VMID sets status=skipped');
check($guardrailHttpCalls === 0, 'Unconfigured lease VMID dispatches 0 HTTP requests');

$zeroVmidService = new ProxmoxLifecycleService(leaseVmid: 0, tokenId: 'tok', tokenSecret: 'sec', httpExecutor: $countingExecutor);
$zeroStart = $zeroVmidService->startGuest();
check($zeroStart['skipped'] === true && $zeroStart['status'] === 'skipped', 'Zero VMID is treated as unconfigured and skipped');

$negativeVmidService = new ProxmoxLifecycleService(leaseVmid: -5, tokenId: 'tok', tokenSecret: 'sec', httpExecutor: $countingExecutor);
$negativeStart = $negativeVmidService->startGuest();
check($negativeStart['skipped'] === true && $negativeStart['status'] === 'skipped', 'Negative VMID is treated as unconfigured and skipped');

// 8.5 Missing Tokens Safe Abort
$missingTokenService = new ProxmoxLifecycleService(
    leaseVmid: 105,
    tokenId: '',
    tokenSecret: '',
    httpExecutor: $countingExecutor
);
$missingTokenStart = $missingTokenService->startGuest();
check($missingTokenStart['ok'] === false && $missingTokenStart['skipped'] === true, 'Missing tokens safely skip without HTTP calls');
check($guardrailHttpCalls === 0, 'Missing tokens dispatch 0 HTTP requests');


// =============================================================================
// SUITE 9: HTTP Error Handling, Network Failures and Timeouts
// =============================================================================
echo "\nSUITE 9: HTTP Error Handling, Network Failures and Timeouts\n";

// 9.1 HTTP 400 Bad Request (Non-Benign)
$badRequestExecutor = function (string $url, array $headers): array {
    return ['errors' => 'parameter vmid does not exist'];
};
$badReqService = new ProxmoxLifecycleService(tokenId: 't', tokenSecret: 's', leaseVmid: 105, httpExecutor: $badRequestExecutor);
$brRes = $badReqService->startGuest();
check($brRes['ok'] === false, 'HTTP 400 returns ok=false');
check($brRes['status'] === 'failed', 'HTTP 400 returns status=failed');
check(str_contains($brRes['error'] ?? '', 'parameter vmid does not exist'), 'HTTP 400 preserves error message');

// 9.2 HTTP 401 Unauthorized
$unauthorizedExecutor = function (string $url, array $headers): array {
    return ['error' => 'HTTP 401 Unauthorized'];
};
$authFailService = new ProxmoxLifecycleService(tokenId: 't', tokenSecret: 's', leaseVmid: 105, httpExecutor: $unauthorizedExecutor);
$afRes = $authFailService->startGuest();
check($afRes['ok'] === false && $afRes['status'] === 'failed', 'HTTP 401 returns ok=false and status=failed');

// 9.3 HTTP 403 Forbidden
$forbiddenExecutor = function (string $url, array $headers): array {
    return ['error' => 'HTTP 403 Forbidden: Permission check failed'];
};
$permFailService = new ProxmoxLifecycleService(tokenId: 't', tokenSecret: 's', leaseVmid: 105, httpExecutor: $forbiddenExecutor);
$pfRes = $permFailService->startGuest();
check($pfRes['ok'] === false && $pfRes['status'] === 'failed', 'HTTP 403 returns ok=false and status=failed');

// 9.4 HTTP 404 Not Found
$notFoundExecutor = function (string $url, array $headers): array {
    return ['error' => 'HTTP 404 Node or guest not found'];
};
$notFoundService = new ProxmoxLifecycleService(tokenId: 't', tokenSecret: 's', leaseVmid: 105, httpExecutor: $notFoundExecutor);
$nfRes = $notFoundService->startGuest();
check($nfRes['ok'] === false && $nfRes['status'] === 'failed', 'HTTP 404 returns ok=false and status=failed');

// 9.5 HTTP 500 Server Error
$serverErrorExecutor = function (string $url, array $headers): array {
    return ['error' => 'HTTP 500 Internal Proxmox cluster error'];
};
$serverErrService = new ProxmoxLifecycleService(tokenId: 't', tokenSecret: 's', leaseVmid: 105, httpExecutor: $serverErrorExecutor);
$seRes = $serverErrService->stopGuest();
check($seRes['ok'] === false && $seRes['status'] === 'failed', 'HTTP 500 returns ok=false and status=failed');

// 9.6 Malformed HTML Error Page
$htmlExecutor = function (string $url, array $headers): string {
    return '<html><head><title>502 Bad Gateway</title></head><body>502 Bad Gateway</body></html>';
};
$htmlService = new ProxmoxLifecycleService(tokenId: 't', tokenSecret: 's', leaseVmid: 105, httpExecutor: $htmlExecutor);
$htmlRes = $htmlService->startGuest();
check($htmlRes['ok'] === false && $htmlRes['status'] === 'failed', 'Malformed HTML returns ok=false without fatal JSON decode crash');

// 9.7 Network Exception / Timeout Handling
$exceptionExecutor = function (string $url, array $headers): array {
    throw new RuntimeException('Connection timed out after 1500ms');
};
$timeoutService = new ProxmoxLifecycleService(tokenId: 't', tokenSecret: 's', leaseVmid: 105, httpExecutor: $exceptionExecutor);
$timeoutRes = $timeoutService->startGuest();
check($timeoutRes['ok'] === false, 'Transport exception returns ok=false without crashing');
check($timeoutRes['status'] === 'failed', 'Transport exception sets status=failed');
check(str_contains($timeoutRes['error'] ?? '', 'timed out after 1500ms'), 'Transport exception captures error message');


// =============================================================================
// SUITE 10: Dynamic Node Resolution for Lifecycle Operations
// =============================================================================
echo "\nSUITE 10: Dynamic Node Resolution for Lifecycle Operations\n";

// 10.1 Active Online Node Selection
$dynamicCalls = [];
$dynamicExecutor = function (string $url, array $headers) use (&$dynamicCalls): array {
    $dynamicCalls[] = $url;
    if (str_contains($url, '/api2/json/nodes') && !str_contains($url, '/status/')) {
        return [
            'data' => [
                ['node' => 'node-standby', 'status' => 'offline'],
                ['node' => 'pve-online-01', 'status' => 'online'],
            ],
        ];
    }
    return ['data' => 'UPID:pve-online-01:00001234:00112233:659F2537:qmstart:105:root@pam!allocator:'];
};

$dynamicService = new ProxmoxLifecycleService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-configured-missing', // configured node is not in cluster
    tokenId: 'tok',
    tokenSecret: 'sec',
    leaseVmid: 105,
    httpExecutor: $dynamicExecutor
);
$dynStart = $dynamicService->startGuest();
check($dynStart['ok'] === true, 'Dynamic node resolution succeeds');
check($dynStart['node'] === 'pve-online-01', 'startGuest dynamically resolves active online node pve-online-01');
check(
    str_contains(end($dynamicCalls), '/nodes/pve-online-01/qemu/105/status/start'),
    'startGuest targets dynamically resolved node in URL path'
);

// 10.2 Fallback to Configured Node When Nodes API Offline
$fallbackExecutor = function (string $url, array $headers): ?array {
    if (str_contains($url, '/api2/json/nodes') && !str_contains($url, '/status/')) {
        return null; // /nodes call fails
    }
    return ['data' => 'UPID:vor-server:...'];
};
$fallbackNodeService = new ProxmoxLifecycleService(
    host: 'https://192.168.1.200:8006',
    node: 'vor-fallback-node',
    tokenId: 'tok',
    tokenSecret: 'sec',
    leaseVmid: 105,
    httpExecutor: $fallbackExecutor
);
check($fallbackNodeService->resolveNodeName() === 'vor-fallback-node', 'resolveNodeName falls back to configured node when /nodes fails');

// 10.3 URL Encoding of Special Characters in Node Name
$specialCalls = [];
$specialNodeExecutor = function (string $url, array $headers) use (&$specialCalls): array {
    $specialCalls[] = $url;
    if (str_contains($url, '/api2/json/nodes') && !str_contains($url, '/status/')) {
        return [
            'data' => [
                ['node' => 'pve cluster#1', 'status' => 'online'],
            ],
        ];
    }
    return ['data' => 'UPID:...'];
};
$specialNodeService = new ProxmoxLifecycleService(
    host: 'https://192.168.1.200:8006',
    node: 'pve cluster#1',
    tokenId: 'tok',
    tokenSecret: 'sec',
    leaseVmid: 105,
    httpExecutor: $specialNodeExecutor
);
$specialStart = $specialNodeService->startGuest();
check(
    str_contains(end($specialCalls), '/nodes/pve%20cluster%231/qemu/105/status/start'),
    'Node names with spaces and hashes are rawurlencoded in lifecycle URL'
);


// =============================================================================
// SUITE 11: Lease Lifecycle Integration & Scoping
// =============================================================================
echo "\nSUITE 11: Lease Lifecycle Integration & Scoping\n";

if (!class_exists('TestSpyLifecycleService')) {
    class TestSpyLifecycleService extends ProxmoxLifecycleService
    {
        public int $startCalls = 0;
        public int $stopCalls = 0;
        public bool $shouldThrow = false;

        public function startGuest(?int $vmid = null): array
        {
            $this->startCalls++;
            if ($this->shouldThrow) {
                throw new RuntimeException('Proxmox hypervisor connection timeout');
            }
            return [
                'ok' => true,
                'action' => 'start',
                'vmid' => $vmid ?? 105,
                'node' => 'vor-server',
                'guest_type' => 'qemu',
                'status' => 'dispatched',
                'upid' => 'UPID:vor-server:00001:start',
                'error' => null,
            ];
        }

        public function stopGuest(?int $vmid = null, bool $force = false): array
        {
            $this->stopCalls++;
            if ($this->shouldThrow) {
                throw new RuntimeException('Proxmox hypervisor connection timeout');
            }
            return [
                'ok' => true,
                'action' => $force ? 'stop' : 'shutdown',
                'vmid' => $vmid ?? 105,
                'node' => 'vor-server',
                'guest_type' => 'qemu',
                'status' => 'dispatched',
                'upid' => 'UPID:vor-server:00002:stop',
                'error' => null,
            ];
        }
    }
}

try {
    $db = Database::getConnection();
} catch (Throwable) {
    $db = null;
}

if ($db !== null) {
    // Clean up any old test allocations
    $db->exec("DELETE FROM allocation_leases WHERE allocation_id IN (SELECT id FROM allocations WHERE project_name LIKE 'test-challenger-%')");
    $db->exec("DELETE FROM allocations WHERE project_name LIKE 'test-challenger-%'");

    $spyLifecycle = new TestSpyLifecycleService();
    $nodeRepo = new NodeRepository($db);
    $userRepo = new UserRepository($db);
    $allocRepo = new AllocationRepository($db, $spyLifecycle);
    $provService = new ProvisioningService($db, $nodeRepo, $userRepo, $allocRepo, $spyLifecycle);

    $now = new DateTimeImmutable();

    // 11.1 Immediate Booking on vor-server triggers startGuest()
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $immJob = $provService->provisionJob(
        userId: 4,
        projectName: 'test-challenger-imm-vor',
        dockerImage: RuntimePreset::UbuntuBuild->value,
        rawStartTime: $now->format('Y-m-d\TH:i'),
        startupScript: null,
        stagedNodeHours: [10 => 1],
        nowOverride: $now
    );
    check($immJob['ok'] === true, 'Immediate booking on vor-server provisions successfully');
    check($spyLifecycle->startCalls === 1, 'Immediate active booking on vor-server triggers startGuest()');
    check($spyLifecycle->stopCalls === 0, 'Immediate active booking does not call stopGuest()');
    $vorLeaseId = (int) ($immJob['leases'][0]['lease_id'] ?? 0);

    // 11.2 Future Booking on vor-server does NOT trigger startGuest()
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $futJob = $provService->provisionJob(
        userId: 4,
        projectName: 'test-challenger-fut-vor',
        dockerImage: RuntimePreset::UbuntuBuild->value,
        rawStartTime: $now->modify('+4 hours')->format('Y-m-d\TH:i'),
        startupScript: null,
        stagedNodeHours: [10 => 1],
        nowOverride: $now
    );
    check($futJob['ok'] === true, 'Future scheduled booking on vor-server provisions successfully');
    check($spyLifecycle->startCalls === 0, 'Future scheduled booking on vor-server does NOT trigger startGuest()');

    // 11.3 Booking on Campus Node (Node 1) does NOT trigger Proxmox lifecycle
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $campusJob = $provService->provisionJob(
        userId: 4,
        projectName: 'test-challenger-imm-campus',
        dockerImage: RuntimePreset::PyTorchCuda->value,
        rawStartTime: $now->format('Y-m-d\TH:i'),
        startupScript: null,
        stagedNodeHours: [1 => 1],
        nowOverride: $now
    );
    check($campusJob['ok'] === true, 'Immediate booking on campus node provisions successfully');
    check($spyLifecycle->startCalls === 0, 'Immediate booking on campus node does NOT trigger startGuest()');
    $campusLeaseId = (int) ($campusJob['leases'][0]['lease_id'] ?? 0);

    // 11.4 Early Termination on vor-server triggers stopGuest()
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $termVor = $provService->terminateLeaseEarly(actingUserId: 4, leaseId: $vorLeaseId, nowOverride: $now);
    check($termVor['ok'] === true, 'Early termination on vor-server succeeds');
    check($spyLifecycle->stopCalls === 1, 'Early termination on vor-server triggers stopGuest()');

    // 11.5 Early Termination on Campus Node does NOT trigger Proxmox lifecycle
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $termCampus = $provService->terminateLeaseEarly(actingUserId: 4, leaseId: $campusLeaseId, nowOverride: $now);
    check($termCampus['ok'] === true, 'Early termination on campus node succeeds');
    check($spyLifecycle->stopCalls === 0, 'Early termination on campus node does NOT trigger stopGuest()');

    // 11.6 Temporal Activation of vor-server via syncTemporalStatuses triggers startGuest()
    $insertAllocStmt = $db->prepare(
        "INSERT INTO allocations (user_id, project_name, docker_image, startup_script, total_credits_cost, created_at)
         VALUES (4, 'test-challenger-sync', 'ubuntu:24.04', NULL, 15.00, NOW())"
    );
    $insertAllocStmt->execute();
    $syncAllocId = (int) $db->lastInsertId();

    $insertLeaseStmt = $db->prepare(
        "INSERT INTO allocation_leases (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, refunded_credits, assigned_port, access_token, lease_status)
         VALUES (:aid, 10, DATE_SUB(NOW(), INTERVAL 5 MINUTE), DATE_ADD(NOW(), INTERVAL 2 HOUR), 5.00, 0.00, 25001, 'synctok1', 'SCHEDULED')"
    );
    $insertLeaseStmt->execute([':aid' => $syncAllocId]);
    $syncVorLeaseId = (int) $db->lastInsertId();

    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $allocRepo->syncTemporalStatuses();
    check($spyLifecycle->startCalls === 1, 'syncTemporalStatuses triggers startGuest() on vor-server transition to ACTIVE');

    // 11.7 Temporal Expiration of vor-server via syncTemporalStatuses triggers stopGuest()
    $db->exec("UPDATE allocation_leases SET start_time = DATE_SUB(NOW(), INTERVAL 2 HOUR), end_time = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id = " . $syncVorLeaseId);
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $allocRepo->syncTemporalStatuses();
    check($spyLifecycle->stopCalls === 1, 'syncTemporalStatuses triggers stopGuest() on vor-server transition to EXPIRED');

    // 11.8 Campus Node Expiration does NOT trigger stopGuest()
    $insertCampusExpStmt = $db->prepare(
        "INSERT INTO allocation_leases (allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, refunded_credits, assigned_port, access_token, lease_status)
         VALUES (:aid, 1, DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 5 MINUTE), 45.00, 0.00, 25002, 'synctok2', 'ACTIVE')"
    );
    $insertCampusExpStmt->execute([':aid' => $syncAllocId]);
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $allocRepo->syncTemporalStatuses();
    check($spyLifecycle->stopCalls === 0, 'syncTemporalStatuses does NOT trigger stopGuest() on campus node transition to EXPIRED');

    // 11.9 Exception Safety: Hypervisor failure does NOT fail DB transaction in provisionJob or terminateLeaseEarly
    $spyLifecycle->shouldThrow = true;
    $spyLifecycle->startCalls = 0;
    $spyLifecycle->stopCalls = 0;
    $failSafeJob = $provService->provisionJob(
        userId: 4,
        projectName: 'test-challenger-failsafe',
        dockerImage: RuntimePreset::UbuntuBuild->value,
        rawStartTime: $now->format('Y-m-d\TH:i'),
        startupScript: null,
        stagedNodeHours: [10 => 1],
        nowOverride: $now
    );
    check($failSafeJob['ok'] === true, 'provisionJob succeeds and commits when hypervisor API throws exception');
    $failSafeLeaseId = (int) ($failSafeJob['leases'][0]['lease_id'] ?? 0);

    $failSafeTerm = $provService->terminateLeaseEarly(actingUserId: 4, leaseId: $failSafeLeaseId, nowOverride: $now);
    check($failSafeTerm['ok'] === true, 'terminateLeaseEarly succeeds and commits when hypervisor API throws exception');

    $syncSafe = true;
    try {
        $allocRepo->syncTemporalStatuses();
    } catch (Throwable) {
        $syncSafe = false;
    }
    check($syncSafe, 'syncTemporalStatuses catches hypervisor exceptions safely without breaking execution');

    // Final clean up of test records
    $db->exec("DELETE FROM allocation_leases WHERE allocation_id IN (SELECT id FROM allocations WHERE project_name LIKE 'test-challenger-%')");
    $db->exec("DELETE FROM allocations WHERE project_name LIKE 'test-challenger-%'");
} else {
    echo "  [WARN] Database unavailable, skipping Suite 11 DB integration checks\n";
}

echo "\n=================================================================\n";
echo sprintf("Challenger Summary: %d Passed, %d Failed (Total %d checks)\n", $passed, $failed, $total);
echo "=================================================================\n";

exit($failed > 0 ? 1 : 0);

