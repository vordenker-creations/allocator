<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

use VKU\Allocator\Domain\Enum\NodeStatus;
use VKU\Allocator\Domain\Enum\RuntimePreset;
use VKU\Allocator\Domain\Enum\UserRole;
use VKU\Allocator\Infrastructure\Database;
use VKU\Allocator\Repository\AllocationRepository;
use VKU\Allocator\Repository\NodeRepository;
use VKU\Allocator\Repository\UserRepository;
use VKU\Allocator\Service\AuthService;
use VKU\Allocator\Service\ProvisioningService;
use VKU\Allocator\Service\ProxmoxLifecycleService;
use VKU\Allocator\Service\ProxmoxTelemetryService;
use VKU\Allocator\Service\I18n;
use VKU\Allocator\Service\SshKeyValidator;
use VKU\Allocator\Service\StagingTrayService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentLang = I18n::resolve(
    isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : null,
    isset($_COOKIE['vku_lang']) && is_string($_COOKIE['vku_lang']) ? $_COOKIE['vku_lang'] : (isset($_COOKIE['lang']) && is_string($_COOKIE['lang']) ? $_COOKIE['lang'] : null),
    isset($_SESSION['lang']) && is_string($_SESSION['lang']) ? $_SESSION['lang'] : null
);

if (isset($_GET['lang']) && is_string($_GET['lang']) && in_array($currentLang, I18n::SUPPORTED_LANGUAGES, true)) {
    $_SESSION['lang'] = $currentLang;
    setcookie('vku_lang', $currentLang, [
        'expires' => time() + 31536000,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    setcookie('lang', $currentLang, [
        'expires' => time() + 31536000,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}

$pdo = Database::getConnection();
$nodeRepo = new NodeRepository($pdo);
$userRepo = new UserRepository($pdo);
$lifecycleService = new ProxmoxLifecycleService();
$allocationRepo = new AllocationRepository($pdo, $lifecycleService);

$authService = new AuthService($userRepo);
$trayService = new StagingTrayService($nodeRepo);
$provisioningService = new ProvisioningService($pdo, $nodeRepo, $userRepo, $allocationRepo, $lifecycleService);

// Synchronize temporal lease statuses (SCHEDULED -> ACTIVE -> EXPIRED)
$allocationRepo->syncTemporalStatuses();

// Flash message helpers
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * @return array{type: string, message: string}|null
 */
function pullFlash(): ?array
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function e(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Resolve route from URI path or ?route= query parameter
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = $_GET['route'] ?? rtrim($requestPath, '/') ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$currentUser = $authService->currentUser();
$currentUserRole = $authService->currentUserRole();
$activeSystemLeaseCount = $currentUser !== null
    ? $allocationRepo->countUserActiveOrScheduledLeases((int) $currentUser['id'])
    : 0;

// Clean match() router
match ([$method, $route]) {
    // -------------------------------------------------------------------------
    // Authentication Routes
    // -------------------------------------------------------------------------
    ['GET', '/login'] => (function () use ($currentUser, $trayService, $currentLang): void {
        if ($currentUser !== null) {
            redirect('/');
        }
        $pageTitle = I18n::get('page_title_login', $currentLang);
        $activeNav = 'login';
        $hydratedTray = $trayService->getHydratedTray();
        require dirname(__DIR__) . '/views/login.php';
    })(),

    ['POST', '/login'] => (function () use ($authService, $currentLang): void {
        $identifier = (string) ($_POST['identifier'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $returnTo = (string) ($_POST['return_to'] ?? '/');

        $result = $authService->login($identifier, $password);
        if (!$result['ok']) {
            $err = isset($result['error']) ? I18n::translateServiceMessage((string) $result['error'], $currentLang) : I18n::get('flash_auth_failed', $currentLang);
            setFlash('error', $err);
            redirect('/login');
        }

        $user = $result['user'];
        setFlash('success', I18n::format('flash_auth_success', $currentLang, [(string) $user['student_code'], I18n::translateRole((string) $user['role'], $currentLang)]));
        redirect(str_starts_with($returnTo, '/') ? $returnTo : '/');
    })(),

    ['POST', '/logout'] => (function () use ($authService, $currentLang): void {
        $authService->logout();
        setFlash('info', I18n::get('flash_logout', $currentLang));
        redirect('/');
    })(),

    // -------------------------------------------------------------------------
    // Language Preference Toggle Endpoint
    // -------------------------------------------------------------------------
    ['GET', '/lang'], ['POST', '/lang'], ['GET', '/set-lang'], ['POST', '/set-lang'], ['GET', '/locale'], ['POST', '/locale'] => (function () use ($currentLang): void {
        $rawTarget = is_string($_POST['lang'] ?? null) ? $_POST['lang'] : (is_string($_GET['lang'] ?? null) ? $_GET['lang'] : '');
        $targetLang = strtolower(trim($rawTarget));
        if (!in_array($targetLang, I18n::SUPPORTED_LANGUAGES, true)) {
            $targetLang = $currentLang === I18n::LANG_VI ? I18n::LANG_EN : I18n::LANG_VI;
        }

        $_SESSION['lang'] = $targetLang;
        setcookie('vku_lang', $targetLang, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        setcookie('lang', $targetLang, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax',
        ]);

        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || ($_GET['format'] ?? '') === 'json') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'lang' => $targetLang]);
            return;
        }

        $rawReturn = (string) ($_POST['return_to'] ?? $_GET['return_to'] ?? '');
        $returnTo = I18n::sanitizeReturnTo(
            $rawReturn,
            $_SERVER['HTTP_HOST'] ?? null,
            $_SERVER['HTTP_REFERER'] ?? null
        );
        $returnTo = str_replace(["\r", "\n", "\0"], '', $returnTo);
        $isSafe = str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !str_starts_with($returnTo, '/\\');
        if (!$isSafe) {
            $returnTo = '/';
        }
        redirect($returnTo);
    })(),

    // -------------------------------------------------------------------------
    // Loop A: Node Discovery Matrix & Time-Window Availability Filter
    // -------------------------------------------------------------------------
    ['GET', '/'], ['GET', '/matrix'] => (function () use (
        $nodeRepo,
        $trayService,
        $currentUser,
        $currentUserRole,
        $activeSystemLeaseCount,
        $currentLang
    ): void {
        $now = new DateTimeImmutable();
        $defaultStartStr = $now->format('Y-m-d\TH:i');
        $minStartStr = $now->modify('-5 minutes')->format('Y-m-d\TH:i');
        $maxHorizonStr = $now->modify('+24 hours')->format('Y-m-d\TH:i');

        $rawFilterStart = (string) ($_GET['start_time'] ?? $trayService->getTray()['start_time'] ?? $defaultStartStr);
        $horizonCheck = StagingTrayService::validateStartTimeHorizon($rawFilterStart, $now);

        if ($horizonCheck['ok'] && isset($horizonCheck['dt'])) {
            $windowStart = $horizonCheck['dt'];
            $filterError = null;
        } else {
            $windowStart = $now;
            $filterError = $horizonCheck['error'] ?? null;
        }

        $previewHours = max(1, min(24, (int) ($_GET['hours'] ?? 2)));
        $minVram = max(0, (int) ($_GET['min_vram'] ?? 0));
        $selectedTierId = isset($_GET['tier_id']) && $_GET['tier_id'] !== '' ? (int) $_GET['tier_id'] : null;

        $windowEnd = $windowStart->modify(sprintf('+%d hours', $previewHours));

        $tiers = $nodeRepo->findAllTiers();
        $nodes = $nodeRepo->findNodesWithWindowAvailability($windowStart, $windowEnd, $minVram, $selectedTierId);
        $hydratedTray = $trayService->getHydratedTray();

        // Group nodes by tier_id
        $nodesByTier = [];
        foreach ($nodes as $node) {
            $nodesByTier[(int) $node['tier_id']][] = $node;
        }

        // Live Proxmox VE Telemetry & Campus Fleet Simulation
        $proxmoxHost = (string) ($_ENV['PROXMOX_HOST'] ?? getenv('PROXMOX_HOST') ?: 'https://192.168.1.200:8006');
        $proxmoxService = new ProxmoxTelemetryService();
        $pveTelemetry = $proxmoxService->getNodeTelemetry();

        $activeLeaseNodeIds = $nodeRepo->getActiveLeaseNodeIds($now);
        $nodeAvailability = $nodeRepo->get24HourAvailability($now);

        $nodeTelemetryMap = [];
        foreach ($nodes as $node) {
            $nid = (int) $node['id'];
            $hostname = (string) $node['hostname'];
            if ($hostname === 'vor-server') {
                $nodeTelemetryMap[$nid] = [
                    'cpu_usage_pct' => (float) $pveTelemetry['cpu_usage_pct'],
                    'ram_used_gb' => (float) $pveTelemetry['ram_used_gb'],
                    'ram_total_gb' => (float) $pveTelemetry['ram_total_gb'],
                    'ram_usage_pct' => (float) $pveTelemetry['ram_usage_pct'],
                    'vram_allocated_gb' => 0,
                    'vram_total_gb' => 0,
                    'vram_usage_pct' => 0.0,
                ];
            } else {
                $hasActive = in_array($nid, $activeLeaseNodeIds, true);
                $nodeTelemetryMap[$nid] = ProxmoxTelemetryService::simulateCampusNodeTelemetry($node, $hasActive);
            }
        }

        $pageTitle = I18n::get('page_title_matrix', $currentLang);
        $activeNav = 'matrix';
        require dirname(__DIR__) . '/views/matrix.php';
    })(),

    // -------------------------------------------------------------------------
    // Loop B: Session Staging Tray Actions
    // -------------------------------------------------------------------------
    ['POST', '/tray/add'] => (function () use ($trayService, $currentUserRole, $activeSystemLeaseCount, $currentLang): void {
        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $hours = max(1, (int) ($_POST['hours'] ?? 2));
        $startTime = (string) ($_POST['start_time'] ?? '');

        // If logged in as STUDENT and already has 1 active/scheduled lease, block early
        if ($currentUserRole === UserRole::Student && $activeSystemLeaseCount >= 1) {
            setFlash('error', I18n::get('flash_student_hoarding', $currentLang));
            redirect('/console');
        }

        $result = $trayService->stageNode($nodeId, $hours, $currentUserRole, $startTime);
        setFlash($result['ok'] ? 'success' : 'error', I18n::translateServiceMessage($result['message'], $currentLang));

        $redirectTo = (string) ($_POST['redirect_to'] ?? '/tray');
        redirect(str_starts_with($redirectTo, '/') ? $redirectTo : '/tray');
    })(),

    ['POST', '/tray/remove'] => (function () use ($trayService, $currentLang): void {
        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $trayService->removeNode($nodeId);
        setFlash('info', I18n::get('flash_node_removed', $currentLang));
        redirect('/tray');
    })(),

    ['POST', '/tray/clear'] => (function () use ($trayService, $currentLang): void {
        $trayService->clearTray();
        setFlash('info', I18n::get('flash_tray_cleared', $currentLang));
        redirect('/');
    })(),

    ['POST', '/tray/update'] => (function () use ($trayService, $currentUserRole, $currentLang): void {
        $projectName = (string) ($_POST['project_name'] ?? 'vku-research-job');
        $dockerImage = (string) ($_POST['docker_image'] ?? RuntimePreset::PyTorchCuda->value);
        $startTime = (string) ($_POST['start_time'] ?? '');
        $startupScript = (string) ($_POST['startup_script'] ?? '');
        $nodeHours = is_array($_POST['node_hours'] ?? null) ? $_POST['node_hours'] : [];

        $result = $trayService->updateJobSpec(
            $projectName,
            $dockerImage,
            $startTime,
            $startupScript,
            $nodeHours,
            $currentUserRole
        );

        setFlash($result['ok'] ? 'success' : 'error', I18n::translateServiceMessage($result['message'], $currentLang));
        redirect('/tray');
    })(),

    ['GET', '/tray'] => (function () use (
        $trayService,
        $currentUser,
        $currentUserRole,
        $activeSystemLeaseCount,
        $currentLang
    ): void {
        $now = new DateTimeImmutable();
        $minStartStr = $now->modify('-5 minutes')->format('Y-m-d\TH:i');
        $maxStartStr = $now->modify('+24 hours')->format('Y-m-d\TH:i');

        $hydratedTray = $trayService->getHydratedTray();
        $presets = RuntimePreset::cases();

        $pageTitle = I18n::get('page_title_tray', $currentLang);
        $activeNav = 'tray';
        require dirname(__DIR__) . '/views/tray.php';
    })(),

    // -------------------------------------------------------------------------
    // Loop C: Transactional Validation, Lock & Provisioning
    // -------------------------------------------------------------------------
    ['POST', '/provision'] => (function () use (
        $currentUser,
        $currentUserRole,
        $trayService,
        $provisioningService,
        $currentLang
    ): void {
        if ($currentUser === null) {
            setFlash('error', I18n::get('flash_signin_before_prov', $currentLang));
            redirect('/login');
        }

        $projectName = (string) ($_POST['project_name'] ?? '');
        $dockerImage = (string) ($_POST['docker_image'] ?? '');
        $startTime = (string) ($_POST['start_time'] ?? '');
        $startupScript = (string) ($_POST['startup_script'] ?? '');
        $sshPublicKey = (string) ($_POST['ssh_public_key'] ?? '');
        $nodeHoursInput = is_array($_POST['node_hours'] ?? null) ? $_POST['node_hours'] : [];

        // Sync form edits into session tray first
        $updateResult = $trayService->updateJobSpec(
            $projectName,
            $dockerImage,
            $startTime,
            $startupScript,
            $nodeHoursInput,
            $currentUserRole
        );

        if (!$updateResult['ok']) {
            setFlash('error', I18n::translateServiceMessage($updateResult['message'], $currentLang));
            redirect('/tray');
        }

        $tray = $trayService->getTray();
        $stagedNodeHours = [];
        foreach ($tray['nodes'] as $nid => $spec) {
            $stagedNodeHours[(int) $nid] = (int) $spec['hours'];
        }

        $result = $provisioningService->provisionJob(
            userId: (int) $currentUser['id'],
            projectName: $tray['project_name'],
            dockerImage: $tray['docker_image'],
            rawStartTime: $tray['start_time'],
            startupScript: $tray['startup_script'],
            stagedNodeHours: $stagedNodeHours,
            submittedSshKey: $sshPublicKey,
        );

        if (!$result['ok']) {
            $err = isset($result['error']) ? I18n::translateServiceMessage((string) $result['error'], $currentLang) : I18n::get('flash_prov_failed', $currentLang);
            setFlash('error', $err);
            redirect('/tray');
        }

        $trayService->clearTray();
        setFlash(
            'success',
            I18n::format(
                'flash_prov_success',
                $currentLang,
                [
                    (int) $result['allocation_id'],
                    (float) $result['total_cost'],
                    count($result['leases'] ?? []),
                ]
            )
        );
        redirect('/console');
    })(),

    // -------------------------------------------------------------------------
    // Loop C & D: Active Console View & Early Lease Termination
    // -------------------------------------------------------------------------
    ['GET', '/console'] => (function () use (
        $currentUser,
        $allocationRepo,
        $trayService,
        $lifecycleService,
        $activeSystemLeaseCount,
        $currentLang
    ): void {
        if ($currentUser === null) {
            setFlash('info', I18n::get('flash_signin_console', $currentLang));
            redirect('/login');
        }

        $leases = $allocationRepo->findLeasesByUser((int) $currentUser['id']);
        $hydratedTray = $trayService->getHydratedTray();

        // Calculate live active/scheduled count directly from the fresh query so it's always accurate
        $activeSystemLeaseCount = count(array_filter($leases, function (array $l): bool {
            return in_array((string) ($l['lease_status'] ?? ''), ['ACTIVE', 'SCHEDULED'], true);
        }));

        // Self-healing check: if user has an ACTIVE lease on vor-server, ensure CT 200 is powered on
        foreach ($leases as $l) {
            if (($l['lease_status'] ?? '') === 'ACTIVE' && strcasecmp((string) ($l['hostname'] ?? ''), 'vor-server') === 0) {
                try {
                    $guestStatus = $lifecycleService->getGuestStatus();
                    if (is_array($guestStatus) && (($guestStatus['data']['status'] ?? '') === 'stopped') && empty($guestStatus['data']['lock'])) {
                        $lifecycleService->startGuest();
                    }
                } catch (Throwable) {
                }
                break;
            }
        }

        $pageTitle = I18n::get('page_title_console', $currentLang);
        $activeNav = 'console';
        require dirname(__DIR__) . '/views/console.php';
    })(),

    ['POST', '/profile/ssh-key'] => (function () use ($currentUser, $userRepo, $currentLang): void {
        if ($currentUser === null) {
            redirect('/login');
        }

        $sshKey = trim((string) ($_POST['ssh_public_key'] ?? ''));
        if (!SshKeyValidator::isValid($sshKey)) {
            setFlash('error', I18n::get('flash_ssh_invalid', $currentLang));
            redirect('/console');
        }

        $userRepo->updateSshKey((int) $currentUser['id'], $sshKey);
        setFlash('success', I18n::get('flash_ssh_updated', $currentLang));
        redirect('/console');
    })(),

    ['POST', '/lease/terminate'] => (function () use (
        $currentUser,
        $currentUserRole,
        $provisioningService,
        $currentLang
    ): void {
        if ($currentUser === null) {
            redirect('/login');
        }

        $leaseId = (int) ($_POST['lease_id'] ?? 0);
        $isAdmin = $currentUserRole?->isAdmin() ?? false;

        $result = $provisioningService->terminateLeaseEarly(
            leaseId: $leaseId,
            actingUserId: (int) $currentUser['id'],
            isAdmin: $isAdmin,
        );

        if (!$result['ok']) {
            $err = isset($result['error']) ? I18n::translateServiceMessage((string) $result['error'], $currentLang) : I18n::get('flash_term_failed', $currentLang);
            setFlash('error', $err);
        } else {
            setFlash(
                'success',
                I18n::format(
                    'flash_term_success',
                    $currentLang,
                    [
                        $leaseId,
                        (string) $result['hostname'],
                        (float) $result['refunded_credits'],
                        (int) $result['unused_hours'],
                    ]
                )
            );
        }

        $returnTo = (string) ($_POST['return_to'] ?? '/console');
        redirect(str_starts_with($returnTo, '/') ? $returnTo : '/console');
    })(),

    // -------------------------------------------------------------------------
    // Loop D: Cluster Admin Override & Management
    // -------------------------------------------------------------------------
    ['GET', '/admin'] => (function () use (
        $currentUser,
        $currentUserRole,
        $nodeRepo,
        $userRepo,
        $allocationRepo,
        $trayService,
        $activeSystemLeaseCount,
        $currentLang
    ): void {
        if ($currentUser === null || $currentUserRole !== UserRole::Admin) {
            setFlash('error', I18n::get('flash_admin_required', $currentLang));
            redirect('/');
        }

        $now = new DateTimeImmutable();
        $tiers = $nodeRepo->findAllTiers();
        $nodes = $nodeRepo->findNodesWithWindowAvailability($now, $now->modify('+1 hour'));
        $users = $userRepo->findAllUsers();
        $recentLeases = $allocationRepo->findAllRecentLeases(40);
        $hydratedTray = $trayService->getHydratedTray();

        $pageTitle = I18n::get('page_title_admin', $currentLang);
        $activeNav = 'admin';
        require dirname(__DIR__) . '/views/admin.php';
    })(),

    ['POST', '/admin/node/status'] => (function () use ($currentUserRole, $nodeRepo, $currentLang): void {
        if ($currentUserRole !== UserRole::Admin) {
            setFlash('error', I18n::get('flash_unauthorized', $currentLang));
            redirect('/');
        }

        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $statusRaw = (string) ($_POST['status'] ?? '');
        $status = NodeStatus::tryFrom($statusRaw);

        if ($nodeId <= 0 || $status === null) {
            setFlash('error', I18n::get('flash_invalid_node_status', $currentLang));
            redirect('/admin');
        }

        $nodeRepo->updateStatus($nodeId, $status);
        setFlash('success', I18n::format('flash_node_status_updated', $currentLang, [$nodeId, I18n::translateStatus($status->value, $currentLang)]));
        redirect('/admin');
    })(),

    ['POST', '/admin/node/create'] => (function () use ($currentUserRole, $nodeRepo, $currentLang): void {
        if ($currentUserRole !== UserRole::Admin) {
            setFlash('error', I18n::get('flash_unauthorized', $currentLang));
            redirect('/');
        }

        $tierId = (int) ($_POST['tier_id'] ?? 1);
        $hostname = trim((string) ($_POST['hostname'] ?? ''));
        $ipAddress = trim((string) ($_POST['ip_address'] ?? ''));
        $gpuModel = trim((string) ($_POST['gpu_model'] ?? ''));
        $vramGb = max(0, (int) ($_POST['vram_gb'] ?? 0));
        $ramGb = max(1, (int) ($_POST['ram_gb'] ?? 64));
        $cpuCores = max(1, (int) ($_POST['cpu_cores'] ?? 16));
        $creditsPerHour = max(0.5, (float) ($_POST['credits_per_hour'] ?? 10.0));

        if ($hostname === '' || $ipAddress === '') {
            setFlash('error', I18n::get('flash_node_host_ip_req', $currentLang));
            redirect('/admin');
        }

        try {
            $newId = $nodeRepo->createNode(
                tierId: $tierId,
                hostname: $hostname,
                ipAddress: $ipAddress,
                gpuModel: $gpuModel !== '' ? $gpuModel : null,
                vramGb: $vramGb,
                ramGb: $ramGb,
                cpuCores: $cpuCores,
                creditsPerHour: $creditsPerHour,
                status: NodeStatus::Online,
            );
            setFlash('success', I18n::format('flash_node_created', $currentLang, [$hostname, $newId]));
        } catch (Throwable $e) {
            setFlash('error', I18n::get('flash_node_create_err', $currentLang) . $e->getMessage());
        }

        redirect('/admin');
    })(),

    ['POST', '/admin/user/topup'] => (function () use ($currentUserRole, $userRepo, $currentLang): void {
        if ($currentUserRole !== UserRole::Admin) {
            setFlash('error', I18n::get('flash_unauthorized', $currentLang));
            redirect('/');
        }

        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);

        if ($targetUserId <= 0 || $amount === 0.0) {
            setFlash('error', I18n::get('flash_topup_invalid', $currentLang));
            redirect('/admin');
        }

        try {
            $userRepo->adjustCredits($targetUserId, $amount);
            setFlash('success', I18n::format('flash_topup_success', $currentLang, [$targetUserId, $amount]));
        } catch (Throwable $e) {
            setFlash('error', I18n::get('flash_topup_err', $currentLang) . $e->getMessage());
        }

        redirect('/admin');
    })(),

    // -------------------------------------------------------------------------
    // Cryptographic Lease Shell Token Gatekeeper API (called by CT 200 ttyd)
    // -------------------------------------------------------------------------
    ['GET', '/api/verify-shell-token'] => (function () use ($allocationRepo): void {
        header('Content-Type: application/json; charset=UTF-8');

        $token = (string) ($_GET['token'] ?? '');
        $hostname = (string) ($_GET['hostname'] ?? 'vor-server');

        $lease = $allocationRepo->verifyActiveShellToken($token, $hostname);
        if ($lease === null) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'error' => 'Unauthorized: Token missing, expired, terminated, or not active for target node.',
            ]);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'lease_id' => (int) $lease['lease_id'],
            'student_code' => (string) $lease['student_code'],
            'project_name' => (string) $lease['project_name'],
            'hostname' => (string) $lease['hostname'],
            'end_time' => (string) $lease['end_time'],
        ]);
    })(),

    default => (function () use ($trayService, $currentUser, $currentLang): void {
        http_response_code(404);
        $hydratedTray = $trayService->getHydratedTray();
        $pageTitle = I18n::get('page_title_404', $currentLang);
        $activeNav = '';
        echo '<!DOCTYPE html><html><body style="font-family:monospace;padding:3rem;">';
        echo '<h1>' . e(I18n::get('err_404_heading', $currentLang)) . '</h1><p><a href="/">' . e(I18n::get('err_404_back', $currentLang)) . '</a></p>';
        echo '</body></html>';
    })(),
};
