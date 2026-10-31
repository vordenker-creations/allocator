<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

use VKU\Allocator\Service\I18n;
use VKU\Allocator\Service\ProxmoxLifecycleService;

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
echo "VKU Allocator — Navbar Logo & UI Localization Verification Suite\n";
echo "PHP Runtime: " . PHP_VERSION . "\n";
echo "=================================================================\n\n";

$layoutPath = dirname(__DIR__) . '/views/layout_top.php';
$layoutBottomPath = dirname(__DIR__) . '/views/layout_bottom.php';
$cssPath = dirname(__DIR__) . '/public/assets/style.css';
$indexPath = dirname(__DIR__) . '/public/index.php';

$layoutContent = (string) file_get_contents($layoutPath);
$layoutBottomContent = (string) file_get_contents($layoutBottomPath);
$cssContent = (string) file_get_contents($cssPath);
$indexContent = (string) file_get_contents($indexPath);

if (!function_exists('pullFlash')) {
    function pullFlash(): ?array
    {
        return null;
    }
}
if (!function_exists('e')) {
    function e(mixed $val): string
    {
        return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Helper function to mock and capture layout_top.php output
function renderLayoutTop(string $lang, ?array $user = null, int $trayCount = 0, int $activeLeaseCount = 0): string
{
    $currentLang = $lang;
    $currentUser = $user;
    $hydratedTray = ['node_count' => $trayCount];
    $activeSystemLeaseCount = $activeLeaseCount;
    $activeNav = 'matrix';
    $pageTitle = 'Test Page';

    ob_start();
    require dirname(__DIR__) . '/views/layout_top.php';
    return ob_get_clean() ?: '';
}

// -----------------------------------------------------------------------------
// Suite 1: Navbar Logo SVG Structure Verification
// -----------------------------------------------------------------------------
echo "1. Testing Navbar Logo SVG Structure...\n";

assertCheck(
    str_contains($layoutContent, '<svg') && str_contains($layoutContent, 'viewBox="0 0 646 129"'),
    'Navbar contains SVG logo with exact viewBox 0 0 646 129'
);

assertCheck(
    str_contains($layoutContent, 'fill="#E21A1B"')
    && str_contains($layoutContent, 'fill="#FFC400"')
    && str_contains($layoutContent, 'fill="#004BA5"'),
    'SVG logo includes the three vertical colored stripes (#E21A1B, #FFC400, #004BA5)'
);

$expectedPathFragment = 'M294.31 89L306.454 40.82H317.08L329.29 89H320.842L318.202 77.318H305.398L302.758 89';
assertCheck(
    str_contains($layoutContent, $expectedPathFragment),
    'SVG logo contains the vector path coordinates drawing mixed-case wordmark "Allocator"'
);

assertCheck(
    !str_contains($layoutContent, 'VKU // ALLOCATOR')
    && !preg_match('/<span[^>]*class=["\']brand-mark["\'][^>]*>.*<\/span>/i', $layoutContent),
    'The old brand mark (VKU // ALLOCATOR) has been replaced'
);

$brandTagMatch = [];
preg_match('/<a[^>]+class=["\'][^"\']*brand[^"\']*["\'][^>]*>(.*?)<\/a>/s', $layoutContent, $brandTagMatch);
$brandMarkup = $brandTagMatch[1] ?? '';
$brandTextOnly = strip_tags($brandMarkup);

assertCheck(
    !str_contains($brandTextOnly, '//') && !preg_match('/\b\/\/\b/', $brandMarkup),
    'The "//" delimiter is completely absent from the navbar brand markup'
);

assertCheck(
    !str_contains($layoutContent, 'ALLOCATOR'),
    'Uppercase "ALLOCATOR" is completely absent from navbar markup'
);

assertCheck(
    str_contains($layoutContent, 'Allocator'),
    'Mixed-case brand wordmark "Allocator" is present in navbar markup'
);

// -----------------------------------------------------------------------------
// Suite 2: Light and Dark Theme Contrast Adaptation
// -----------------------------------------------------------------------------
echo "\n2. Testing Light and Dark Theme Contrast Adaptation...\n";

assertCheck(
    preg_match('/<path[^>]+class=["\'][^"\']*brand-wordmark[^"\']*["\'][^>]+fill=["\']currentColor["\']/', $layoutContent) === 1
    || preg_match('/<path[^>]+fill=["\']currentColor["\'][^>]+class=["\'][^"\']*brand-wordmark[^"\']*["\']/', $layoutContent) === 1,
    'Wordmark SVG path uses fill="currentColor" and class="brand-wordmark" for dynamic theming'
);

assertCheck(
    str_contains($cssContent, '.brand-wordmark') && str_contains($cssContent, 'fill: var(--fg-primary)'),
    'CSS defines .brand-wordmark with fill: var(--fg-primary)'
);

assertCheck(
    str_contains($cssContent, '.brand') && str_contains($cssContent, 'color: var(--fg-primary)'),
    'CSS defines .brand with color: var(--fg-primary) to cascade into currentColor'
);

// Contrast ratio mathematical verification
function calcLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2)) / 255.0;
    $g = hexdec(substr($hex, 2, 2)) / 255.0;
    $b = hexdec(substr($hex, 4, 2)) / 255.0;

    $calc = function (float $c): float {
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $calc($r) + 0.7152 * $calc($g) + 0.0722 * $calc($b);
}

function calcContrastRatio(string $c1, string $c2): float
{
    $l1 = calcLuminance($c1);
    $l2 = calcLuminance($c2);
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
}

$lightFg = '#141413';
$lightBg = '#FAF8F5';
$lightRatio = calcContrastRatio($lightFg, $lightBg);
assertCheck(
    $lightRatio >= 7.0,
    sprintf('Light theme contrast ratio is %.2f:1 (exceeds WCAG AAA threshold of 7:1)', $lightRatio)
);

$darkFg = '#FAF8F5';
$darkBg = '#141413';
$darkRatio = calcContrastRatio($darkFg, $darkBg);
assertCheck(
    $darkRatio >= 7.0,
    sprintf('Dark theme contrast ratio is %.2f:1 (exceeds WCAG AAA threshold of 7:1)', $darkRatio)
);

// -----------------------------------------------------------------------------
// Suite 3: Language Toggle Button & Placement
// -----------------------------------------------------------------------------
echo "\n3. Testing Language Toggle Button & Placement...\n";

assertCheck(
    str_contains($layoutContent, 'id="lang-toggle"'),
    'Language switch button with id="lang-toggle" exists in layout_top.php'
);

assertCheck(
    str_contains($layoutContent, 'id="theme-toggle"'),
    'Theme toggle button with id="theme-toggle" exists in layout_top.php'
);

$topbarMetaMatch = [];
preg_match('/<div[^>]+class=["\'][^"\']*topbar-meta[^"\']*["\'][^>]*>(.*?)<\/div>/s', $layoutContent, $topbarMetaMatch);
$topbarMetaMarkup = $topbarMetaMatch[1] ?? '';

assertCheck(
    str_contains($topbarMetaMarkup, 'id="theme-toggle"') && str_contains($topbarMetaMarkup, 'id="lang-toggle"'),
    'Both theme toggle and language switch buttons are within topbar-meta'
);

$isAdjacent = preg_match('/id=["\']theme-toggle["\'][^>]*>.*?<\/button>\s*<button[^>]+id=["\']lang-toggle["\']/s', $topbarMetaMarkup) === 1
    || preg_match('/id=["\']lang-toggle["\'][^>]*>.*?<\/button>\s*<button[^>]+id=["\']theme-toggle["\']/s', $topbarMetaMarkup) === 1
    || preg_match('/id=["\']theme-toggle["\'][^>]*>.*?<\/button>\s*<button[^>]+id=["\']lang-toggle["\']/', $topbarMetaMarkup) === 1;

assertCheck(
    $isAdjacent,
    'Language switch button is placed directly adjacent to the Theme toggle button'
);

assertCheck(
    str_contains($layoutBottomContent, "document.getElementById('lang-toggle')")
    && str_contains($layoutBottomContent, "vku_lang"),
    'layout_bottom.php includes client-side click listener to persist vku_lang cookie and redirect'
);

assertCheck(
    str_contains($layoutContent, 'id="lang-toggle"') && str_contains($layoutContent, 'aria-label='),
    'Language switch button includes descriptive aria-label for accessibility'
);

// -----------------------------------------------------------------------------
// Suite 4: Language State Resolution & Persistence (I18n Service)
// -----------------------------------------------------------------------------
echo "\n4. Testing Language State Resolution & Persistence...\n";

assertCheck(
    I18n::resolve(null, null, null) === 'en',
    'Default language resolves to "en" when unconfigured'
);

assertCheck(
    I18n::resolve('vi', null, null) === 'vi',
    'Resolves "vi" from explicit GET parameter'
);

assertCheck(
    I18n::resolve(null, 'vi', null) === 'vi',
    'Resolves "vi" from persistent cookie'
);

assertCheck(
    I18n::resolve(null, null, 'vi') === 'vi',
    'Resolves "vi" from persistent session'
);

assertCheck(
    I18n::resolve('en', 'vi', 'vi') === 'en',
    'GET parameter takes precedence over cookie and session'
);

assertCheck(
    I18n::resolve(null, 'vi', 'en') === 'vi',
    'Cookie takes precedence over session'
);

assertCheck(
    I18n::resolve('fr', 'invalid', 'unknown') === 'en',
    'Invalid/unsupported language codes safely fall back to "en"'
);

assertCheck(
    I18n::normalize('  VI  ') === 'vi' && I18n::normalize('EN') === 'en',
    'I18n::normalize handles whitespace and case-insensitivity'
);

// -----------------------------------------------------------------------------
// Suite 5: UI Label Localization Coverage across Entire Application
// -----------------------------------------------------------------------------
echo "\n5. Testing UI Label Localization Coverage...\n";

$enLabels = I18n::all('en');
$viLabels = I18n::all('vi');

assertCheck($enLabels['nav_node_matrix'] === 'Node Matrix', 'EN mapping: Node Matrix');
assertCheck($viLabels['nav_node_matrix'] === 'Ma trận Node', 'VI mapping: Ma trận Node');

assertCheck($enLabels['nav_staging_tray'] === 'Staging Tray', 'EN mapping: Staging Tray');
assertCheck($viLabels['nav_staging_tray'] === 'Khay chờ', 'VI mapping: Khay chờ');

assertCheck($enLabels['nav_active_console'] === 'Active Console', 'EN mapping: Active Console');
assertCheck($viLabels['nav_active_console'] === 'Bảng điều khiển', 'VI mapping: Bảng điều khiển');

assertCheck($enLabels['nav_cluster_admin'] === 'Cluster Admin', 'EN mapping: Cluster Admin');
assertCheck($viLabels['nav_cluster_admin'] === 'Quản trị cụm', 'VI mapping: Quản trị cụm');

assertCheck($enLabels['nav_sign_in'] === 'Sign In', 'EN mapping: Sign In');
assertCheck($viLabels['nav_sign_in'] === 'Đăng nhập', 'VI mapping: Đăng nhập');

assertCheck($enLabels['nav_sign_out'] === 'Sign Out', 'EN mapping: Sign Out');
assertCheck($viLabels['nav_sign_out'] === 'Đăng xuất', 'VI mapping: Đăng xuất');

assertCheck($enLabels['nav_theme'] === 'Theme', 'EN mapping: Theme');
assertCheck($viLabels['nav_theme'] === 'Giao diện', 'VI mapping: Giao diện');

assertCheck(count($enLabels) === count($viLabels) && count($enLabels) > 120, 'Full application dictionary has parity between EN and VI (> 120 keys)');
assertCheck(I18n::translateStatus('ONLINE // FREE', 'vi') === 'TRỰC TUYẾN // TRỐNG', 'VI status translation: ONLINE // FREE');
assertCheck(I18n::translateRole('STUDENT', 'vi') === 'SINH VIÊN', 'VI role translation: STUDENT');
assertCheck(I18n::translateTierName('CUDA_COMPUTE', 'CUDA Compute Nodes', 'vi') === 'Cụm Node Tính Toán CUDA', 'VI tier name translation: CUDA_COMPUTE');

// -----------------------------------------------------------------------------
// Suite 6: Rendered Layout Top Localization & Mode Switching
// -----------------------------------------------------------------------------
echo "\n6. Testing Rendered Layout Top in English & Vietnamese Modes...\n";

$studentUser = [
    'id' => 1,
    'student_code' => 'VKU24001',
    'role' => 'STUDENT',
    'credit_balance' => '240.00',
];

$adminUser = [
    'id' => 2,
    'student_code' => 'VKU_ADMIN',
    'role' => 'ADMIN',
    'credit_balance' => '999.00',
];

// 6a. English mode with Admin user
$enHtml = renderLayoutTop('en', $adminUser, 3, 1);
assertCheck(str_contains($enHtml, '<html lang="en"'), 'English mode sets <html lang="en"');
assertCheck(str_contains($enHtml, 'Node Matrix'), 'English mode renders "Node Matrix"');
assertCheck(str_contains($enHtml, 'Staging Tray (3)'), 'English mode renders "Staging Tray (3)"');
assertCheck(str_contains($enHtml, 'Active Console (1)'), 'English mode renders "Active Console (1)"');
assertCheck(str_contains($enHtml, 'Cluster Admin'), 'English mode renders "Cluster Admin" for admin');
assertCheck(str_contains($enHtml, 'Sign Out'), 'English mode renders "Sign Out" for logged-in user');
assertCheck(str_contains($enHtml, 'Theme'), 'English mode renders "Theme" button');
assertCheck(str_contains($enHtml, 'VI'), 'English mode language toggle displays "VI" to switch to Vietnamese');

// 6b. Vietnamese mode with Admin user
$viHtml = renderLayoutTop('vi', $adminUser, 3, 1);
assertCheck(str_contains($viHtml, '<html lang="vi"'), 'Vietnamese mode sets <html lang="vi"');
assertCheck(str_contains($viHtml, 'Ma trận Node'), 'Vietnamese mode renders "Ma trận Node"');
assertCheck(str_contains($viHtml, 'Khay chờ (3)'), 'Vietnamese mode renders "Khay chờ (3)"');
assertCheck(str_contains($viHtml, 'Bảng điều khiển (1)'), 'Vietnamese mode renders "Bảng điều khiển (1)"');
assertCheck(str_contains($viHtml, 'Quản trị cụm'), 'Vietnamese mode renders "Quản trị cụm" for admin');
assertCheck(str_contains($viHtml, 'Đăng xuất'), 'Vietnamese mode renders "Đăng xuất" for logged-in user');
assertCheck(str_contains($viHtml, 'Giao diện'), 'Vietnamese mode renders "Giao diện" button');
assertCheck(str_contains($viHtml, 'EN'), 'Vietnamese mode language toggle displays "EN" to switch to English');

// 6c. Guest user (null) Sign In / Đăng nhập
$enGuestHtml = renderLayoutTop('en', null);
assertCheck(str_contains($enGuestHtml, 'Sign In'), 'English mode renders "Sign In" for guest');

$viGuestHtml = renderLayoutTop('vi', null);
assertCheck(str_contains($viGuestHtml, 'Đăng nhập'), 'Vietnamese mode renders "Đăng nhập" for guest');

// 6d. Layout resolution from cookie environment when $currentLang not explicitly passed
$_COOKIE['vku_lang'] = 'vi';
ob_start();
(function () {
    $currentUser = null;
    $hydratedTray = ['node_count' => 0];
    require dirname(__DIR__) . '/views/layout_top.php';
})();
$cookieResolvedHtml = ob_get_clean() ?: '';
assertCheck(
    str_contains($cookieResolvedHtml, '<html lang="vi"'),
    'layout_top.php falls back to $_COOKIE["vku_lang"] when $currentLang variable is unassigned'
);
unset($_COOKIE['vku_lang']);

// -----------------------------------------------------------------------------
// Suite 7: Endpoint & Cookie Persistence Handling in index.php
// -----------------------------------------------------------------------------
echo "\n7. Testing Language Switch Endpoint & Cookie Handling in index.php...\n";

assertCheck(
    str_contains($indexContent, "['GET', '/lang']") && str_contains($indexContent, "['POST', '/lang']"),
    'index.php router defines both GET and POST /lang endpoints'
);

assertCheck(
    str_contains($indexContent, "setcookie('vku_lang'") && str_contains($indexContent, "setcookie('lang'"),
    'index.php sets persistent cookies (vku_lang and lang) on language switch'
);

assertCheck(
    str_contains($indexContent, "str_starts_with(\$returnTo, '/')")
    && (str_contains($indexContent, "!str_starts_with(\$returnTo, '//')") || str_contains($indexContent, "str_starts_with(\$returnTo, '//')")),
    'index.php prevents open-redirect attacks by validating return_to path'
);

$validateReturnTo = function (string $url, ?string $host = null, ?string $referer = null): string {
    return I18n::sanitizeReturnTo($url, $host, $referer);
};
assertCheck($validateReturnTo('/console') === '/console', 'Open-redirect validation: safe relative path is preserved');
assertCheck($validateReturnTo('https://evil.com') === '/', 'Open-redirect validation: external absolute URL rejected');
assertCheck($validateReturnTo('//evil.com') === '/', 'Open-redirect validation: protocol-relative URL rejected');
assertCheck($validateReturnTo('/\\evil.com') === '/', 'Open-redirect validation: backslash bypass URL rejected');
assertCheck($validateReturnTo('/matrix?hours=3&lang=en') === '/matrix?hours=3', 'Stale lang query parameter stripped from return_to to prevent revert loop');
assertCheck($validateReturnTo('/matrix?lang=vi') === '/matrix', 'Pure lang query parameter cleanly removed leaving base path');
assertCheck($validateReturnTo("/console\r\nLocation: evil.com") === '/consoleLocation: evil.com', 'CRLF control characters stripped from return_to');
assertCheck($validateReturnTo("/\r\n/evil.com") === '/', 'CRLF protocol-relative bypass attempt rejected');
assertCheck($validateReturnTo('/lang') === '/', 'Direct return_to=/lang rejected to prevent infinite redirect loop');
assertCheck($validateReturnTo('/lang?lang=vi') === '/', 'Direct return_to=/lang with query rejected');
assertCheck($validateReturnTo('/set-lang') === '/', 'Direct return_to=/set-lang rejected');
assertCheck($validateReturnTo('/locale') === '/', 'Direct return_to=/locale rejected');
assertCheck($validateReturnTo('', 'localhost:8080', 'http://localhost:8080/matrix?hours=2&lang=en') === '/matrix?hours=2', 'Same-origin Referer extracted and sanitized when return_to is empty');
assertCheck($validateReturnTo('', 'localhost:8080', 'https://attacker.com/steal') === '/', 'External Referer safely rejected to / when return_to is empty');
assertCheck($validateReturnTo('/console#leases') === '/console#leases', 'URL fragment hash preserved across redirection');
assertCheck($validateReturnTo('http://:80') === '/', 'Malformed URL parsing handled safely without errors');

// -----------------------------------------------------------------------------
// Suite 8: CT 200 Rollback + Start Parameter Verification
// -----------------------------------------------------------------------------
echo "\n8. Testing CT 200 Snapshot Rollback + Start-on-Rollback Dispatch...\n";

$rollbackCalls = [];
$rollbackExec = function (string $url, array $headers, string $method = 'POST', array $params = []) use (&$rollbackCalls): array {
    $rollbackCalls[] = ['url' => $url, 'method' => $method, 'params' => $params];
    if (str_contains($url, '/api2/json/nodes') && !str_contains($url, '/lxc/')) {
        return ['data' => [['node' => 'vor', 'status' => 'online']]];
    }
    return ['data' => 'UPID:vor:00001234:00112233:659F2537:vzstart:200:root@pam!allocator:'];
};

$ctLifecycle = new ProxmoxLifecycleService(
    host: 'https://192.168.1.200:8006',
    node: 'vor',
    tokenId: 'root@pam!allocator',
    tokenSecret: 'secret',
    leaseVmid: 200,
    guestType: 'lxc',
    hostVmid: 100,
    httpExecutor: $rollbackExec,
    cleanSnapshot: 'clean'
);

$ctStartRes = $ctLifecycle->startGuest();
assertCheck($ctStartRes['ok'] === true, 'startGuest with cleanSnapshot succeeds');
$foundRollbackWithStart = false;
foreach ($rollbackCalls as $call) {
    if (str_contains($call['url'], '/lxc/200/snapshot/clean/rollback') && ($call['params']['start'] ?? null) === 1) {
        $foundRollbackWithStart = true;
    }
}
assertCheck($foundRollbackWithStart, 'startGuest passes start=1 to /lxc/200/snapshot/clean/rollback so CT 200 boots atomically');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=================================================================\n";
echo sprintf("Verification Summary: %d Passed, %d Failed\n", $passed, $failed);
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
