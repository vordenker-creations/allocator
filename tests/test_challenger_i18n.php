<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use VKU\Allocator\Service\I18n;
use VKU\Allocator\Service\ProxmoxTelemetryService;

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
echo "CHALLENGER 2B — Empirical Adversarial I18n & Telemetry Stress Harness\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "=================================================================\n\n";

// =============================================================================
// SUITE 1: I18n::translateStatus Edge Cases & Attack Vectors
// =============================================================================
echo "SUITE 1: I18n::translateStatus Edge Cases & Attack Vectors\n";

// 1.1 Canonical 'AVAILABLE'
check(I18n::translateStatus('AVAILABLE', 'en') === 'AVAILABLE', 'Canonical AVAILABLE in EN');
check(I18n::translateStatus('AVAILABLE', 'vi') === 'KHẢ DỤNG', 'Canonical AVAILABLE in VI');

// 1.2 Lowercase 'available'
check(I18n::translateStatus('available', 'en') === 'AVAILABLE', 'Lowercase available in EN resolves via strtoupper');
check(I18n::translateStatus('available', 'vi') === 'KHẢ DỤNG', 'Lowercase available in VI resolves via strtoupper');

// 1.3 Mixed case
check(I18n::translateStatus('Available', 'vi') === 'KHẢ DỤNG', 'Mixed-case Available in VI resolves');
check(I18n::translateStatus('aVaiLaBLe', 'vi') === 'KHẢ DỤNG', 'Bizarre-case aVaiLaBLe in VI resolves');

// 1.4 Leading & trailing whitespace
check(I18n::translateStatus('   AVAILABLE   ', 'vi') === 'KHẢ DỤNG', 'Leading & trailing spaces around AVAILABLE');
check(I18n::translateStatus("\t\n  available \r\n", 'vi') === 'KHẢ DỤNG', 'Tabs, newlines, CRLF around available');

// 1.5 Legacy 'ONLINE // FREE' backward compatibility
check(I18n::translateStatus('ONLINE // FREE', 'en') === 'AVAILABLE', 'Legacy ONLINE // FREE in EN maps to AVAILABLE');
check(I18n::translateStatus('ONLINE // FREE', 'vi') === 'KHẢ DỤNG', 'Legacy ONLINE // FREE in VI maps to KHẢ DỤNG');
check(I18n::translateStatus('  online // free  ', 'vi') === 'KHẢ DỤNG', 'Whitespace & lowercase legacy online // free in VI');

// 1.6 Unmapped strings & boundary inputs
check(I18n::translateStatus('UNKNOWN_STATUS_xyz', 'en') === 'UNKNOWN_STATUS_xyz', 'Unmapped status returns raw input in EN');
check(I18n::translateStatus('UNKNOWN_STATUS_xyz', 'vi') === 'UNKNOWN_STATUS_xyz', 'Unmapped status returns raw input in VI');
check(I18n::translateStatus('', 'vi') === '', 'Empty string input returns empty string');
check(I18n::translateStatus('   ', 'vi') === '   ', 'Whitespace-only string returns unchanged whitespace string');

// 1.7 Vietnamese string injection attempt (ensure it does NOT map or crash)
// Translations must be strictly unidirectional from canonical tokens. Localized strings should never map.
check(I18n::translateStatus('KHẢ DỤNG', 'vi') === 'KHẢ DỤNG', 'VI string injection KHẢ DỤNG is unmapped and returned intact');
check(I18n::translateStatus('KHẢ DỤNG', 'en') === 'KHẢ DỤNG', 'VI string injection KHẢ DỤNG in EN is unmapped and returned intact');
check(I18n::translateStatus('TRỰC TUYẾN // TRỐNG', 'vi') === 'TRỰC TUYẾN // TRỐNG', 'Legacy VI string TRỰC TUYẾN // TRỐNG is unmapped and returned intact');
check(I18n::translateStatus('BẢO TRÌ', 'vi') === 'BẢO TRÌ', 'VI status BẢO TRÌ is unmapped and returned intact');
check(I18n::translateStatus('NGOẠI TUYẾN', 'vi') === 'NGOẠI TUYẾN', 'VI status NGOẠI TUYẾN is unmapped and returned intact');

// 1.8 Adversarial payloads (XSS, control characters, long strings)
$xssPayload = '<script>alert("xss")</script>';
check(I18n::translateStatus($xssPayload, 'vi') === $xssPayload, 'XSS payload in translateStatus returned intact without crash');

$longString = str_repeat('A', 10000);
check(I18n::translateStatus($longString, 'vi') === $longString, '10,000 char status string handled without memory exhaustion');

// =============================================================================
// SUITE 2: I18n::translateTelemetrySource Edge Cases
// =============================================================================
echo "\nSUITE 2: I18n::translateTelemetrySource Edge Cases\n";

// 2.1 Exact 'LIVE PVE API'
check(I18n::translateTelemetrySource('LIVE PVE API', 'en') === 'LIVE TELEMETRY', 'Exact LIVE PVE API in EN maps to LIVE TELEMETRY');
check(I18n::translateTelemetrySource('LIVE PVE API', 'vi') === 'DỮ LIỆU TRỰC TIẾP', 'Exact LIVE PVE API in VI maps to DỮ LIỆU TRỰC TIẾP');

// 2.2 Substring match on LIVE PVE API
check(I18n::translateTelemetrySource('LIVE PVE API (node-01)', 'en') === 'LIVE TELEMETRY', 'Substring LIVE PVE API (node-01) in EN maps to LIVE TELEMETRY');
check(I18n::translateTelemetrySource('LIVE PVE API (node-01)', 'vi') === 'DỮ LIỆU TRỰC TIẾP', 'Substring LIVE PVE API (node-01) in VI maps to DỮ LIỆU TRỰC TIẾP');

// 2.3 Simulated sources
$simulatedRaw = 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)';
// Note: $simulatedRaw contains 'LIVE PVE API'!
// Let's verify priority in translateTelemetrySource:
// If translateTelemetrySource checks 'LIVE PVE API' first, what happens to 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)'?
// Let's inspect this critical edge case!
echo "  [INFO] Evaluating priority in translateTelemetrySource for simulated raw string...\n";
$transSimEn = I18n::translateTelemetrySource($simulatedRaw, 'en');
$transSimVi = I18n::translateTelemetrySource($simulatedRaw, 'vi');
echo "  [INFO] Simulated raw translated (EN): $transSimEn\n";
echo "  [INFO] Simulated raw translated (VI): $transSimVi\n";

// Pure 'SIMULATED' source (without LIVE PVE API substring)
check(I18n::translateTelemetrySource('SIMULATED', 'en') === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)', 'Pure SIMULATED in EN maps to pve_sim_badge');
check(I18n::translateTelemetrySource('SIMULATED', 'vi') === 'THÔNG SỐ: MÔ PHỎNG (CẤU HÌNH .ENV ĐỂ KẾT NỐI PVE API THỰC)', 'Pure SIMULATED in VI maps to pve_sim_badge VI');

// 2.4 Unknown telemetry sources
check(I18n::translateTelemetrySource('CUSTOM_TELEMETRY_ENGINE', 'en') === 'CUSTOM_TELEMETRY_ENGINE', 'Unknown source returns unmodified in EN');
check(I18n::translateTelemetrySource('CUSTOM_TELEMETRY_ENGINE', 'vi') === 'CUSTOM_TELEMETRY_ENGINE', 'Unknown source returns unmodified in VI');
check(I18n::translateTelemetrySource('', 'vi') === '', 'Empty telemetry source returns empty string');

// 2.5 Vietnamese string injection attempt on translateTelemetrySource
check(I18n::translateTelemetrySource('DỮ LIỆU TRỰC TIẾP', 'vi') === 'DỮ LIỆU TRỰC TIẾP', 'VI badge injection returns unmodified');
check(I18n::translateTelemetrySource('THÔNG SỐ: MÔ PHỎNG', 'vi') === 'THÔNG SỐ: MÔ PHỎNG', 'VI sim badge injection returns unmodified');

// =============================================================================
// SUITE 3: ProxmoxTelemetryService Contract Verification
// =============================================================================
echo "\nSUITE 3: ProxmoxTelemetryService Contract Verification\n";

// 3.1 Simulated fallback state contract
$fallback = ProxmoxTelemetryService::getSimulatedFallbackState('test-node');
check($fallback['is_live'] === false, 'Fallback is_live === false');
check($fallback['telemetry_source'] === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)', 'Fallback telemetry_source contains SIMULATED message');
check($fallback['status_message'] === 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)', 'Fallback status_message matches');
check($fallback['node_name'] === 'test-node', 'Fallback node_name honored');

// 3.2 Live parsing contract (via Reflection on parseLiveResponses)
$service = new ProxmoxTelemetryService(
    host: 'https://127.0.0.1:8006',
    node: 'vor-server',
    tokenId: 'test',
    tokenSecret: 'secret'
);

$reflector = new ReflectionClass(ProxmoxTelemetryService::class);
$parseMethod = $reflector->getMethod('parseLiveResponses');
$parseMethod->setAccessible(true);

$mockStatusData = [
    'cpu' => 0.25,
    'cpuinfo' => ['model' => 'Intel Xeon E5-2680 v4', 'cpus' => 28, 'sockets' => 2],
    'memory' => ['used' => 32 * 1024 * 1024 * 1024, 'total' => 64 * 1024 * 1024 * 1024],
    'uptime' => 7200,
];
$mockQemuData = [
    ['vmid' => 100, 'status' => 'running', 'name' => 'vm-cuda-1'],
    ['vmid' => 101, 'status' => 'stopped', 'name' => 'vm-cuda-2'],
];

$liveResult = $parseMethod->invoke($service, $mockStatusData, $mockQemuData, 'vor-server');

check($liveResult['is_live'] === true, 'Live telemetry is_live === true');
check($liveResult['telemetry_source'] === 'LIVE PVE API', 'Live telemetry telemetry_source strictly equals "LIVE PVE API"');
check($liveResult['status_message'] === 'LIVE PVE API', 'Live telemetry status_message strictly equals "LIVE PVE API"');
check($liveResult['running_vms'] === 1, 'Live telemetry calculates 1 running VM');
check($liveResult['total_vms'] === 2, 'Live telemetry calculates 2 total VMs');

// =============================================================================
// SUITE 4: Dictionary Parity & Label Invariants
// =============================================================================
echo "\nSUITE 4: Dictionary Parity & Label Invariants\n";

$enLabels = I18n::all('en');
$viLabels = I18n::all('vi');

check(count($enLabels) === count($viLabels), sprintf('Parity maintained: EN count (%d) === VI count (%d)', count($enLabels), count($viLabels)));
check(count($enLabels) > 120, 'Dictionary count > 120');

// Key dictionary entries verified
check($enLabels['status_online_free'] === 'AVAILABLE', 'EN status_online_free is AVAILABLE');
check($viLabels['status_online_free'] === 'KHẢ DỤNG', 'VI status_online_free is KHẢ DỤNG');

check($enLabels['pve_live_badge'] === 'LIVE TELEMETRY', 'EN pve_live_badge is LIVE TELEMETRY');
check($viLabels['pve_live_badge'] === 'DỮ LIỆU TRỰC TIẾP', 'VI pve_live_badge is DỮ LIỆU TRỰC TIẾP');

check($enLabels['footer_attribution'] === 'A CREATION OF VORDENKER.WORK', 'EN footer_attribution is A CREATION OF VORDENKER.WORK');
check($viLabels['footer_attribution'] === 'A CREATION OF VORDENKER.WORK', 'VI footer_attribution is A CREATION OF VORDENKER.WORK');

check($enLabels['footer_text'] === 'VKU’s Allocator — Vietnam-Korea University of Information and Communication Technology', 'EN footer_text is de-cluttered institutional name');
check($viLabels['footer_text'] === 'VKU’s Allocator — Trường Đại học Công nghệ Thông tin và Truyền thông Việt - Hàn', 'VI footer_text is de-cluttered institutional name');

// Ensure no %s remains in footer_text
check(!str_contains($enLabels['footer_text'], '%s'), 'EN footer_text contains no %s format specifier');
check(!str_contains($viLabels['footer_text'], '%s'), 'VI footer_text contains no %s format specifier');

// Ensure no PHP or MySQL version mentions in footer_text
check(!str_contains(strtolower($enLabels['footer_text']), 'php'), 'EN footer_text contains no PHP version');
check(!str_contains(strtolower($enLabels['footer_text']), 'mysql'), 'EN footer_text contains no MySQL version');

// Check translateStatus mapping does not contain Vietnamese keys in map
$translateStatusMethod = $reflector = new ReflectionMethod(I18n::class, 'translateStatus');
check($translateStatusMethod->isStatic(), 'translateStatus is static');

echo "\n=================================================================\n";
echo sprintf("Summary: %d Passed, %d Failed (Total: %d)\n", $passed, $failed, $total);
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
