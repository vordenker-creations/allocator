-- =============================================================================
-- VKU's Allocator — 3NF Relational Schema (MySQL 8.4)
-- Module: Advanced Web Technology
-- =============================================================================

CREATE DATABASE IF NOT EXISTS vku_allocator
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE vku_allocator;

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- 1. Resource Tiers (e.g. CUDA Compute, CPU Build Cluster, Edge & AIoT Kits)
CREATE TABLE IF NOT EXISTS resource_tiers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    max_allocation_hours INT UNSIGNED NOT NULL DEFAULT 6,
    CONSTRAINT chk_max_hours CHECK (max_allocation_hours >= 1 AND max_allocation_hours <= 168)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Compute Nodes (1-to-Many with resource_tiers)
CREATE TABLE IF NOT EXISTS compute_nodes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tier_id INT UNSIGNED NOT NULL,
    hostname VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NOT NULL,
    gpu_model VARCHAR(100) NULL,
    vram_gb INT UNSIGNED NOT NULL DEFAULT 0,
    ram_gb INT UNSIGNED NOT NULL,
    cpu_cores INT UNSIGNED NOT NULL DEFAULT 8,
    credits_per_hour DECIMAL(10, 2) NOT NULL,
    status ENUM('ONLINE', 'MAINTENANCE', 'OFFLINE') NOT NULL DEFAULT 'ONLINE',
    CONSTRAINT fk_nodes_tier FOREIGN KEY (tier_id)
        REFERENCES resource_tiers(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT chk_positive_rate CHECK (credits_per_hour >= 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Users (Students, Researchers, Admins)
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_code VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    credit_balance DECIMAL(10, 2) NOT NULL DEFAULT 500.00,
    ssh_public_key TEXT NULL,
    role ENUM('STUDENT', 'RESEARCHER', 'ADMIN') NOT NULL DEFAULT 'STUDENT',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_non_negative_balance CHECK (credit_balance >= 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Allocations (Job Header — equivalent to Order Header)
CREATE TABLE IF NOT EXISTS allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project_name VARCHAR(100) NOT NULL,
    docker_image VARCHAR(120) NOT NULL,
    startup_script TEXT NULL,
    total_credits_cost DECIMAL(10, 2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_allocations_user FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Allocation Leases (Job Line Items / Individual Node Time-Window Leases)
CREATE TABLE IF NOT EXISTS allocation_leases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allocation_id INT UNSIGNED NOT NULL,
    node_id INT UNSIGNED NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    hourly_rate_snapshot DECIMAL(10, 2) NOT NULL,
    refunded_credits DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    assigned_port INT UNSIGNED NOT NULL,
    access_token CHAR(32) NOT NULL,
    lease_status ENUM('SCHEDULED', 'ACTIVE', 'EXPIRED', 'CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
    terminated_at DATETIME NULL,
    CONSTRAINT fk_leases_allocation FOREIGN KEY (allocation_id)
        REFERENCES allocations(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_leases_node FOREIGN KEY (node_id)
        REFERENCES compute_nodes(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT chk_lease_window CHECK (end_time > start_time),
    CONSTRAINT chk_ephemeral_port CHECK (assigned_port BETWEEN 20000 AND 60000),
    INDEX idx_node_window (node_id, start_time, end_time),
    INDEX idx_node_status_window (node_id, lease_status, start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
