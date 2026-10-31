# VKU’s Allocator — Campus Compute & Hardware Lease Scheduler

A high-performance compute (HPC) and hardware node scheduling application built for the **Advanced Web Technology** module at **Vietnam-Korea University of Information and Communication Technology (VKU)**.

## Technical Stack
- **Backend**: PHP 8.4 (`declare(strict_types=1)`, PSR-4 autoloading `VKU\Allocator\`, Backed Enums, `readonly` classes, `match()` routing, `DateTimeImmutable`)
- **Database**: MySQL 8.4 (3NF normalized relational schema with composite scheduling indexes and ACID row-level locking via `SELECT ... FOR UPDATE`)
- **Frontend**: Minimalist Warm White (`#FAF8F5`) & Soft Black (`#141413`) interface with instant Light/Dark theme toggle

---

## Quick Start (Docker Compose)

```bash
# 1. Start the MySQL 8.4 and PHP 8.4 containers
docker compose up -d --build

# 2. Generate Composer PSR-4 autoloader
docker compose exec app composer dump-autoload -o

# 3. Run the automated invariant & transaction test suite
docker compose exec app php tests/verify_workflow.php
```

- **Web Application**: [http://localhost:8080](http://localhost:8080)
- **Reset Database from Scratch**:
  ```bash
  docker compose down -v && docker compose up -d
  ```

---

## DBeaver Connection Details

Connect **DBeaver** (or MySQL Workbench) to inspect the 5 normalized tables and generate ER diagrams:

| Parameter | Value |
| :--- | :--- |
| **Host** | `127.0.0.1` |
| **Port** | `3306` |
| **Database** | `vku_allocator` |
| **Username** | `vku_user` (or `root`) |
| **Password** | `vku_password` (or `vku_root_secret` for root) |

---

## Demo Accounts (Password: `vku2026`)

| Role | Student Code / ID | Email | Quotas & Hoarding Limits | Initial Credits |
| :--- | :--- | :--- | :--- | :--- |
| **STUDENT** | `22IT001` | `an.nv.22it@vku.udn.vn` | Max **1 node/job** &bull; Max **1 active/scheduled lease** system-wide | `240.00 CR` |
| **STUDENT** | `22IT089` | `minh.tt.22it@vku.udn.vn` | Max **1 node/job** &bull; Max **1 active/scheduled lease** system-wide | `180.00 CR` |
| **RESEARCHER** | `RES104` | `namlh@vku.udn.vn` | Max **3 nodes/job** | `1250.00 CR` |
| **ADMIN** | `ADMIN01` | `hpc-admin@vku.udn.vn` | Max **3 nodes/job** + Fleet Override & Credit Top-Up | `9999.00 CR` |
