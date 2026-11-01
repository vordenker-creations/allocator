<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

final class I18n
{
    public const string LANG_EN = 'en';
    public const string LANG_VI = 'vi';

    /**
     * @var list<string>
     */
    public const array SUPPORTED_LANGUAGES = [self::LANG_EN, self::LANG_VI];

    /**
     * @var array<string, array<string, string>>
     */
    private static array $messages = [
        self::LANG_EN => [
            // Navbar & Layout
            'nav_node_matrix' => 'Node Matrix',
            'nav_staging_tray' => 'Staging Tray',
            'nav_active_console' => 'Active Console',
            'nav_cluster_admin' => 'Cluster Admin',
            'nav_sign_in' => 'Sign In',
            'nav_sign_out' => 'Sign Out',
            'nav_theme' => 'Theme',
            'lang_toggle_label' => 'VI',
            'lang_toggle_target' => 'vi',
            'lang_toggle_title' => 'Chuyển sang Tiếng Việt',
            'brand_sub' => 'HPC & Hardware Lease Scheduler',
            'theme_toggle_title' => 'Toggle Warm White / Soft Black Theme',
            'footer_text' => 'VKU’s Allocator — Vietnam-Korea University of Information and Communication Technology',
            'footer_attribution' => 'A CREATION OF VORDENKER.WORK',
            'copied_btn' => 'Copied!',

            // Page Titles
            'page_title_matrix' => 'Node Matrix — VKU Allocator',
            'page_title_tray' => 'Job Spec Staging Tray — VKU Allocator',
            'page_title_console' => 'Active Console — VKU Allocator',
            'page_title_admin' => 'Cluster Admin — VKU Allocator',
            'page_title_login' => 'Sign In — VKU Allocator',
            'page_title_404' => '404 Not Found — VKU Allocator',

            // Common Status & Role Badges
            'status_online_free' => 'AVAILABLE',
            'status_busy_window' => 'BUSY IN WINDOW',
            'status_online' => 'ONLINE',
            'status_maintenance' => 'MAINTENANCE',
            'status_offline' => 'OFFLINE',
            'status_active' => 'ACTIVE',
            'status_scheduled' => 'SCHEDULED',
            'status_expired' => 'EXPIRED',
            'status_cancelled' => 'CANCELLED',
            'status_staged' => 'STAGED',
            'role_student' => 'STUDENT',
            'role_researcher' => 'RESEARCHER',
            'role_admin' => 'ADMIN',

            // Seeded Tier Names & Descriptions
            'tier_name_CUDA_COMPUTE' => 'CUDA Compute Nodes',
            'tier_desc_CUDA_COMPUTE' => 'High-VRAM NVIDIA Tensor Core GPUs for deep learning training, fine-tuning, and CUDA workloads.',
            'tier_name_CPU_CLUSTER' => 'CPU Build & Simulation Cluster',
            'tier_desc_CPU_CLUSTER' => 'High-core-count AMD EPYC & Intel Xeon nodes for distributed compilation, CI builds, and simulations.',
            'tier_name_EDGE_AIOT' => 'Edge & AIoT Hardware Kits',
            'tier_desc_EDGE_AIOT' => 'Physical embedded compute kits (Jetson Orin, RISC-V, FPGA) with remote serial & SSH tunnel access.',

            // Matrix View
            'matrix_title' => 'Campus Compute & Hardware Node Matrix',
            'matrix_subtitle' => 'Inspect live cluster telemetry, filter by target time window (24-Hour Reservation Horizon) or VRAM, and stage hardware into your Job Spec Tray.',
            'policy_title' => 'Anti-Hoarding Policy:',
            'policy_summary' => 'STUDENT: max 1 node/job & max 1 active/scheduled lease system-wide • RESEARCHER / ADMIN: max 3 nodes/job • Booking Horizon: 24-Hour Reservation Horizon',
            'policy_active_role' => 'Active Role:',
            'policy_active_lease' => 'active lease',
            'policy_guest_prefix' => 'Browsing as Public Guest —',
            'policy_guest_signin' => 'Sign in',
            'policy_guest_suffix' => 'for Researcher/Admin quotas',
            'pve_banner_title' => 'PROXMOX VE HYPERVISOR TELEMETRY',
            'pve_live_badge' => 'LIVE TELEMETRY',
            'pve_sim_badge' => 'TELEMETRY: SIMULATED (CONFIGURE .ENV FOR LIVE PVE API)',
            'pve_host_label' => 'Host:',
            'pve_node_label' => 'Node:',
            'pve_cpu_hw' => 'CPU Hardware',
            'pve_live_cpu' => 'Live CPU Load',
            'pve_phys_ram' => 'Physical RAM',
            'pve_load_uptime' => 'Load Avg & Uptime',
            'pve_running_vms' => 'Running QEMU VMs',
            'pve_vms_unit' => 'VMs',
            'filter_reset_notice' => '(Resetting preview window to current time).',
            'filter_panel_title' => 'Target Window & Hardware Spec Filter',
            'filter_checking_window' => 'Checking Window:',
            'filter_window_start' => 'Window Start (24-Hour Reservation Horizon)',
            'filter_duration_preview' => 'Duration Preview (Hours)',
            'filter_min_vram' => 'Minimum VRAM (GB)',
            'vram_opt_0' => 'Any VRAM (Include CPU)',
            'vram_opt_24' => '24 GB+ (RTX 4090 / Orin)',
            'vram_opt_48' => '48 GB+ (L40S / Orin 64)',
            'vram_opt_80' => '80 GB (A100 SXM4)',
            'filter_resource_tier' => 'Resource Tier',
            'filter_all_tiers' => 'All Resource Tiers',
            'filter_max_h' => 'Max %dh',
            'filter_apply_btn' => 'Apply Window',
            'filter_reset_btn' => 'Reset',
            'tier_code_label' => 'Tier Code:',
            'tier_max_duration' => 'Max Lease Duration:',
            'spec_accelerator' => 'Accelerator / GPU',
            'spec_cpu_only' => 'CPU-Only Node',
            'spec_vram_ram' => 'VRAM / System RAM',
            'spec_cpu_threads' => 'CPU Threads',
            'spec_cores_unit' => '%d Cores',
            'spec_burn_rate' => 'Burn Rate (Max %dh)',
            'meter_cpu_load' => 'CPU Load',
            'meter_system_ram' => 'System RAM',
            'meter_vram_alloc' => 'VRAM Allocation',
            'meter_na_cpu' => 'N/A (CPU Node)',
            'avail_horizon_label' => '24h Availability Horizon',
            'avail_now_plus_24' => 'Now → +24h',
            'legend_free' => 'Free',
            'legend_booked' => 'Booked',
            'legend_maintenance' => 'Maintenance',
            'reserved_until_prefix' => 'Reserved in window until',
            'est_window_cost' => 'Est. Window Cost (%dh)',
            'btn_configure' => 'Configure',
            'btn_stage_node' => '+ Stage Node',
            'btn_unavailable' => 'Unavailable',
            'hours_input_title' => 'Hours (1 to %dh)',

            // Staging Tray View
            'tray_title' => 'Job Spec Staging Tray',
            'tray_subtitle' => 'Configure runtime environment, job start time (24-Hour Reservation Horizon), per-node duration, and commit an atomic reservation.',
            'tray_add_more' => '← Add More Nodes',
            'tray_clear' => 'Clear Tray',
            'tray_empty_title' => 'Your Job Spec Staging Tray is Empty',
            'tray_empty_desc' => 'Select one or more online hardware nodes from the Node Matrix to configure a compute job.',
            'tray_browse_btn' => 'Browse Node Matrix',
            'tray_sec1_title' => '1. Staged Compute Nodes (%d / %d Max for %s)',
            'th_node_hostname' => 'Node Hostname',
            'th_tier_cap' => 'Tier & Cap',
            'th_rate' => 'Rate',
            'th_hours' => 'Hours',
            'th_window_end' => 'Window End',
            'th_subtotal' => 'Subtotal',
            'tray_core_cpu' => '%d-Core CPU',
            'tray_remove_title' => 'Remove node from tray',
            'tray_recalc_btn' => 'Recalculate & Save Tray Changes',
            'tray_sec2_title' => '2. Runtime Environment & Scheduling Parameters',
            'tray_project_label' => 'Job / Project Label',
            'tray_start_label' => 'Job-Wide Start Time (24-Hour Reservation Horizon)',
            'tray_preset_label' => 'Container / Runtime Environment Preset',
            'tray_script_label' => 'Optional Startup Script / Job Notes',
            'tray_ssh_label' => 'SSH Public Key (ssh-ed25519 / ssh-rsa / ecdsa) — Required for Node Provisioning',
            'tray_ssh_hint' => 'Validated via strict OpenSSH regex prior to atomic SQL commit.',
            'tray_sec3_title' => '3. Transactional Cost Summary',
            'tray_sum_nodes' => 'Staged Nodes',
            'tray_sum_start' => 'Job Start Window',
            'tray_sum_horizon' => 'Booking Horizon',
            'tray_sum_horizon_valid' => 'Valid (24-Hour Horizon)',
            'tray_sum_balance' => 'Current Balance',
            'tray_sum_burn' => 'Total Credit Burn',
            'tray_sum_projected' => 'Projected Balance After Commit',
            'tray_hoarding_warn' => 'Hoarding limit: Your %s account already has %d active/scheduled lease. Terminate it in the Console first.',
            'tray_provision_btn' => 'Provision Job Atomically →',
            'tray_auth_required' => 'Authentication is required to verify your Compute Credit quota and SSH public key.',
            'tray_signin_btn' => 'Sign In to Provision Job →',
            'tray_acid_title' => 'ACID Transaction Guarantees:',
            'tray_acid_1' => '1. Row-level <code>FOR UPDATE</code> lock on user &amp; target nodes.',
            'tray_acid_2' => '2. Verifies zero overlapping leases in <code>[start_time, end_time]</code>.',
            'tray_acid_3' => '3. Deducts credits &amp; issues ephemeral SSH port (20000&ndash;60000).',

            // Console View
            'console_title' => 'Active Compute Console & Lease Receipts',
            'console_subtitle' => 'Connect via ephemeral SSH tunnel, monitor live lease countdowns, or trigger early release for an automatic pro-rata credit refund.',
            'console_new_hw_btn' => '+ Provision New Hardware',
            'console_active_panel' => 'Active & Scheduled Hardware Leases (%d)',
            'console_server_clock' => 'Server Clock:',
            'console_empty_prefix' => 'No active or scheduled leases at this time. Visit the',
            'console_empty_suffix' => 'to stage hardware.',
            'console_job_prefix' => 'Job',
            'console_rem_window' => 'Remaining Lease Window',
            'console_starts_in_label' => 'Starts In / Window',
            'console_calculating' => 'Calculating...',
            'console_copy_ssh' => 'Copy SSH',
            'console_spec_image' => 'Runtime Container Image',
            'console_spec_window' => 'Lease Window',
            'console_spec_port' => 'Ephemeral Port / Node IP',
            'console_spec_port_val' => 'Port %d → %s:22',
            'console_spec_token' => 'Access Token (32-Char Hex)',
            'console_startup_script' => 'STARTUP SCRIPT:',
            'console_shell_title' => 'vor-compute (CT 200) Browser Shell',
            'console_shell_sub' => '• Token-Gated Ephemeral Alpine Session',
            'console_launch_shell' => 'Launch Web Terminal',
            'console_hide_shell' => 'Hide Web Terminal',
            'console_pop_out' => 'Pop Out ↗',
            'console_locked_rate' => 'Locked Rate:',
            'console_early_refund' => 'Early Release Pro-Rata Refund:',
            'console_unused_hr' => 'unused full hr',
            'console_terminate_btn' => 'Terminate Lease & Refund %s CR',
            'console_terminate_confirm' => 'Terminate lease #%d on %s early and refund %s CR?',
            'console_history_title' => 'Lease Audit History (%d)',
            'console_history_empty' => 'No expired or cancelled leases yet.',
            'th_lease' => 'Lease',
            'th_node_project' => 'Node / Project',
            'th_window' => 'Window',
            'th_status' => 'Status',
            'th_refunded' => 'Refunded',
            'console_ssh_profile_title' => 'SSH Public Key Profile',
            'console_ssh_profile_label' => 'Authorized OpenSSH Key (ed25519 / rsa / ecdsa)',
            'console_ssh_update_btn' => 'Update SSH Public Key',
            'js_starts_in' => 'Starts in ',
            'js_remaining' => ' remaining',
            'js_window_expired' => 'Window Expired',

            // Admin View
            'admin_title' => 'HPC Cluster Administration & Override Console',
            'admin_subtitle' => 'Manage compute node operational states, register new hardware nodes, adjust student credit quotas, and audit cluster-wide leases.',
            'admin_sec1_title' => '1. Hardware Node Fleet Status Override (%d Nodes)',
            'th_id' => 'ID',
            'th_hostname_ip' => 'Hostname / IP',
            'th_tier_specs' => 'Tier & Specs',
            'th_state' => 'State',
            'th_set_state' => 'Set State',
            'btn_apply' => 'Apply',
            'admin_cpu_only_short' => 'CPU Only',
            'admin_sec2_title' => '2. Top Up / Adjust User Compute Credits',
            'admin_select_user' => 'Select Student / Researcher Account',
            'admin_credit_delta' => 'Credit Delta (+ to Top Up, - to Deduct)',
            'admin_commit_credit_btn' => 'Commit Credit Quota Adjustment',
            'admin_sec3_title' => '3. Register New Hardware Node',
            'admin_hostname_label' => 'Hostname (Unique)',
            'admin_ip_label' => 'Internal IP Address',
            'admin_gpu_label' => 'GPU / Accelerator Model (Leave empty for CPU node)',
            'admin_vram_label' => 'VRAM (GB)',
            'admin_ram_label' => 'System RAM (GB)',
            'admin_cores_label' => 'CPU Cores',
            'admin_rate_label' => 'Rate (CR/hr)',
            'admin_create_node_btn' => '+ Provision Hardware Node into Fleet',
            'admin_sec4_title' => '4. Cluster-Wide Lease Ledger (Recent %d Leases)',
            'th_user' => 'User',
            'th_node_tier' => 'Node / Tier',
            'th_project' => 'Project',
            'th_time_window' => 'Time Window',
            'th_port' => 'Port',
            'th_rate_refund' => 'Rate / Refund',
            'th_admin_action' => 'Admin Action',
            'admin_refund_prefix' => 'Refund:',
            'admin_force_terminate' => 'Force Terminate',
            'admin_force_confirm' => 'Admin force-terminate lease #%d and refund unused full hours?',

            // Login View
            'login_title' => 'VKU Identity Authentication',
            'login_badge' => 'Argon2id Auth',
            'login_desc' => 'Sign in with your VKU Student Code / Staff ID or `@vku.udn.vn` email address to stage and provision cluster hardware.',
            'login_id_label' => 'Student Code / Staff ID or VKU Email',
            'login_id_placeholder' => '22IT001 or an.nv.22it@vku.udn.vn',
            'login_pw_label' => 'Password',
            'login_submit_btn' => 'Authenticate →',
            'login_or_divider' => 'OR',
            'login_google_btn' => 'Continue with Google',
            'login_demo_title' => 'Quick-Fill Demo Accounts (Viva / Grading)',
            'login_demo_hint' => 'Click any role below to auto-fill credentials (Password:',
            'login_demo_student_name' => '22IT001 (Nguyen Van An)',
            'login_demo_student_cap' => 'Max 1 Node/Job • 240 CR',
            'login_demo_res_name' => 'RES104 (Dr. Le Hoang Nam)',
            'login_demo_res_cap' => 'Max 3 Nodes/Job • 1250 CR',
            'login_demo_admin_name' => 'ADMIN01 (HPC Cluster Admin)',
            'login_demo_admin_cap' => 'Max 3 Nodes/Job • 9999 CR',

            // Runtime Presets
            'preset_pytorch' => 'PyTorch 2.5 + CUDA 12.6',
            'preset_tensorflow' => 'TensorFlow 2.17 + CUDA 12.6',
            'preset_vllm' => 'vLLM OpenAI Serving Engine',
            'preset_ubuntu' => 'Ubuntu 24.04 C++/Rust/MPI Build',
            'preset_baremetal' => 'Bare Metal / Direct Hardware Checkout',

            // Flash & Controller Messages
            'flash_auth_failed' => 'Authentication failed.',
            'flash_auth_success' => 'Authenticated as %s (%s).',
            'flash_logout' => 'Session terminated.',
            'flash_student_hoarding' => 'Hoarding prevention policy: STUDENT accounts may only hold 1 active/scheduled lease across the system at any time. Terminate your current lease in the Console first.',
            'flash_node_removed' => 'Node removed from staging tray.',
            'flash_tray_cleared' => 'Staging tray cleared.',
            'flash_signin_before_prov' => 'Please sign in with your VKU credentials before provisioning hardware.',
            'flash_prov_failed' => 'Provisioning failed.',
            'flash_prov_success' => 'Allocation #%d provisioned atomically! Deducted %.2f CR across %d lease(s).',
            'flash_signin_console' => 'Sign in to access your active hardware console and SSH receipts.',
            'flash_ssh_invalid' => 'Invalid SSH public key format. Expected ssh-ed25519, ssh-rsa, or ecdsa-sha2-nistp*.',
            'flash_ssh_updated' => 'SSH public key updated on your profile.',
            'flash_term_failed' => 'Could not terminate lease.',
            'flash_term_success' => 'Lease #%d (%s) terminated early. Refunded %.2f CR (%d unused full hour(s)) to account balance.',
            'flash_admin_required' => 'Restricted area: ADMIN role required.',
            'flash_unauthorized' => 'Unauthorized.',
            'flash_invalid_node_status' => 'Invalid node ID or status value.',
            'flash_node_status_updated' => 'Node #%d status updated to %s.',
            'flash_node_host_ip_req' => 'Hostname and IP address are required to register a hardware node.',
            'flash_node_created' => 'Registered hardware node %s (#%d).',
            'flash_node_create_err' => 'Could not create node: ',
            'flash_topup_invalid' => 'Specify a valid user and non-zero credit delta.',
            'flash_topup_success' => 'Adjusted user #%d credit quota by %+.2f CR.',
            'flash_topup_err' => 'Credit adjustment failed: ',
            'err_404_heading' => '404 — Route Not Found',
            'err_404_back' => 'Return to VKU Node Matrix',
            'flash_google_unconfigured' => 'Google OAuth is not configured in this environment. Please sign in with your VKU credentials or use the Demo Quick-Fill accounts below.',
            'flash_google_cancelled' => 'Google sign-in was cancelled.',
            'flash_google_state_invalid' => 'Google OAuth session state expired or did not match. Please try signing in again.',
            'flash_auth_google_success' => 'Signed in via Google as %s (%s).',
        ],
        self::LANG_VI => [
            // Navbar & Layout
            'nav_node_matrix' => 'Ma trận Node',
            'nav_staging_tray' => 'Khay chờ',
            'nav_active_console' => 'Bảng điều khiển',
            'nav_cluster_admin' => 'Quản trị cụm',
            'nav_sign_in' => 'Đăng nhập',
            'nav_sign_out' => 'Đăng xuất',
            'nav_theme' => 'Giao diện',
            'lang_toggle_label' => 'EN',
            'lang_toggle_target' => 'en',
            'lang_toggle_title' => 'Switch to English',
            'brand_sub' => 'Hệ thống điều phối thuê phần cứng & HPC',
            'theme_toggle_title' => 'Chuyển đổi giao diện Sáng / Tối',
            'footer_text' => 'VKU’s Allocator — Trường Đại học Công nghệ Thông tin và Truyền thông Việt - Hàn',
            'footer_attribution' => 'A CREATION OF VORDENKER.WORK',
            'copied_btn' => 'Đã sao chép!',

            // Page Titles
            'page_title_matrix' => 'Ma trận Node — VKU Allocator',
            'page_title_tray' => 'Khay chờ cấu hình tác vụ — VKU Allocator',
            'page_title_console' => 'Bảng điều khiển — VKU Allocator',
            'page_title_admin' => 'Quản trị cụm — VKU Allocator',
            'page_title_login' => 'Đăng nhập — VKU Allocator',
            'page_title_404' => '404 Không tìm thấy trang — VKU Allocator',

            // Common Status & Role Badges
            'status_online_free' => 'KHẢ DỤNG',
            'status_busy_window' => 'ĐÃ ĐẶT TRONG KHUNG GIỜ',
            'status_online' => 'TRỰC TUYẾN',
            'status_maintenance' => 'BẢO TRÌ',
            'status_offline' => 'NGOẠI TUYẾN',
            'status_active' => 'ĐANG CHẠY',
            'status_scheduled' => 'ĐÃ LÊN LỊCH',
            'status_expired' => 'ĐÃ HẾT HẠN',
            'status_cancelled' => 'ĐÃ HỦY',
            'status_staged' => 'ĐÃ CHỌN',
            'role_student' => 'SINH VIÊN',
            'role_researcher' => 'NGHIÊN CỨU VIÊN',
            'role_admin' => 'QUẢN TRỊ',

            // Seeded Tier Names & Descriptions
            'tier_name_CUDA_COMPUTE' => 'Cụm Node Tính Toán CUDA',
            'tier_desc_CUDA_COMPUTE' => 'Hệ thống GPU NVIDIA Tensor Core dung lượng VRAM lớn phục vụ huấn luyện học sâu, tinh chỉnh mô hình và tác vụ CUDA.',
            'tier_name_CPU_CLUSTER' => 'Cụm CPU Biên Dịch & Mô Phỏng',
            'tier_desc_CPU_CLUSTER' => 'Các máy chủ AMD EPYC & Intel Xeon đa nhân phục vụ biên dịch phân tán, tích hợp CI và mô phỏng hiệu năng cao.',
            'tier_name_EDGE_AIOT' => 'Bộ Kit Phần Cứng Edge & AIoT',
            'tier_desc_EDGE_AIOT' => 'Các bộ máy tính nhúng vật lý (Jetson Orin, RISC-V, FPGA) hỗ trợ truy cập qua cổng nối tiếp và đường hầm SSH từ xa.',

            // Matrix View
            'matrix_title' => 'Ma trận Node Tính toán & Phần cứng Campus',
            'matrix_subtitle' => 'Theo dõi thông số cụm máy chủ trực tiếp, lọc theo khung giờ mục tiêu (Giới hạn đặt trước 24 giờ) hoặc dung lượng VRAM, và đưa phần cứng vào Khay chờ.',
            'policy_title' => 'Chính sách chống đầu cơ tài nguyên:',
            'policy_summary' => 'SINH VIÊN: tối đa 1 node/tác vụ & tối đa 1 phiên thuê đang chạy/đặt lịch toàn hệ thống • NGHIÊN CỨU VIÊN / QUẢN TRỊ: tối đa 3 node/tác vụ • Khung đặt lịch: Giới hạn đặt trước 24 giờ',
            'policy_active_role' => 'Vai trò hiện tại:',
            'policy_active_lease' => 'phiên thuê đang hoạt động',
            'policy_guest_prefix' => 'Đang xem với tư cách Khách —',
            'policy_guest_signin' => 'Đăng nhập',
            'policy_guest_suffix' => 'để sử dụng hạn mức Nghiên cứu viên / Quản trị',
            'pve_banner_title' => 'THÔNG SỐ TRỰC TIẾP HYPERVISOR PROXMOX VE',
            'pve_live_badge' => 'DỮ LIỆU TRỰC TIẾP',
            'pve_sim_badge' => 'THÔNG SỐ: MÔ PHỎNG (CẤU HÌNH .ENV ĐỂ KẾT NỐI PVE API THỰC)',
            'pve_host_label' => 'Máy chủ:',
            'pve_node_label' => 'Nút:',
            'pve_cpu_hw' => 'Phần cứng CPU',
            'pve_live_cpu' => 'Tải CPU trực tiếp',
            'pve_phys_ram' => 'RAM vật lý',
            'pve_load_uptime' => 'Tải TB & Thời gian chạy',
            'pve_running_vms' => 'Máy ảo QEMU đang chạy',
            'pve_vms_unit' => 'Máy ảo',
            'filter_reset_notice' => '(Đã đặt lại khung giờ xem trước về thời điểm hiện tại).',
            'filter_panel_title' => 'Bộ lọc Khung giờ mục tiêu & Cấu hình phần cứng',
            'filter_checking_window' => 'Đang kiểm tra khung giờ:',
            'filter_window_start' => 'Thời điểm bắt đầu (Giới hạn đặt trước 24 giờ)',
            'filter_duration_preview' => 'Thời lượng dự kiến (Giờ)',
            'filter_min_vram' => 'VRAM tối thiểu (GB)',
            'vram_opt_0' => 'Mọi mức VRAM (Bao gồm CPU)',
            'vram_opt_24' => 'Từ 24 GB+ (RTX 4090 / Orin)',
            'vram_opt_48' => 'Từ 48 GB+ (L40S / Orin 64)',
            'vram_opt_80' => '80 GB (A100 SXM4)',
            'filter_resource_tier' => 'Phân tầng tài nguyên',
            'filter_all_tiers' => 'Tất cả phân tầng tài nguyên',
            'filter_max_h' => 'Tối đa %d giờ',
            'filter_apply_btn' => 'Áp dụng khung giờ',
            'filter_reset_btn' => 'Đặt lại',
            'tier_code_label' => 'Mã phân tầng:',
            'tier_max_duration' => 'Thời lượng thuê tối đa:',
            'spec_accelerator' => 'Bộ tăng tốc / GPU',
            'spec_cpu_only' => 'Node thuần CPU',
            'spec_vram_ram' => 'VRAM / RAM hệ thống',
            'spec_cpu_threads' => 'Số luồng CPU',
            'spec_cores_unit' => '%d Nhân',
            'spec_burn_rate' => 'Đơn giá (Tối đa %dh)',
            'meter_cpu_load' => 'Tải CPU',
            'meter_system_ram' => 'RAM hệ thống',
            'meter_vram_alloc' => 'Phân bổ VRAM',
            'meter_na_cpu' => 'K/A (Node CPU)',
            'avail_horizon_label' => 'Lịch trống trong 24h tới',
            'avail_now_plus_24' => 'Hiện tại → +24h',
            'legend_free' => 'Trống',
            'legend_booked' => 'Đã đặt',
            'legend_maintenance' => 'Bảo trì',
            'reserved_until_prefix' => 'Đã được đặt trong khung giờ đến',
            'est_window_cost' => 'Chi phí dự kiến (%dh)',
            'btn_configure' => 'Cấu hình',
            'btn_stage_node' => '+ Chọn Node',
            'btn_unavailable' => 'Không khả dụng',
            'hours_input_title' => 'Số giờ (từ 1 đến %dh)',

            // Staging Tray View
            'tray_title' => 'Khay chờ cấu hình tác vụ',
            'tray_subtitle' => 'Cấu hình môi trường thực thi, thời gian bắt đầu tác vụ (Giới hạn đặt trước 24 giờ), thời lượng từng node và xác nhận cấp phát nguyên tử.',
            'tray_add_more' => '← Thêm Node khác',
            'tray_clear' => 'Xóa khay chờ',
            'tray_empty_title' => 'Khay chờ cấu hình tác vụ đang trống',
            'tray_empty_desc' => 'Hãy chọn một hoặc nhiều node phần cứng đang trực tuyến từ Ma trận Node để cấu hình tác vụ tính toán.',
            'tray_browse_btn' => 'Mở Ma trận Node',
            'tray_sec1_title' => '1. Các Node tính toán đã chọn (%d / %d tối đa cho %s)',
            'th_node_hostname' => 'Tên máy chủ Node',
            'th_tier_cap' => 'Phân tầng & Giới hạn',
            'th_rate' => 'Đơn giá',
            'th_hours' => 'Số giờ',
            'th_window_end' => 'Kết thúc lúc',
            'th_subtotal' => 'Thành tiền',
            'tray_core_cpu' => 'CPU %d nhân',
            'tray_remove_title' => 'Xóa node khỏi khay chờ',
            'tray_recalc_btn' => 'Tính lại & Lưu thay đổi khay chờ',
            'tray_sec2_title' => '2. Môi trường thực thi & Tham số lập lịch',
            'tray_project_label' => 'Nhãn tác vụ / Tên dự án',
            'tray_start_label' => 'Thời gian bắt đầu chung (Giới hạn đặt trước 24 giờ)',
            'tray_preset_label' => 'Cấu hình Container / Môi trường thực thi',
            'tray_script_label' => 'Kịch bản khởi chạy tùy chọn / Ghi chú tác vụ',
            'tray_ssh_label' => 'Khóa công khai SSH (ssh-ed25519 / ssh-rsa / ecdsa) — Bắt buộc để cấp phát Node',
            'tray_ssh_hint' => 'Được kiểm tra bằng biểu thức chính quy OpenSSH nghiêm ngặt trước khi thực thi giao dịch SQL nguyên tử.',
            'tray_sec3_title' => '3. Tổng kết chi phí giao dịch',
            'tray_sum_nodes' => 'Số Node đã chọn',
            'tray_sum_start' => 'Thời điểm bắt đầu',
            'tray_sum_horizon' => 'Khung thời gian đặt',
            'tray_sum_horizon_valid' => 'Hợp lệ (Trong vòng 24 giờ)',
            'tray_sum_balance' => 'Số dư hiện tại',
            'tray_sum_burn' => 'Tổng tín chỉ tiêu thụ',
            'tray_sum_projected' => 'Số dư dự kiến sau cấp phát',
            'tray_hoarding_warn' => 'Giới hạn chống đầu cơ: Tài khoản %s của bạn đã có %d phiên thuê đang chạy/đặt lịch. Vui lòng kết thúc phiên đó trong Bảng điều khiển trước.',
            'tray_provision_btn' => 'Cấp phát tác vụ nguyên tử →',
            'tray_auth_required' => 'Cần đăng nhập để xác minh hạn mức Tín chỉ tính toán và khóa công khai SSH của bạn.',
            'tray_signin_btn' => 'Đăng nhập để cấp phát tác vụ →',
            'tray_acid_title' => 'Đảm bảo giao dịch ACID:',
            'tray_acid_1' => '1. Khóa dòng <code>FOR UPDATE</code> trên tài khoản &amp; các node mục tiêu.',
            'tray_acid_2' => '2. Đảm bảo không trùng lịch thuê trong đoạn <code>[start_time, end_time]</code>.',
            'tray_acid_3' => '3. Khấu trừ tín chỉ &amp; cấp cổng SSH tạm thời (20000&ndash;60000).',

            // Console View
            'console_title' => 'Bảng điều khiển tính toán & Biên lai thuê phần cứng',
            'console_subtitle' => 'Kết nối qua đường hầm SSH tạm thời, theo dõi đếm ngược thời gian thuê trực tiếp, hoặc kết thúc sớm để được hoàn trả tín chỉ tự động theo số giờ nguyên chưa dùng.',
            'console_new_hw_btn' => '+ Cấp phát phần cứng mới',
            'console_active_panel' => 'Các phiên thuê phần cứng đang chạy & Đã lên lịch (%d)',
            'console_server_clock' => 'Đồng hồ máy chủ:',
            'console_empty_prefix' => 'Hiện không có phiên thuê nào đang chạy hoặc đã lên lịch. Hãy truy cập',
            'console_empty_suffix' => 'để chọn phần cứng.',
            'console_job_prefix' => 'Tác vụ',
            'console_rem_window' => 'Thời gian thuê còn lại',
            'console_starts_in_label' => 'Bắt đầu sau / Khung giờ',
            'console_calculating' => 'Đang tính...',
            'console_copy_ssh' => 'Sao chép SSH',
            'console_spec_image' => 'Ảnh Container môi trường',
            'console_spec_window' => 'Khung thời gian thuê',
            'console_spec_port' => 'Cổng tạm thời / IP Node',
            'console_spec_port_val' => 'Cổng %d → %s:22',
            'console_spec_token' => 'Mã truy cập (32 ký tự Hex)',
            'console_startup_script' => 'KỊCH BẢN KHỞI CHẠY:',
            'console_shell_title' => 'Thiết bị đầu cuối Web vor-compute (CT 200)',
            'console_shell_sub' => '• Phiên Alpine tạm thời bảo vệ bằng Token',
            'console_launch_shell' => 'Mở Terminal Web',
            'console_hide_shell' => 'Ẩn Terminal Web',
            'console_pop_out' => 'Mở tab mới ↗',
            'console_locked_rate' => 'Đơn giá cố định:',
            'console_early_refund' => 'Hoàn trả khi kết thúc sớm:',
            'console_unused_hr' => 'giờ nguyên chưa dùng',
            'console_terminate_btn' => 'Kết thúc thuê & Hoàn %s CR',
            'console_terminate_confirm' => 'Kết thúc sớm phiên thuê #%d trên %s và hoàn lại %s CR?',
            'console_history_title' => 'Lịch sử kiểm toán phiên thuê (%d)',
            'console_history_empty' => 'Chưa có phiên thuê nào hết hạn hoặc bị hủy.',
            'th_lease' => 'Phiên thuê',
            'th_node_project' => 'Node / Dự án',
            'th_window' => 'Khung giờ',
            'th_status' => 'Trạng thái',
            'th_refunded' => 'Đã hoàn trả',
            'console_ssh_profile_title' => 'Hồ sơ khóa công khai SSH',
            'console_ssh_profile_label' => 'Khóa OpenSSH được ủy quyền (ed25519 / rsa / ecdsa)',
            'console_ssh_update_btn' => 'Cập nhật khóa công khai SSH',
            'js_starts_in' => 'Bắt đầu sau ',
            'js_remaining' => ' còn lại',
            'js_window_expired' => 'Đã hết thời gian',

            // Admin View
            'admin_title' => 'Bảng điều khiển Quản trị & Điều phối Cụm HPC',
            'admin_subtitle' => 'Quản lý trạng thái vận hành các node tính toán, đăng ký node phần cứng mới, điều chỉnh hạn mức tín chỉ sinh viên và kiểm toán toàn bộ phiên thuê.',
            'admin_sec1_title' => '1. Điều chỉnh trạng thái đội ngũ Node phần cứng (%d Node)',
            'th_id' => 'ID',
            'th_hostname_ip' => 'Tên máy chủ / IP',
            'th_tier_specs' => 'Phân tầng & Cấu hình',
            'th_state' => 'Trạng thái',
            'th_set_state' => 'Đổi trạng thái',
            'btn_apply' => 'Áp dụng',
            'admin_cpu_only_short' => 'Thuần CPU',
            'admin_sec2_title' => '2. Nạp thêm / Điều chỉnh tín chỉ người dùng',
            'admin_select_user' => 'Chọn tài khoản Sinh viên / Nghiên cứu viên',
            'admin_credit_delta' => 'Mức thay đổi tín chỉ (+ để nạp thêm, - để khấu trừ)',
            'admin_commit_credit_btn' => 'Xác nhận điều chỉnh hạn mức tín chỉ',
            'admin_sec3_title' => '3. Đăng ký Node phần cứng mới',
            'admin_hostname_label' => 'Tên máy chủ (Duy nhất)',
            'admin_ip_label' => 'Địa chỉ IP nội bộ',
            'admin_gpu_label' => 'Mẫu GPU / Bộ tăng tốc (Để trống nếu là node CPU)',
            'admin_vram_label' => 'VRAM (GB)',
            'admin_ram_label' => 'RAM hệ thống (GB)',
            'admin_cores_label' => 'Số nhân CPU',
            'admin_rate_label' => 'Đơn giá (CR/giờ)',
            'admin_create_node_btn' => '+ Thêm Node phần cứng vào hệ thống',
            'admin_sec4_title' => '4. Sổ cái phiên thuê toàn cụm (%d phiên thuê gần nhất)',
            'th_user' => 'Người dùng',
            'th_node_tier' => 'Node / Phân tầng',
            'th_project' => 'Dự án',
            'th_time_window' => 'Khung thời gian',
            'th_port' => 'Cổng',
            'th_rate_refund' => 'Đơn giá / Hoàn trả',
            'th_admin_action' => 'Thao tác Quản trị',
            'admin_refund_prefix' => 'Hoàn trả:',
            'admin_force_terminate' => 'Buộc kết thúc',
            'admin_force_confirm' => 'Quản trị viên buộc kết thúc phiên thuê #%d và hoàn trả số giờ nguyên chưa dùng?',

            // Login View
            'login_title' => 'Xác thực định danh VKU',
            'login_badge' => 'Bảo mật Argon2id',
            'login_desc' => 'Đăng nhập bằng Mã sinh viên / Mã cán bộ VKU hoặc địa chỉ email `@vku.udn.vn` để chọn và cấp phát phần cứng cụm máy chủ.',
            'login_id_label' => 'Mã sinh viên / Mã cán bộ hoặc Email VKU',
            'login_id_placeholder' => '22IT001 hoặc an.nv.22it@vku.udn.vn',
            'login_pw_label' => 'Mật khẩu',
            'login_submit_btn' => 'Xác thực đăng nhập →',
            'login_or_divider' => 'HOẶC',
            'login_google_btn' => 'Tiếp tục với Google',
            'login_demo_title' => 'Tài khoản Demo điền nhanh (Bảo vệ / Chấm điểm)',
            'login_demo_hint' => 'Nhấn vào bất kỳ vai trò nào bên dưới để tự động điền thông tin (Mật khẩu:',
            'login_demo_student_name' => '22IT001 (Nguyễn Văn An)',
            'login_demo_student_cap' => 'Tối đa 1 Node/Tác vụ • 240 CR',
            'login_demo_res_name' => 'RES104 (TS. Lê Hoàng Nam)',
            'login_demo_res_cap' => 'Tối đa 3 Node/Tác vụ • 1250 CR',
            'login_demo_admin_name' => 'ADMIN01 (Quản trị viên Cụm HPC)',
            'login_demo_admin_cap' => 'Tối đa 3 Node/Tác vụ • 9999 CR',

            // Runtime Presets
            'preset_pytorch' => 'PyTorch 2.5 + CUDA 12.6 (Học sâu GPU)',
            'preset_tensorflow' => 'TensorFlow 2.17 + CUDA 12.6 (Học sâu GPU)',
            'preset_vllm' => 'vLLM OpenAI Serving Engine (Suy luận LLM)',
            'preset_ubuntu' => 'Ubuntu 24.04 Biên dịch C++/Rust/MPI',
            'preset_baremetal' => 'Bare Metal / Cấp phát phần cứng trực tiếp',

            // Flash & Controller Messages
            'flash_auth_failed' => 'Xác thực thất bại. Vui lòng kiểm tra lại thông tin đăng nhập.',
            'flash_auth_success' => 'Đã đăng nhập với tài khoản %s (%s).',
            'flash_logout' => 'Đã kết thúc phiên đăng nhập.',
            'flash_student_hoarding' => 'Chính sách chống đầu cơ: Tài khoản SINH VIÊN chỉ được giữ tối đa 1 phiên thuê đang chạy/đặt lịch trên toàn hệ thống. Vui lòng kết thúc phiên hiện tại ở Bảng điều khiển trước.',
            'flash_node_removed' => 'Đã xóa Node khỏi khay chờ.',
            'flash_tray_cleared' => 'Đã làm trống khay chờ.',
            'flash_signin_before_prov' => 'Vui lòng đăng nhập bằng tài khoản VKU trước khi cấp phát phần cứng.',
            'flash_prov_failed' => 'Cấp phát tác vụ thất bại.',
            'flash_prov_success' => 'Đã cấp phát nguyên tử tác vụ #%d! Đã khấu trừ %.2f CR cho %d phiên thuê.',
            'flash_signin_console' => 'Vui lòng đăng nhập để xem bảng điều khiển phần cứng và biên lai kết nối SSH.',
            'flash_ssh_invalid' => 'Định dạng khóa công khai SSH không hợp lệ. Yêu cầu ssh-ed25519, ssh-rsa, hoặc ecdsa-sha2-nistp*.',
            'flash_ssh_updated' => 'Đã cập nhật khóa công khai SSH trong hồ sơ của bạn.',
            'flash_term_failed' => 'Không thể kết thúc phiên thuê.',
            'flash_term_success' => 'Đã kết thúc sớm phiên thuê #%d (%s). Đã hoàn trả %.2f CR (%d giờ nguyên chưa sử dụng) vào số dư tài khoản.',
            'flash_admin_required' => 'Khu vực giới hạn: Yêu cầu quyền QUẢN TRỊ (ADMIN).',
            'flash_unauthorized' => 'Không có quyền thực hiện thao tác này.',
            'flash_invalid_node_status' => 'ID Node hoặc trạng thái không hợp lệ.',
            'flash_node_status_updated' => 'Đã cập nhật trạng thái Node #%d thành %s.',
            'flash_node_host_ip_req' => 'Tên máy chủ và địa chỉ IP là bắt buộc để đăng ký node phần cứng.',
            'flash_node_created' => 'Đã đăng ký node phần cứng %s (#%d).',
            'flash_node_create_err' => 'Không thể tạo node: ',
            'flash_topup_invalid' => 'Vui lòng chọn người dùng hợp lệ và mức thay đổi tín chỉ khác 0.',
            'flash_topup_success' => 'Đã điều chỉnh hạn mức tín chỉ của người dùng #%d thêm %+.2f CR.',
            'flash_topup_err' => 'Điều chỉnh tín chỉ thất bại: ',
            'err_404_heading' => '404 — Không tìm thấy đường dẫn',
            'err_404_back' => 'Quay lại Ma trận Node VKU',
            'flash_google_unconfigured' => 'Google OAuth chưa được cấu hình trên môi trường này. Vui lòng đăng nhập bằng tài khoản VKU hoặc dùng tài khoản Demo bên dưới.',
            'flash_google_cancelled' => 'Đã hủy đăng nhập qua Google.',
            'flash_google_state_invalid' => 'Phiên xác thực Google đã hết hạn hoặc không khớp. Vui lòng thử lại.',
            'flash_auth_google_success' => 'Đã đăng nhập qua Google với mã %s (%s).',
        ],
    ];

    public static function normalize(?string $lang): string
    {
        if ($lang === null) {
            return self::LANG_EN;
        }
        $normalized = strtolower(trim($lang));
        return in_array($normalized, self::SUPPORTED_LANGUAGES, true) ? $normalized : self::LANG_EN;
    }

    public static function resolve(?string $getLang, ?string $cookieLang, ?string $sessionLang): string
    {
        if ($getLang !== null) {
            $norm = strtolower(trim($getLang));
            if (in_array($norm, self::SUPPORTED_LANGUAGES, true)) {
                return $norm;
            }
        }

        if ($cookieLang !== null) {
            $norm = strtolower(trim($cookieLang));
            if (in_array($norm, self::SUPPORTED_LANGUAGES, true)) {
                return $norm;
            }
        }

        if ($sessionLang !== null) {
            $norm = strtolower(trim($sessionLang));
            if (in_array($norm, self::SUPPORTED_LANGUAGES, true)) {
                return $norm;
            }
        }

        return self::LANG_EN;
    }

    public static function get(string $key, ?string $lang = null): string
    {
        $lang = self::normalize($lang);
        return self::$messages[$lang][$key] ?? self::$messages[self::LANG_EN][$key] ?? $key;
    }

    /**
     * Formats a localized string using vsprintf if arguments are passed.
     *
     * @param list<scalar> $args
     */
    public static function format(string $key, ?string $lang = null, array $args = []): string
    {
        $template = self::get($key, $lang);
        if ($args === []) {
            return $template;
        }
        return vsprintf($template, $args);
    }

    public static function translateStatus(string $status, ?string $lang = null): string
    {
        $map = [
            'AVAILABLE' => 'status_online_free',
            'ONLINE // FREE' => 'status_online_free',
            'BUSY IN WINDOW' => 'status_busy_window',
            'ONLINE' => 'status_online',
            'MAINTENANCE' => 'status_maintenance',
            'OFFLINE' => 'status_offline',
            'ACTIVE' => 'status_active',
            'SCHEDULED' => 'status_scheduled',
            'EXPIRED' => 'status_expired',
            'CANCELLED' => 'status_cancelled',
            'STAGED' => 'status_staged',
        ];
        $upper = strtoupper(trim($status));
        if (isset($map[$upper])) {
            return self::get($map[$upper], $lang);
        }
        return $status;
    }

    public static function translateRole(string $role, ?string $lang = null): string
    {
        $map = [
            'STUDENT' => 'role_student',
            'RESEARCHER' => 'role_researcher',
            'ADMIN' => 'role_admin',
        ];
        $upper = strtoupper(trim($role));
        if (isset($map[$upper])) {
            return self::get($map[$upper], $lang);
        }
        return $role;
    }

    public static function translateTierName(string $tierCodeOrName, string $fallbackName, ?string $lang = null): string
    {
        $codeKey = 'tier_name_' . strtoupper(trim($tierCodeOrName));
        if (isset(self::$messages[self::LANG_EN][$codeKey])) {
            return self::get($codeKey, $lang);
        }
        foreach (['CUDA_COMPUTE', 'CPU_CLUSTER', 'EDGE_AIOT'] as $code) {
            if (strcasecmp(self::$messages[self::LANG_EN]['tier_name_' . $code], trim($fallbackName)) === 0) {
                return self::get('tier_name_' . $code, $lang);
            }
        }
        return $fallbackName;
    }

    public static function translateTierDescription(string $tierCode, string $fallbackDesc, ?string $lang = null): string
    {
        $codeKey = 'tier_desc_' . strtoupper(trim($tierCode));
        if (isset(self::$messages[self::LANG_EN][$codeKey])) {
            return self::get($codeKey, $lang);
        }
        return $fallbackDesc;
    }

    public static function translateTelemetrySource(string $source, ?string $lang = null): string
    {
        if (str_contains($source, 'LIVE PVE API')) {
            return self::get('pve_live_badge', $lang);
        }
        if (str_contains($source, 'SIMULATED')) {
            return self::get('pve_sim_badge', $lang);
        }
        return $source;
    }

    public static function translateTooltip(string $tooltip, ?string $lang = null): string
    {
        if (self::normalize($lang) !== self::LANG_VI) {
            return $tooltip;
        }
        return str_replace(
            [': FREE', ': BOOKED', ': MAINTENANCE'],
            [': TRỐNG', ': ĐÃ ĐẶT', ': BẢO TRÌ'],
            $tooltip
        );
    }

    public static function translateServiceMessage(string $message, ?string $lang = null): string
    {
        if (self::normalize($lang) !== self::LANG_VI) {
            return $message;
        }

        if (preg_match('/^Staged (.+) for (\d+) hour\(s\) into Job Spec Tray\.$/', $message, $m)) {
            return sprintf('Đã thêm %s (%d giờ) vào Khay chờ cấu hình tác vụ.', $m[1], (int) $m[2]);
        }
        if ($message === 'Job configuration updated.') {
            return 'Đã cập nhật cấu hình tác vụ.';
        }
        if ($message === 'Selected compute node does not exist.') {
            return 'Node tính toán đã chọn không tồn tại.';
        }
        if (preg_match('/^Node (.+) is currently in (.+) mode and cannot be staged\.$/', $message, $m)) {
            return sprintf('Node %s hiện đang ở trạng thái %s và không thể chọn.', $m[1], self::translateStatus($m[2], $lang));
        }
        if (preg_match('/^Duration for (.+) \((.+)\) must be between 1 and (\d+) hours/', $message, $m)) {
            return sprintf('Thời lượng thuê cho %s (%s) phải từ 1 đến %d giờ.', $m[1], $m[2], (int) $m[3]);
        }
        if (preg_match('/^Node (.+) \((.+)\) duration must be between 1 and (\d+) hours\.$/', $message, $m)) {
            return sprintf('Thời lượng thuê của Node %s (%s) phải từ 1 đến %d giờ.', $m[1], $m[2], (int) $m[3]);
        }
        if (preg_match('/^Anti-hoarding policy: (.+) accounts can stage at most (\d+) node\(s\) per job/', $message, $m)) {
            return sprintf('Chính sách chống đầu cơ: Tài khoản %s chỉ được chọn tối đa %d node mỗi tác vụ.', self::translateRole($m[1], $lang), (int) $m[2]);
        }
        if (preg_match('/^Hoarding prevention policy: (.+) accounts can lease at most (\d+) node\(s\) per job/', $message, $m)) {
            return sprintf('Chính sách chống đầu cơ: Tài khoản %s chỉ được thuê tối đa %d node mỗi tác vụ.', self::translateRole($m[1], $lang), (int) $m[2]);
        }
        if (preg_match('/^Hoarding prevention policy: (.+) accounts may hold at most (\d+) active\/scheduled lease/', $message, $m)) {
            return sprintf('Chính sách chống đầu cơ: Tài khoản %s chỉ được giữ tối đa %d phiên thuê đang chạy/đặt lịch trên toàn hệ thống. Vui lòng kết thúc phiên hiện tại trước.', self::translateRole($m[1], $lang), (int) $m[2]);
        }
        if (preg_match('/^Schedule conflict: Node (.+) is already booked during (.+) → (.+)\.$/', $message, $m)) {
            return sprintf('Trùng lịch thuê: Node %s đã được đặt trong khoảng %s → %s.', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/^Insufficient Compute Credits: Job requires ([0-9.]+) CR, but your available balance is ([0-9.]+) CR\.$/', $message, $m)) {
            return sprintf('Không đủ Tín chỉ tính toán: Tác vụ cần %s CR nhưng số dư khả dụng của bạn là %s CR.', $m[1], $m[2]);
        }
        if ($message === 'Job start time cannot be in the past.') {
            return 'Thời gian bắt đầu tác vụ không được nằm trong quá khứ.';
        }
        if (str_starts_with($message, 'Booking horizon exceeded:')) {
            return 'Vượt quá giới hạn đặt lịch: Tác vụ chỉ có thể được đặt trước trong vòng 24 giờ tới.';
        }
        if (str_starts_with($message, 'Valid OpenSSH public key')) {
            return 'Cần có khóa công khai OpenSSH hợp lệ (ssh-ed25519, ssh-rsa, hoặc ecdsa-sha2-nistp*) để cấp phát phần cứng.';
        }
        if ($message === 'Invalid credentials or unknown VKU account.') {
            return 'Thông tin đăng nhập không chính xác hoặc tài khoản VKU không tồn tại.';
        }
        if ($message === 'Please enter both your VKU identifier and password.') {
            return 'Vui lòng nhập đầy đủ mã định danh VKU và mật khẩu.';
        }

        return $message;
    }

    /**
     * @return array<string, string>
     */
    public static function all(?string $lang = null): array
    {
        $lang = self::normalize($lang);
        return self::$messages[$lang] ?? self::$messages[self::LANG_EN];
    }

    public static function sanitizeReturnTo(string $returnTo, ?string $currentHost = null, ?string $referer = null): string
    {
        $raw = trim($returnTo);
        if ($raw === '' && $referer !== null && $referer !== '') {
            $refHost = parse_url($referer, PHP_URL_HOST);
            $currHostOnly = $currentHost !== null ? explode(':', $currentHost)[0] : '';
            $refHostOnly = $refHost ? explode(':', $refHost)[0] : '';
            if ($refHost === null || $refHostOnly === '' || ($currHostOnly !== '' && strtolower($refHostOnly) === strtolower($currHostOnly))) {
                $refPath = parse_url($referer, PHP_URL_PATH) ?: '/';
                $refQuery = parse_url($referer, PHP_URL_QUERY);
                $refFrag = parse_url($referer, PHP_URL_FRAGMENT);
                $raw = $refPath . ($refQuery !== null ? '?' . $refQuery : '') . ($refFrag !== null ? '#' . $refFrag : '');
            }
        }

        if ($raw === '') {
            return '/';
        }

        $raw = str_replace(["\r", "\n", "\0"], '', $raw);
        $isSafe = str_starts_with($raw, '/') && !str_starts_with($raw, '//') && !str_starts_with($raw, '/\\');
        if (!$isSafe) {
            return '/';
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if (in_array($path, ['/lang', '/set-lang', '/locale'], true)) {
            return '/';
        }

        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return '/';
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryParams);
            unset($queryParams['lang']);
            $query = http_build_query($queryParams);
            $cleanUrl = $path . ($query !== '' ? '?' . $query : '');
        } else {
            $cleanUrl = $path;
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $cleanUrl .= '#' . $parts['fragment'];
        }

        return $cleanUrl;
    }
}
