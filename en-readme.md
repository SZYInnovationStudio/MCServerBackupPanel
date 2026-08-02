# MCServerBackupPanel (MCSBP)

A Minecraft server backup management panel developed by **SZY Innovation Studio**.

A professional and lightweight server backup management tool supporting multi-server scheduled backups, one-click restore, public downloads, and password protection.

---

## Feature List

- **Installation Wizard** — Visual installation flow, automatically creates database and config files
- **Admin Dashboard** — Responsive dashboard, works smoothly on both desktop and mobile
- **Server Management** — Manual addition or automatic detection of MCSManager Daemon instances
- **Scheduled Backup Tasks** — Independent scheduled backups per server, with support for selecting specific directories/files
- **Instant Backup** — Initiate one-time backups directly from the backup management page, with custom paths and filenames
- **Backup Record Management** — View, download, delete, rename backup files, and set public/private visibility
- **One-Click Restore** — Extract backups back to the server directory (with ZIP Slip protection), with automatic pre-restore temporary backup
- **Public Download Page** — Download public backups without logging in, with optional password protection
- **Download Password Protection** — Set bcrypt-hashed passwords for backups; users must verify before downloading
- **Flexible Storage Paths** — Supports relative paths (stored under website `backups/`) or absolute server paths
- **Auto Cleanup** — Optionally auto-delete old backups; public page always shows only the latest backup
- **Task Status & Logs** — Real-time backup progress and detailed logs, with support for canceling and clearing tasks
- **Security** — CSRF protection, bcrypt password hashing, AES-256-CBC encrypted storage, PDO prepared statements, PHP proxy downloads, path traversal protection

---

## System Requirements

### Server

| Component | Minimum Version | Notes |
|-----------|----------------|-------|
| PHP | **7.3+** | PHP 8.0+ recommended |
| MySQL | **5.7+** | Or MariaDB 10.2+ |
| Web Server | Nginx / Apache | With URL rewrite support |
| OS | Linux | Ubuntu 20.04+ / Debian 11+ / CentOS 7+ recommended |

### Required PHP Extensions

| Extension | Purpose | Required |
|-----------|---------|:--------:|
| `pdo_mysql` | Database connection | **Yes** |
| `json` | JSON encoding/decoding | **Yes** |
| `session` | User login state | **Yes** |
| `zip` (ZipArchive) | ZIP compression/decompression (fallback path) | **Yes** |
| `openssl` | Password encryption storage (AES-256-CBC) | **Yes** |
| `mbstring` | Multibyte string handling | **Yes** |
| `fileinfo` | MIME type detection | Recommended |
| `ctype` | Character type checking | Recommended |

**Installation command (Debian/Ubuntu):**

```bash
sudo apt install php php-mysql php-zip php-mbstring php-xml php-json
```

**Installation command (CentOS/RHEL):**

```bash
sudo yum install php php-mysqlnd php-zip php-mbstring php-xml php-json
```

### Required PHP Functions

The following PHP functions **must** be available outside of `disable_functions`:

| Function | Purpose | Consequence if Disabled |
|----------|---------|------------------------|
| `exec` | Call system `zip` command for 10-50x speed boost | Falls back to PHP ZipArchive (slow); large directories may timeout |
| `proc_open` | Alternative process spawning | Complements `exec` |
| `popen` | Alternative process spawning | Complements `exec` |
| `symlink` | Symbolic link resolution (not strictly required) | Only affects certain `realpath` scenarios |
| `disk_free_space` | Check available disk space before backup | Skips disk space check |

> **Important**: Even if `exec`, `proc_open`, and `popen` are all disabled, the panel will still work (falling back to pure PHP ZipArchive), but backup speed will decrease significantly, and large directories (10GB+) may timeout. **It is strongly recommended to keep the `exec` function available.**

You can check currently disabled functions in `php.ini`:

```bash
php -r "echo ini_get('disable_functions');"
```

### Recommended System Tools

| Tool | Purpose | Install Command |
|------|---------|----------------|
| `zip` (Info-ZIP) | High-performance ZIP creation, native C implementation, 10-50x faster than PHP ZipArchive | `apt install zip` |

Verify system `zip` availability:

```bash
which zip && zip --version
```

---

## Quick Deployment

### 1. Upload Files

Upload the entire project directory to your web server (e.g., `/www/wwwroot/mcbackup.example.com/`).

### 2. Configure Web Server

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name mcbackup.example.com;
    root /www/wwwroot/mcbackup.example.com;
    index index.php;

    # Main entry
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        # Longer timeout needed for large backup files
        fastcgi_read_timeout 3600s;
    }

    # Deny direct access to backups directory (files served via PHP proxy)
    location ~ ^/backups/ {
        deny all;
    }

    # Deny access to sensitive files
    location ~ ^/(config\.php|database\.sql)$ {
        deny all;
    }
}
```

#### Apache Configuration

The project includes a `.htaccess` file. Ensure `mod_rewrite` is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 3. Directory Permissions

```bash
cd /www/wwwroot/mcbackup.example.com

# backups directory must be writable (web process writes backup files)
mkdir -p backups
chown -R www-data:www-data backups
chmod 755 backups

# config.php is auto-generated during installation; ensure parent directory is writable
chown www-data:www-data .
```

### 4. Run the Installation Wizard

Visit `http://your-domain/install.php` in your browser and fill in the wizard:

| Step | Content |
|------|---------|
| Site Info | Site name, Logo URL (optional), ICP filing number (optional), Police filing number (optional) |
| Admin Account | Username and password (at least 6 characters) |
| Database Connection | MySQL host, port, database name, username, password |

After submission, the system automatically:
1. Creates the database and table structure (including all migration columns)
2. Writes the admin account (bcrypt hashed)
3. Generates the `config.php` configuration file
4. Creates the `backups/` directory and security files (`.htaccess` + `index.php`)
5. **Auto-deletes `install.php`** (prevents re-installation)

### 5. Log in to the Admin Panel

After installation, you are automatically redirected to the login page. Log in with the admin credentials you set.

### 6. Configure Cron Job

Backup tasks run automatically at scheduled times. Add a system cron job:

```bash
crontab -e
```

Add the following line (checks every minute):

```
* * * * * /usr/bin/php /www/wwwroot/mcbackup.example.com/cron.php >> /var/log/mcsbp_cron.log 2>&1
```

> **Note**: Replace `/usr/bin/php` and the project path with actual values. You can confirm the PHP path with `which php`.
>
> `cron.php` only allows CLI execution; direct web access returns 403.

---

## User Guide

### 1. Add a Server

Go to the "Server Management" page and click "Add Server":

- **Auto-detect**: Enter the MCSManager Daemon installation path (default `/opt/mcsmanager/daemon`). The system automatically scans JSON config files in `data/InstanceConfig/`, lists all instances, and you can check the ones to batch-add.
- **Manual input**: Fill in "Server Name" and "Server Directory" (absolute path, e.g., `/szydata/mcsmanager/server/szydmc`).

### 2. Create a Backup Task

On the "Backup Tasks" page, select a server and click "Add Task":

| Field | Description |
|-------|-------------|
| **Backup Folder** | Absolute path of the source directory to back up |
| **Backup Time** | Automatic daily execution at HH:MM (24-hour format) |
| **Backup Destination** | Relative path (subdirectory under `backups/`) or absolute path (any writable server directory) |
| **Filename Template** | Supports `{server_name}` `{date}` `{time}` `{datetime}` variables |
| **Backup Contents** | Optionally back up specific directories/files; leave blank = everything |
| **Auto-delete Old Backups** | When checked, automatically cleans up on each run; public page only shows the latest |
| **Set Download Password** | When checked, automatically sets a bcrypt download password for all backups of this task |

#### Path Mode Explanation

| Mode | Example | Actual Storage Location |
|------|---------|------------------------|
| Relative Path | `SZYDMC-JAVA/` | `/www/wwwroot/mcbackup.example.com/backups/SZYDMC-JAVA/` |
| Absolute Path | `/mnt/disk/backups/` | `/mnt/disk/backups/` (requires web process write permission) |

> **Recommended to use relative paths**: unified file management, simpler permissions, no extra configuration needed.

### 3. Manual Backup (Instant Backup)

In the "Instant Backup" section at the top of the "Backup Management" page:
1. Select the target server
2. Check the directories/files to back up (all selected by default)
3. Optionally customize filename and storage path
4. Click "Backup Now" — progress can be viewed on the "Task Status" page

### 4. Manage Backup Records

On the "Backup Management" page, view all backup records:

| Action | Description |
|--------|-------------|
| **Download** | Direct download if no password; password required if protected (PHP proxy, real path not exposed) |
| **Public/Private** | Toggle whether the backup appears on the public download page |
| **Set/Change/Remove Password** | Manage download password protection (bcrypt hashed storage) |
| **Rename** | Rename the backup file (disk file is renamed simultaneously) |
| **Restore** | Extract back to the server directory; automatic temporary backup of current state before restoring |
| **Delete** | Permanently delete the backup file and database record |

### 5. Public Download Page

Visit the site homepage (no login required) to see all public backups:
- Password-protected backups show a lock icon; clicking opens a password input dialog
- After password verification, download via PHP proxy — **real file path is never exposed**
- Mobile password dialog is centered, consistent with desktop experience

### 6. Monitor Task Status

The "Task Status" page provides:
- Real-time status of all backup tasks (Waiting / Running / Success / Failed)
- Click a task row to view detailed logs
- Cancel running tasks / clear completed tasks
- Auto-refreshes every 5 seconds when tasks are running

---

## Directory Structure

```
MCServerBackupPanel/
├── .htaccess           # Apache security rules (deny direct access to config.php, database.sql, backups/)
├── admin_layout.php    # Admin dashboard common layout (sidebar + top bar + Toast notifications + responsive nav)
├── auth_check.php      # Login state verification middleware (not logged in → redirect to login.php)
├── backup.php          # Backup management: record list, download proxy, restore, password, rename, instant backup
├── common.css          # Global stylesheet (CSS variables, responsive layout, animations, mobile adaptation)
├── config.php          # Global config (generated after install): DB connection, encryption key, utility functions, auto-migration
├── console.php         # Dashboard: stat cards + pending task reminders + recent backup records
├── cron.php            # Cron task entry: CLI only, checks and executes due backup tasks every minute
├── database.sql        # Database table structure (6 tables)
├── index.php           # Public backup download page (card layout, password dialog, PHP proxy download)
├── install.php         # Installation wizard (auto-deleted after success)
├── job_status.php      # Task status page: real-time list + log detail dialog + auto-refresh
├── jobs_handler.php    # Task status AJAX API: list, logs, cancel, clear
├── login.php           # Admin login page (session regeneration, CSRF protection)
├── logout.php          # Logout (destroy session + clear cookies)
├── servers.php         # Server management: CRUD + MCSManager Daemon auto-detection + directory browse API
├── setting.php         # Site settings: name, Logo URL, ICP filing number, police filing number
├── tasks.php           # Backup task management: create, edit, delete, run now + file browser
├── backups/            # Backup file storage directory (direct web access forbidden)
│   ├── .htaccess       # Apache: Deny from all
│   └── index.php       # Returns 403
└── README.md
```

---

## Database Schema

| Table Name | Purpose | Key Fields |
|------------|---------|------------|
| `config` | Site configuration | site_name, site_logo, icp_number, police_number |
| `admin` | Admin accounts | username, password (bcrypt) |
| `servers` | MC server info | name, directory (absolute path) |
| `backup_tasks` | Backup tasks | backup_folder, backup_time, backup_destination, backup_path_type, backup_filename, backup_items (JSON), auto_delete, encrypted, encrypt_password (AES-256-CBC) |
| `backup_records` | Backup records | filename, file_size, file_path, is_public, download_password (bcrypt) |
| `backup_jobs` | Task execution logs | status, message, log_text (LONGTEXT) |

---

## Security Mechanisms

| Protection | Implementation |
|------------|----------------|
| **SQL Injection** | All SQL queries use PDO prepared statements + parameter binding; no string concatenation |
| **XSS** | All user input / database output escaped via `htmlspecialchars()` |
| **CSRF** | All POST requests include a CSRF Token, verified with `hash_equals()` |
| **Password Storage** | Admin password → `password_hash(BCRYPT)`; download password → same; task encryption password → `openssl_encrypt(AES-256-CBC)` + random IV |
| **File Download Protection** | PHP proxy download, `realpath()` path validation, real file path never exposed |
| **Path Traversal Protection** | Backup items reject `..` and absolute paths; restore validates every ZIP entry |
| **Directory Access Protection** | `backups/` has `.htaccess` + `index.php` double protection; `config.php` / `database.sql` blocked by `.htaccess` |
| **Session Security** | `session_regenerate_id(true)` on login; session destroyed + cookies cleared on logout |
| **Installation Security** | `install.php` auto-deleted after successful installation |
| **CLI Restriction** | `cron.php` only allows command-line execution (`php_sapi_name()` check); web access returns 403 |
| **Disk Space Check** | Before backup, checks target disk free space (<100MB rejected, <1GB warned) |

---

## Important Notes

### Permission Requirements

1. The **`backups/` directory** must be readable and writable by the web process (e.g., `www-data`)
2. When using **absolute paths** for backup storage, the target directory must be writable by the web process
3. The backup **source directory** (MC server directory) must be readable by the web process (at least for files to be backed up)
4. If the MC server directory permissions are restricted, you can grant access only to needed subdirectories: `chmod o+r /path/to/world`

### Performance Recommendations

1. **Be sure to install the system `zip` command**: `apt install zip`, backup speed increases 10-50x and does not consume PHP memory
2. When backing up very large directories, consider relaxing limits in `php.ini`:
   ```ini
   memory_limit = -1
   max_execution_time = 0
   ```
3. Nginx users should increase timeout:
   ```nginx
   fastcgi_read_timeout 3600s;
   ```
4. The panel uses `sendResponseAndContinue` technology: after backup starts, it immediately returns an HTTP response and continues execution in the background, avoiding browser/Nginx timeout

### Backup Strategy Recommendations

1. Create independent backup tasks for each MC server
2. Set reasonable backup times (e.g., 3:00 AM) to avoid player peak hours
3. **Recommended to use relative paths** for backup storage — unified file management, simpler permissions
4. Enable "Auto Cleanup" to prevent old backups from filling up the disk
5. For important backups, consider setting an additional download password
6. Use filename templates like `{server_name}_{date}_{time}.zip` to avoid duplicate filenames for multiple daily backups

### Restore Notes

1. Before restoring, the system **automatically creates a temporary backup of the current server state** (stored in `backups/pre_restore/`)
2. If the pre-restore backup fails (insufficient permissions, disk full), the restore will still continue, but there will be no rollback file
3. Restore uses the ZipArchive extension with **ZIP Slip protection** — rejects file entries containing `..` or absolute paths
4. The `backups/pre_restore/` directory is not auto-cleaned; periodic manual cleanup is recommended

---

## Troubleshooting

| Issue | Possible Cause | Solution |
|-------|---------------|----------|
| **PDOException: No such file or directory** | `localhost` attempts Unix socket connection (wrong socket path) | Change `DB_HOST` from `localhost` to `127.0.0.1` to force TCP connection |
| **Backup stuck at "Creating ZIP archive"** | `exec()` disabled + large directory ZipArchive traversal | Install system `zip` command + enable `exec()` function |
| **Backup log only shows "0"** | `||` in MySQL is not string concatenation (treated as OR) | Already fixed by using `CONCAT()`, no longer an issue |
| **Permission denied** | Web process has no permission on source/target directory | `chmod -R o+r source_dir` or `chown www-data target_dir` |
| **No space left on device** | Insufficient disk space | Clean up disk; panel auto-checks: rejects if <100MB, warns if <1GB |
| **No download password after backup** | Old version INSERT statement missing `download_password` field | Already fixed; ensure you upgrade to the latest code |
| **Modal closes when switching path type** | Overlay click event misjudgment (mobile dropdown select) | Already fixed: uses `!e.target.closest('.modal')` check |
| **Scheduled tasks don't support absolute paths** | cron.php didn't pass `backup_path_type` parameter | Already fixed; ensure you upgrade to the latest code |
| **PDO connection failed** | MySQL service not running or wrong credentials | `systemctl status mysql`; check `config.php` credentials |
| **Installation failed** | Database user lacks sufficient permissions | Ensure the database user has CREATE DATABASE and CREATE TABLE privileges |

### Log Locations

- Cron task logs: `/var/log/mcsbp_cron.log` (configured in crontab)
- PHP error logs: `/var/log/php*.log` or web server error logs
- Backup task logs: view in the log detail dialog on the "Task Status" page
- Pre-restore backup failure logs: PHP `error_log`

---

## Design Philosophy

- **Color Scheme**: Dark blue-gray sidebar (#1e293b) + warm light gray background (#f9fafb) + soft professional blue accent (#3b82f6)
- **Typography**: System font stack, monospace fonts for paths and code display
- **Animations**: 0.2s quick transitions, non-intrusive to workflow
- **Responsive**: Mobile hamburger menu, table-to-card layout, touch targets ≥ 44px
- **Quiet and Unobtrusive**: No pop-up harassment, no forced notifications, professional and understated design language

---

## Technical Architecture

- **Backend**: Native PHP (no framework), PDO database abstraction layer
- **Frontend**: Native HTML/CSS/JS (no build tools), Fetch API AJAX
- **Backup Engine**: Dual strategy — system `zip` (native C, preferred) + PHP ZipArchive (fallback)
- **Encryption**: OpenSSL AES-256-CBC (reversible storage) + bcrypt (irreversible hashing)
- **Deployment**: Only requires PHP + MySQL, no Composer dependencies

---

## Open Source License

MIT License

Copyright (c) 2026 SZY Innovation Studio

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

---

**Powered by SZY Innovation Studio**
