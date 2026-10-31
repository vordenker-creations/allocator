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
use VKU\Allocator\Service\SshKeyValidator;
use VKU\Allocator\Service\StagingTrayService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
    ['GET', '/login'] => (function () use ($currentUser, $trayService): void {
        if ($currentUser !== null) {
            redirect('/');
        }
        $pageTitle = 'Sign In — VKU Allocator';
        $activeNav = 'login';
        $hydratedTray = $trayService->getHydratedTray();
        require dirname(__DIR__) . '/views/login.php';
    })(),

    ['POST', '/login'] => (function () use ($authService): void {
        $identifier = (string) ($_POST['identifier'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $returnTo = (string) ($_POST['return_to'] ?? '/');

        $result = $authService->login($identifier, $password);
        if (!$result['ok']) {
            setFlash('error', $result['error'] ?? 'Authentication failed.');
            redirect('/login');
        }

        $user = $result['user'];
        setFlash('success', sprintf('Authenticated as %s (%s).', $user['student_code'], $user['role']));
        redirect(str_starts_with($returnTo, '/') ? $returnTo : '/');
    })(),

    ['POST', '/logout'] => (function () use ($authService): void {
        $authService->logout();
        setFlash('info', 'Session terminated.');
        redirect('/');
    })(),

    // -------------------------------------------------------------------------
    // Loop A: Node Discovery Matrix & Time-Window Availability Filter
    // -------------------------------------------------------------------------
    ['GET', '/'], ['GET', '/matrix'] => (function () use (
        $nodeRepo,
        $trayService,
        $currentUser,
        $currentUserRole,
        $activeSystemLeaseCount
    ): void {
        $now = new DateTimeImmutable();
        $defaultStartStr = $now->format('Y-m-d\TH:i');
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

        $pageTitle = 'Node Matrix — VKU Allocator';
        $activeNav = 'matrix';
        require dirname(__DIR__) . '/views/matrix.php';
    })(),

    // -------------------------------------------------------------------------
    // Loop B: Session Staging Tray Actions
    // -------------------------------------------------------------------------
    ['POST', '/tray/add'] => (function () use ($trayService, $currentUserRole, $activeSystemLeaseCount): void {
        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $hours = max(1, (int) ($_POST['hours'] ?? 2));
        $startTime = (string) ($_POST['start_time'] ?? '');

        // If logged in as STUDENT and already has 1 active/scheduled lease, block early
        if ($currentUserRole === UserRole::Student && $activeSystemLeaseCount >= 1) {
            setFlash(
                'error',
                'Hoarding prevention policy: STUDENT accounts may only hold 1 active/scheduled lease across the system at any time. Terminate your current lease in the Console first.'
            );
            redirect('/console');
        }

        $result = $trayService->stageNode($nodeId, $hours, $currentUserRole, $startTime);
        setFlash($result['ok'] ? 'success' : 'error', $result['message']);

        $redirectTo = (string) ($_POST['redirect_to'] ?? '/tray');
        redirect(str_starts_with($redirectTo, '/') ? $redirectTo : '/tray');
    })(),

    ['POST', '/tray/remove'] => (function () use ($trayService): void {
        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $trayService->removeNode($nodeId);
        setFlash('info', 'Node removed from staging tray.');
        redirect('/tray');
    })(),

    ['POST', '/tray/clear'] => (function () use ($trayService): void {
        $trayService->clearTray();
        setFlash('info', 'Staging tray cleared.');
        redirect('/');
    })(),

    ['POST', '/tray/update'] => (function () use ($trayService, $currentUserRole): void {
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

        setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('/tray');
    })(),

    ['GET', '/tray'] => (function () use (
        $trayService,
        $currentUser,
        $currentUserRole,
        $activeSystemLeaseCount
    ): void {
        $now = new DateTimeImmutable();
        $minStartStr = $now->format('Y-m-d\TH:i');
        $maxStartStr = $now->modify('+24 hours')->format('Y-m-d\TH:i');

        $hydratedTray = $trayService->getHydratedTray();
        $presets = RuntimePreset::cases();

        $pageTitle = 'Job Spec Staging Tray — VKU Allocator';
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
        $provisioningService
    ): void {
        if ($currentUser === null) {
            setFlash('error', 'Please sign in with your VKU credentials before provisioning hardware.');
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
            setFlash('error', $updateResult['message']);
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
            setFlash('error', $result['error'] ?? 'Provisioning failed.');
            redirect('/tray');
        }

        $trayService->clearTray();
        setFlash(
            'success',
            sprintf(
                'Allocation #%d provisioned atomically! Deducted %.2f CR across %d lease(s).',
                $result['allocation_id'],
                $result['total_cost'],
                count($result['leases'] ?? [])
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
        $trayService
    ): void {
        if ($currentUser === null) {
            setFlash('info', 'Sign in to access your active hardware console and SSH receipts.');
            redirect('/login');
        }

        $leases = $allocationRepo->findLeasesByUser((int) $currentUser['id']);
        $hydratedTray = $trayService->getHydratedTray();

        $pageTitle = 'Active Console — VKU Allocator';
        $activeNav = 'console';
        require dirname(__DIR__) . '/views/console.php';
    })(),

    ['POST', '/profile/ssh-key'] => (function () use ($currentUser, $userRepo): void {
        if ($currentUser === null) {
            redirect('/login');
        }

        $sshKey = trim((string) ($_POST['ssh_public_key'] ?? ''));
        if (!SshKeyValidator::isValid($sshKey)) {
            setFlash('error', 'Invalid SSH public key format. Expected ssh-ed25519, ssh-rsa, or ecdsa-sha2-nistp*.');
            redirect('/console');
        }

        $userRepo->updateSshKey((int) $currentUser['id'], $sshKey);
        setFlash('success', 'SSH public key updated on your profile.');
        redirect('/console');
    })(),

    ['POST', '/lease/terminate'] => (function () use (
        $currentUser,
        $currentUserRole,
        $provisioningService
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
            setFlash('error', $result['error'] ?? 'Could not terminate lease.');
        } else {
            setFlash(
                'success',
                sprintf(
                    'Lease #%d (%s) terminated early. Refunded %.2f CR (%d unused full hour(s)) to account balance.',
                    $leaseId,
                    $result['hostname'],
                    $result['refunded_credits'],
                    $result['unused_hours']
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
        $trayService
    ): void {
        if ($currentUser === null || $currentUserRole !== UserRole::Admin) {
            setFlash('error', 'Restricted area: ADMIN role required.');
            redirect('/');
        }

        $now = new DateTimeImmutable();
        $tiers = $nodeRepo->findAllTiers();
        $nodes = $nodeRepo->findNodesWithWindowAvailability($now, $now->modify('+1 hour'));
        $users = $userRepo->findAllUsers();
        $recentLeases = $allocationRepo->findAllRecentLeases(40);
        $hydratedTray = $trayService->getHydratedTray();

        $pageTitle = 'Cluster Admin — VKU Allocator';
        $activeNav = 'admin';
        require dirname(__DIR__) . '/views/admin.php';
    })(),

    ['POST', '/admin/node/status'] => (function () use ($currentUserRole, $nodeRepo): void {
        if ($currentUserRole !== UserRole::Admin) {
            setFlash('error', 'Unauthorized.');
            redirect('/');
        }

        $nodeId = (int) ($_POST['node_id'] ?? 0);
        $statusRaw = (string) ($_POST['status'] ?? '');
        $status = NodeStatus::tryFrom($statusRaw);

        if ($nodeId <= 0 || $status === null) {
            setFlash('error', 'Invalid node ID or status value.');
            redirect('/admin');
        }

        $nodeRepo->updateStatus($nodeId, $status);
        setFlash('success', sprintf('Node #%d status updated to %s.', $nodeId, $status->value));
        redirect('/admin');
    })(),

    ['POST', '/admin/node/create'] => (function () use ($currentUserRole, $nodeRepo): void {
        if ($currentUserRole !== UserRole::Admin) {
            setFlash('error', 'Unauthorized.');
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
            setFlash('error', 'Hostname and IP address are required to register a hardware node.');
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
            setFlash('success', sprintf('Registered hardware node %s (#%d).', $hostname, $newId));
        } catch (Throwable $e) {
            setFlash('error', 'Could not create node: ' . $e->getMessage());
        }

        redirect('/admin');
    })(),

    ['POST', '/admin/user/topup'] => (function () use ($currentUserRole, $userRepo): void {
        if ($currentUserRole !== UserRole::Admin) {
            setFlash('error', 'Unauthorized.');
            redirect('/');
        }

        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);

        if ($targetUserId <= 0 || $amount === 0.0) {
            setFlash('error', 'Specify a valid user and non-zero credit delta.');
            redirect('/admin');
        }

        try {
            $userRepo->adjustCredits($targetUserId, $amount);
            setFlash('success', sprintf('Adjusted user #%d credit quota by %+.2f CR.', $targetUserId, $amount));
        } catch (Throwable $e) {
            setFlash('error', 'Credit adjustment failed: ' . $e->getMessage());
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

    default => (function () use ($trayService, $currentUser): void {
        http_response_code(404);
        $hydratedTray = $trayService->getHydratedTray();
        $pageTitle = '404 Not Found — VKU Allocator';
        $activeNav = '';
        echo '<!DOCTYPE html><html><body style="font-family:monospace;padding:3rem;">';
        echo '<h1>404 — Route Not Found</h1><p><a href="/">Return to VKU Node Matrix</a></p>';
        echo '</body></html>';
    })(),
};
