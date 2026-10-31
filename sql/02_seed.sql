-- =============================================================================
-- VKU's Allocator — Initial Seed Data (Tiers, Nodes, Demo Accounts)
-- Default Password for all demo accounts: vku2026
-- =============================================================================

USE vku_allocator;
SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- 1. Seed Resource Tiers
INSERT INTO resource_tiers (id, code, name, description, max_allocation_hours) VALUES
(1, 'CUDA_COMPUTE', 'CUDA Compute Nodes', 'High-VRAM NVIDIA Tensor Core GPUs for deep learning training, fine-tuning, and CUDA workloads.', 6),
(2, 'CPU_CLUSTER', 'CPU Build & Simulation Cluster', 'High-core-count AMD EPYC & Intel Xeon nodes for distributed compilation, CI builds, and simulations.', 12),
(3, 'EDGE_AIOT', 'Edge & AIoT Hardware Kits', 'Physical embedded compute kits (Jetson Orin, RISC-V, FPGA) with remote serial & SSH tunnel access.', 24);

-- 2. Seed Compute Nodes
INSERT INTO compute_nodes (id, tier_id, hostname, ip_address, gpu_model, vram_gb, ram_gb, cpu_cores, credits_per_hour, status) VALUES
(1, 1, 'vku-hgx-a100-01', '10.20.10.11', 'NVIDIA A100-SXM4 80GB', 80, 256, 64, 45.00, 'ONLINE'),
(2, 1, 'vku-hgx-a100-02', '10.20.10.12', 'NVIDIA A100-SXM4 80GB', 80, 256, 64, 45.00, 'ONLINE'),
(3, 1, 'vku-rtx4090-01', '10.20.10.21', 'NVIDIA GeForce RTX 4090', 24, 128, 32, 20.00, 'ONLINE'),
(4, 1, 'vku-rtx4090-02', '10.20.10.22', 'NVIDIA GeForce RTX 4090', 24, 128, 32, 20.00, 'MAINTENANCE'),
(5, 1, 'vku-l40s-01', '10.20.10.31', 'NVIDIA L40S 48GB', 48, 192, 48, 32.00, 'ONLINE'),
(6, 2, 'vku-epyc-build-01', '10.20.20.11', NULL, 0, 256, 128, 12.00, 'ONLINE'),
(7, 2, 'vku-epyc-build-02', '10.20.20.12', NULL, 0, 128, 64, 8.00, 'ONLINE'),
(8, 3, 'vku-jetson-orin-01', '10.20.30.11', 'NVIDIA Ampere Orin 64GB (Unified)', 64, 64, 12, 6.00, 'ONLINE'),
(9, 3, 'vku-jetson-orin-02', '10.20.30.12', 'NVIDIA Ampere Orin 32GB (Unified)', 32, 32, 8, 4.00, 'ONLINE'),
(10, 2, 'vor-server', '192.168.1.200', 'Intel Core i5-3470 (4C/4T @ 3.20GHz) • Proxmox VE Host', 0, 16, 4, 5.00, 'ONLINE');

-- 3. Seed Users
-- Password for all seeded accounts is: vku2026
-- Hash below is Argon2id generated for 'vku2026'
INSERT INTO users (id, student_code, full_name, email, password_hash, credit_balance, ssh_public_key, role) VALUES
(1, '22IT001', 'Nguyen Van An (Student)', 'an.nv.22it@vku.udn.vn', '$argon2id$v=19$m=65536,t=4,p=1$VktVQWxsb2NhdG9yU2FsdA$7cK0zR8mJ7P5qL2xW9vN1yB4tF6hD3gS8kM0pQ2wE4r', 240.00, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl an.nv.22it@vku.udn.vn', 'STUDENT'),
(2, '22IT089', 'Tran Thi Minh (Student)', 'minh.tt.22it@vku.udn.vn', '$argon2id$v=19$m=65536,t=4,p=1$VktVQWxsb2NhdG9yU2FsdA$7cK0zR8mJ7P5qL2xW9vN1yB4tF6hD3gS8kM0pQ2wE4r', 180.00, NULL, 'STUDENT'),
(3, 'RES104', 'Dr. Le Hoang Nam (AI Lab)', 'namlh@vku.udn.vn', '$argon2id$v=19$m=65536,t=4,p=1$VktVQWxsb2NhdG9yU2FsdA$7cK0zR8mJ7P5qL2xW9vN1yB4tF6hD3gS8kM0pQ2wE4r', 1250.00, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJb8xPqW2mK9vL4nR7tY1uI3oP5aS6dF8gH0jK2lZ9xC namlh@vku.udn.vn', 'RESEARCHER'),
(4, 'ADMIN01', 'VKU HPC Cluster Admin', 'hpc-admin@vku.udn.vn', '$argon2id$v=19$m=65536,t=4,p=1$VktVQWxsb2NhdG9yU2FsdA$7cK0zR8mJ7P5qL2xW9vN1yB4tF6hD3gS8kM0pQ2wE4r', 9999.00, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKm9nB4vC7xZ2lK8jH5gF3dS1aP0oI9uY7tR5eW3qA1z hpc-admin@vku.udn.vn', 'ADMIN');

-- 4. Seed Sample Active Allocation (Researcher running a benchmark on vku-hgx-a100-02)
INSERT INTO allocations (id, user_id, project_name, docker_image, startup_script, total_credits_cost, created_at) VALUES
(1, 3, 'vietnamese-vlm-finetune', 'nvcr.io/nvidia/pytorch:24.10-py3 (PyTorch 2.5 + CUDA 12.6)', 'export WANDB_MODE=offline\ntorchrun --nproc_per_node=1 train_vlm.py --fp16', 180.00, DATE_SUB(NOW(), INTERVAL 1 HOUR));

INSERT INTO allocation_leases (id, allocation_id, node_id, start_time, end_time, hourly_rate_snapshot, refunded_credits, assigned_port, access_token, lease_status) VALUES
(1, 1, 2, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 3 HOUR), 45.00, 0.00, 42189, '9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c', 'ACTIVE');
