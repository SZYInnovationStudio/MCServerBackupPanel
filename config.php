<?php
/**
 * MCServerBackupPanel — Global Configuration
 *
 * Database connection and site-wide configuration.
 * Team: SZY创新工作室
 *
 * @package MCSBP
 * @version 1.0.0
 */

// --------------------------------------------------
// Session
// --------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $__secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $__secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// --------------------------------------------------
// Database Configuration
// --------------------------------------------------
// PDO "No such file or directory" fix: use 127.0.0.1 instead of localhost
// to force TCP (localhost tries Unix socket which may be misconfigured)
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'mcsbp');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// --------------------------------------------------
// Site Paths
// --------------------------------------------------
define('SITE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'));
define('SITE_DIR', __DIR__);
define('BACKUP_ROOT', SITE_DIR . '/backups');

// --------------------------------------------------
// Timezone
// --------------------------------------------------
date_default_timezone_set('Asia/Shanghai');

// --------------------------------------------------
// PDO Connection
// --------------------------------------------------
/**
 * Get PDO database connection.
 *
 * @return PDO
 * @throws PDOException
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Auto-migration: add new columns if they don't exist (idempotent, MySQL 5.7+ compatible)
        $migrations = [
            // [SQL to run, error codes to ignore (already-exists)]
            ["ALTER TABLE backup_records ADD COLUMN download_password VARCHAR(255) DEFAULT NULL COMMENT 'hashed password for public download, NULL=no password' AFTER is_public", [1060]],
            ["ALTER TABLE backup_tasks ADD COLUMN backup_items TEXT DEFAULT NULL COMMENT 'JSON array of relative paths to backup, null=all files' AFTER backup_filename", [1060]],
            // MODIFY is always safe to re-run
            ["ALTER TABLE backup_records MODIFY COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'default private: 1=public, 0=private'", []],
            // New columns for auto-delete + encryption
            ["ALTER TABLE backup_tasks ADD COLUMN auto_delete TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'auto-delete old backups' AFTER backup_items", [1060]],
            ["ALTER TABLE backup_tasks ADD COLUMN encrypted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'encrypt backup files' AFTER auto_delete", [1060]],
            ["ALTER TABLE backup_tasks ADD COLUMN encrypt_password VARCHAR(255) DEFAULT NULL COMMENT 'encrypted encryption password' AFTER encrypted", [1060]],
            // Backup job status tracking table
            ["CREATE TABLE IF NOT EXISTS backup_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                server_id INT NOT NULL,
                task_id INT NULL,
                filename VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                message VARCHAR(500) DEFAULT '',
                log_text LONGTEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [1050]],
            ["ALTER TABLE backup_tasks ADD COLUMN backup_path_type VARCHAR(10) NOT NULL DEFAULT 'relative' COMMENT 'relative or absolute' AFTER backup_destination", [1060]],
            ["ALTER TABLE backup_tasks ADD COLUMN default_public TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'new backups default visibility: 1=public, 0=private' AFTER encrypted", [1060]],
        ];
        foreach ($migrations as [$sql, $ignoreCodes]) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $code = (int) $e->getCode();
                // MySQL error codes: 1060 = Duplicate column, 42S21 = SQLSTATE
                if (!in_array($code, $ignoreCodes, true) && $e->getCode() !== '42S21') {
                    error_log('MCSBP migration failed: ' . $e->getMessage() . ' [SQL: ' . $sql . ']');
                }
            }
        }
    }
    return $pdo;
}

// --------------------------------------------------
// Helper Functions
// --------------------------------------------------

/**
 * Generate CSRF token and store in session.
 *
 * @return string
 */
function generateCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST.
 *
 * @return bool
 */
function verifyCSRF(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Format file size to human-readable string.
 *
 * @param int $bytes
 * @return string
 */
function formatSize(int $bytes): string
{
    if ($bytes === 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/** @var array|null Global config cache — shared between getConfig() and refreshConfig() */
$_config_cache = null;

/**
 * Get site config value by key.
 *
 * @param string $key
 * @return string|null
 */
function getConfig(string $key): ?string
{
    global $_config_cache;
    if ($_config_cache === null) {
        try {
            $db = getDB();
            $stmt = $db->query("SELECT * FROM config LIMIT 1");
            $_config_cache = $stmt->fetch() ?: [];
        } catch (Exception $e) {
            $_config_cache = [];
        }
    }
    return $_config_cache[$key] ?? null;
}

/**
 * Refresh cached config.
 */
function refreshConfig(): void
{
    global $_config_cache;
    $db = getDB();
    $stmt = $db->query("SELECT * FROM config LIMIT 1");
    $_config_cache = $stmt->fetch() ?: [];
}

/**
 * Generate a safe filename for backups.
 *
 * @param string $name
 * @return string
 */
function safeFilename(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9_\-\p{Han}.]/u', '_', $name);
}

/**
 * Ensure a directory exists, create recursively if not.
 *
 * @param string $path
 * @return bool
 */
function ensureDir(string $path): bool
{
    if (!is_dir($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}

// --------------------------------------------------
// Rate Limiting Helpers（登录与公开下载暴力破解防护）
// --------------------------------------------------

/**
 * 检查某 key（如 IP + 场景）是否被限流。
 *
 * @param string $key           限流标识（建议含 IP，避免仅依赖可被清除的 session）
 * @param int    $maxAttempts   窗口内最大失败次数
 * @param int    $windowSeconds 统计窗口（秒）
 * @param int    $lockSeconds   触发限流后的锁定时长（秒）
 * @return bool true=允许继续尝试，false=已被限流
 */
function rateLimitAllowed(string $key, int $maxAttempts = 5, int $windowSeconds = 300, int $lockSeconds = 900): bool
{
    $dir = sys_get_temp_dir() . '/mcsbp_ratelimit';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $file = $dir . '/' . sha1($key) . '.json';
    $data = ['attempts' => 0, 'window_start' => time(), 'locked_until' => 0];
    if (is_file($file)) {
        $raw = json_decode((string)@file_get_contents($file), true);
        if (is_array($raw)) { $data = array_merge($data, $raw); }
    }
    $now = time();
    if ($data['locked_until'] > $now) {
        return false; // 锁定中
    }
    if ($now - $data['window_start'] > $windowSeconds) {
        $data['attempts'] = 0;
        $data['window_start'] = $now;
    }
    if ($data['attempts'] >= $maxAttempts) {
        $data['locked_until'] = $now + $lockSeconds;
        @file_put_contents($file, json_encode($data));
        return false;
    }
    return true;
}

/**
 * 记录一次失败尝试。
 */
function rateLimitHit(string $key): void
{
    $dir = sys_get_temp_dir() . '/mcsbp_ratelimit';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $file = $dir . '/' . sha1($key) . '.json';
    $data = ['attempts' => 0, 'window_start' => time(), 'locked_until' => 0];
    if (is_file($file)) {
        $raw = json_decode((string)@file_get_contents($file), true);
        if (is_array($raw)) { $data = array_merge($data, $raw); }
    }
    $data['attempts']++;
    @file_put_contents($file, json_encode($data));
}

/**
 * 当目标文件已存在时，生成一个带时间戳后缀的唯一路径，避免覆盖现有备份。
 */
function uniqueBackupPath(string $filePath): string
{
    if (!is_file($filePath)) {
        return $filePath;
    }
    $dir = dirname($filePath);
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $base = $ext === '' ? basename($filePath) : substr(basename($filePath), 0, -(strlen($ext) + 1));
    $suffix = date('Ymd_His');
    $candidate = $dir . '/' . $base . '_' . $suffix . ($ext === '' ? '' : '.' . $ext);
    $i = 1;
    while (is_file($candidate)) {
        $candidate = $dir . '/' . $base . '_' . $suffix . '_' . $i . ($ext === '' ? '' : '.' . $ext);
        $i++;
    }
    return $candidate;
}

// --------------------------------------------------
// Encryption Helpers
// --------------------------------------------------
/**
 * Application secret — 用于可逆加密。
 * 生产环境由 install.php 安装时生成随机值（不可预测）。
 * 此处作为未走安装流程时的默认值，加入 DB_PASS 以增加熵。
 * 注意：变更此密钥会使已加密数据无法解密。
 */
define('APP_SECRET', getenv('MCSBP_APP_SECRET') ?: hash('sha256', DB_HOST . DB_NAME . DB_USER . DB_PASS . __FILE__));

/**
 * Encrypt a plaintext string with APP_SECRET for reversible storage.
 *
 * @param string $plaintext
 * @return string Base64-encoded encrypted string
 */
function encryptValue(string $plaintext): string
{
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', APP_SECRET, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt a string previously encrypted with encryptValue().
 *
 * @param string $encoded
 * @return string
 */
function decryptValue(string $encoded): string
{
    $data = base64_decode($encoded);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'aes-256-cbc', APP_SECRET, 0, $iv);
}

/**
 * Normalize a backup destination path.
 *
 * @param string $path       The raw destination path
 * @param bool   $isAbsolute true = absolute server path, false = relative to BACKUP_ROOT
 *                           If not explicitly set, auto-detect: paths starting with /
 *                           (except /backups) are treated as absolute.
 * @return string Absolute path ending with /
 */
function normalizeBackupPath(string $path, ?bool $isAbsolute = null): string
{
    $path = str_replace('\\', '/', trim($path));

    // Auto-detect when not explicitly specified
    if ($isAbsolute === null) {
        $isAbsolute = (strpos($path, '/') === 0 && stripos($path, '/backups') !== 0);
    }

    if ($isAbsolute) {
        // Normalize: collapse consecutive slashes, ensure trailing /
        $result = preg_replace('#/+#', '/', '/' . $path);
        return rtrim($result, '/') . '/';
    }

    // Strip legacy "/backups" prefix (old default values)
    if (stripos($path, '/backups/') === 0) {
        $path = substr($path, 9);
    } elseif (stripos($path, '/backups') === 0) {
        $path = substr($path, 8);
    }
    $path = ltrim($path, '/');

    // Relative to BACKUP_ROOT — collapse any double slashes from concatenation
    $result = rtrim(BACKUP_ROOT, '/') . '/' . $path;
    return preg_replace('#/+#', '/', $result);
}

/**
 * Create a ZIP archive.
 *
 * Strategy (priority order):
 *   1. System /usr/bin/zip — native C, 10x faster, zero PHP memory
 *   2. PHP ZipArchive    — pure PHP, slower, fallback only
 *
 * @param string      $sourceDir     Source directory
 * @param string      $destFile      Destination ZIP path
 * @param array       $selectedItems Sub-paths to include (empty = all)
 * @param PDO|null    $db            Optional DB handle for progress logging
 * @param int|null    $jobId         Optional job ID for progress logging
 * @return array [true, fileCount] on success, [false, errorMessage] on failure
 */
function createBackupZip(string $sourceDir, string $destFile, array $selectedItems = [], ?PDO $db = null, ?int $jobId = null): array
{
    $sourceDir = rtrim(str_replace('\\', '/', realpath($sourceDir) ?: $sourceDir), '/') . '/';

    ignore_user_abort(true);
    @set_time_limit(0);

    $log = function(string $msg) use ($db, $jobId) {
        if ($db && $jobId) logJob($db, $jobId, 'info', $msg);
    };

    $isCancelled = function() use ($db, $jobId) {
        if (!$db || !$jobId) return false;
        try {
            $stmt = $db->prepare("SELECT status FROM backup_jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            return $stmt->fetchColumn() === 'cancel_requested';
        } catch (Throwable $e) {
            return false;
        }
    };

    if ($isCancelled()) {
        return [false, '备份已取消'];
    }

    // ── Security: reject path traversal attempts ──
    foreach ($selectedItems as $item) {
        $normalized = str_replace('\\', '/', trim($item));
        if ($normalized === '' || strpos($normalized, '..') !== false || strpos($normalized, '/') === 0) {
            return [false, "Invalid backup item: {$item}"];
        }
    }

    // ── Pre-flight: disk space check ──
    $destDir = dirname($destFile);
    $freeBytes = @disk_free_space($destDir);
    if ($freeBytes !== false) {
        if ($freeBytes < 100 * 1024 * 1024) {
            // Less than 100 MB — refuse to start
            $freeHuman = formatSize($freeBytes);
            return [false, "磁盘空间不足（仅剩 {$freeHuman}），备份中止"];
        }
        if ($freeBytes < 1024 * 1024 * 1024) {
            // Less than 1 GB — warn but still try
            $log('WARNING: only ' . formatSize($freeBytes) . ' free — backup may fail if source is larger');
        }
    }

    // ── Locate system zip ──
    $zipBin = null;
    foreach (['/usr/bin/zip', '/usr/local/bin/zip', '/bin/zip', '/usr/sbin/zip'] as $c) {
        if (@is_executable($c)) { $zipBin = $c; break; }
    }
    $hasExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));

    // ── Phase 1: system zip (optimal path) ──
    if ($zipBin && $hasExec) {
        $startTime = microtime(true);
        @unlink($destFile);
        $escSource = escapeshellarg($sourceDir);
        $escDest   = escapeshellarg($destFile);

        if (empty($selectedItems)) {
            $cmd = "cd {$escSource} && {$zipBin} -r -q -0 {$escDest} . 2>&1";
            $log('System zip: entire directory');
        } else {
            // KEY OPTIMIZATION: pass items directly to zip instead of building
            // a file list with PHP RecursiveDirectoryIterator first.
            // zip's native traversal is 10-50x faster than PHP iteration.
            $items = implode(' ', array_map('escapeshellarg', $selectedItems));
            $cmd = "cd {$escSource} && {$zipBin} -r -q -0 {$escDest} {$items} 2>&1";
            $log('System zip: ' . implode(', ', $selectedItems));
        }

        $output = [];
        $retCode = 0;
        @exec($cmd, $output, $retCode);
        $elapsed = round(microtime(true) - $startTime, 1);

        if ($isCancelled()) {
            @unlink($destFile);
            return [false, '备份已取消'];
        }

        if ($retCode === 0 && is_file($destFile) && filesize($destFile) > 0) {
            $sz = formatSize(filesize($destFile));
            $log("Done in {$elapsed}s: {$sz}");
            $fileCount = 0;
            @exec("{$zipBin} -sf {$escDest} 2>&1 | tail -n +2 | head -n -1 | wc -l", $listOut);
            $fileCount = max(1, (int)(trim(implode('', $listOut)) ?: 0));
            return [true, $fileCount];
        }

        // zip exited with warnings/errors (e.g. permission denied on some files)
        // BUT the archive itself may be valid — check before falling back
        if (is_file($destFile) && filesize($destFile) > 0) {
            // Quick ZIP header check: first 4 bytes must be "PK\x03\x04"
            $fh = @fopen($destFile, 'rb');
            if ($fh) {
                $header = fread($fh, 4);
                fclose($fh);
                if ($header === "PK\x03\x04" || $header === "PK\x05\x06" || $header === "PK\x07\x08") {
                    $sz = formatSize(filesize($destFile));
                    $log("WARNING: zip completed with warnings in {$elapsed}s: {$sz}");
                    $log("Some files may have been skipped (permission denied). The archive is usable.");
                    $fileCount = 0;
                    @exec("{$zipBin} -sf {$escDest} 2>&1 | tail -n +2 | head -n -1 | wc -l", $listOut);
                    $fileCount = max(1, (int)(trim(implode('', $listOut)) ?: 0));
                    return [true, $fileCount];
                }
            }
        }

        // System zip failed — clean up and decide next step
        @unlink($destFile);   // Remove partial/corrupt zip to free disk space
        $errStr = implode('; ', array_slice(array_filter($output), -3));
        $log("System zip failed (code={$retCode}, {$elapsed}s): {$errStr}");

        // If it's a disk-full error, ZipArchive will also fail — don't waste time
        if (stripos($errStr, 'No space left') !== false || stripos($errStr, 'write error') !== false) {
            unset($output);  // Free memory before returning
            return [false, "磁盘空间不足，备份中止。请清理磁盘后重试。" . ($freeBytes !== false ? "（剩余: " . formatSize($freeBytes) . "）" : "")];
        }
    } else {
        if (!$zipBin)  $log('zip not found. Install: apt install zip');
        if (!$hasExec) $log('exec() is disabled — cannot run system commands');
    }

    // ── Phase 2: PHP ZipArchive fallback ──
    if (!class_exists('ZipArchive')) {
        return [false, 'ZipArchive 扩展未安装，无法创建备份'];
    }

    // Re-check disk space before ZipArchive attempt (system zip may have consumed some)
    $freeBytes = @disk_free_space($destDir);
    if ($freeBytes !== false && $freeBytes < 100 * 1024 * 1024) {
        return [false, "磁盘空间不足（仅剩 " . formatSize($freeBytes) . "），无法继续"];
    }

    $log('Using ZipArchive fallback');
    @ini_set('memory_limit', '4096M');

    try {
        $flags = FilesystemIterator::SKIP_DOTS
               | FilesystemIterator::CATCH_GET_CHILD
               | RecursiveDirectoryIterator::SKIP_DOTS;

        $zip = new ZipArchive();
        if ($zip->open($destFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [false, '无法创建ZIP文件'];
        }
        $zip->setCompressionMethod(ZipArchive::CM_STORE);
        $fileCount = 0;
        $skipCount = 0;
        $ck = 0;

        $iterations = empty($selectedItems)
            ? [$sourceDir]
            : array_map(fn($item) => $sourceDir . ltrim($item, '/'), $selectedItems);

        foreach ($iterations as $path) {
            $label = empty($selectedItems) ? basename($sourceDir) : basename($path);
            $path = str_replace('\\', '/', $path);

            if (is_file($path)) {
                if (is_readable($path)) {
                    $rp = ltrim(str_replace('\\', '/', str_replace($sourceDir, '', $path)), '/');
                    $zip->addFile($path, $rp);
                    $fileCount++;
                } else { $skipCount++; }
                continue;
            }

            if (!is_dir($path)) {
                $log("Skip: {$label} (not found)");
                $skipCount++;
                continue;
            }

            try {
                $inner = new RecursiveDirectoryIterator($path, $flags);
            } catch (\UnexpectedValueException $e) {
                $log("Skip: {$label} — " . $e->getMessage());
                $skipCount++;
                continue;
            }

            $iter = new RecursiveIteratorIterator($inner, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iter as $file) {
                try {
                    $fp = $file->getRealPath();
                    if ($fp === false) { $skipCount++; continue; }
                    $fp = str_replace('\\', '/', $fp);
                    $rp = substr($fp, strlen($sourceDir));
                    if ($file->isDir()) {
                        $zip->addEmptyDir($rp);
                    } else {
                        if (!$file->isReadable()) { $skipCount++; continue; }
                        $zip->addFile($fp, $rp);
                        $fileCount++;
                    }
                } catch (\RuntimeException $e) {
                    $skipCount++;
                    continue;
                }

                if (++$ck % 5000 === 0) {
                    if ($isCancelled()) {
                        $zip->close();
                        @unlink($destFile);
                        return [false, '备份已取消'];
                    }
                    $log("... {$fileCount} files" . ($skipCount ? " ({$skipCount} skipped)" : ''));
                }
            }
        }

        if ($fileCount === 0) {
            $zip->close();
            @unlink($destFile);
            return [false, "无文件可备份" . ($skipCount ? "（{$skipCount}项无权限）" : '')];
        }

        $log("Closing ZIP ({$fileCount} files)...");
        if (!$zip->close()) {
            @unlink($destFile);
            return [false, '写入失败（磁盘满？）'];
        }

        $log('Done: ' . formatSize(filesize($destFile)));
        return [true, $fileCount];
    } catch (Exception $e) {
        @unlink($destFile);
        return [false, '异常：' . $e->getMessage()];
    }
}

// --------------------------------------------------
// Job Tracking Helpers (shared: worker + inline fallback)
// --------------------------------------------------

/**
 * Append a timestamped log line to the job record.
 */
function logJob(PDO $db, ?int $jobId, string $level, string $message): void
{
    if (!$jobId) return;
    $line = '[' . date('H:i:s') . '] [' . strtoupper($level) . '] ' . $message;
    try {
        $db->prepare("UPDATE backup_jobs SET log_text = CONCAT(COALESCE(log_text, ''), CHAR(10 USING utf8mb4), ?) WHERE id = ?")
           ->execute([$line, $jobId]);
    } catch (Throwable $e) {
        // Logging failure must never crash the worker
    }
}

/**
 * Update job status and optional message.
 */
function setJobStatus(PDO $db, ?int $jobId, string $status, string $message = ''): void
{
    if (!$jobId) return;
    try {
        $db->prepare("UPDATE backup_jobs SET status = ?, message = ? WHERE id = ?")
           ->execute([$status, $message, $jobId]);
    } catch (Throwable $e) {
        // Fail silently — status is best-effort
    }
}

/**
 * Compact AUTO_INCREMENT after job deletions so the next new job
 * fills the lowest available ID gap instead of skipping ahead.
 */
function compactJobIds(PDO $db): void
{
    try {
        $rows = $db->query("SELECT id FROM backup_jobs ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $nextId = 1;
        foreach ($rows as $rid) {
            if ((int)$rid === $nextId) {
                $nextId++;
            } else {
                break;
            }
        }
        $db->exec("ALTER TABLE backup_jobs AUTO_INCREMENT = $nextId");
    } catch (Throwable $e) {
        // Non-critical — next job just gets a higher number; no harm
    }
}

/**
 * Flush JSON response to the client immediately, then continue executing.
 * This prevents Nginx fastcgi_read_timeout from killing long-running backups.
 *
 * @param array $data JSON-serializable response
 */
function sendResponseAndContinue(array $data): void
{
    // Discard any previous output
    while (ob_get_level()) ob_end_clean();
    ob_start();

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $size = ob_get_length();

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . $size);
    header('Connection: close');

    // Flush to client
    ob_end_flush();
    @ob_flush();
    @flush();

    // Close connection (PHP-FPM / Apache)
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    // Tell session to write and close
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Now the client has the response. Continue working...
    ignore_user_abort(true);
    set_time_limit(0);
}

/**
 * Stream a file to the browser in chunks.
 *
 * Unlike readfile() which buffers the entire file into memory,
 * this reads and flushes in 1 MB chunks so even 10 GB+ files
 * download without exhausting memory or hitting timeouts.
 *
 * @param string $filePath Absolute path to the file
 * @param string $filename Download filename shown to browser
 */
function streamDownload(string $filePath, string $filename): void
{
    $fh = @fopen($filePath, 'rb');
    if (!$fh) {
        header('HTTP/1.1 500 Internal Server Error');
        exit;
    }

    $fileSize = @filesize($filePath);

    // Clear any prior output
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    if ($fileSize !== false && $fileSize > 0) {
        header('Content-Length: ' . $fileSize);
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Accept-Ranges: bytes');

    // Disable output buffering entirely
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', 'Off');
    @ini_set('memory_limit', '-1');
    @set_time_limit(0);

    // Disable PHP's internal read buffer so fread() hits the filesystem directly
    stream_set_read_buffer($fh, 0);

    // 8 MiB chunk — large enough to minimize PHP loop overhead,
    // small enough that memory stays trivial even with output buffering
    $chunkSize = 8 * 1024 * 1024;
    $flushEvery = 8; // flush to browser every 64 MiB to reduce syscall frequency

    $chunks = 0;
    while (!feof($fh) && connection_aborted() === 0) {
        $chunk = fread($fh, $chunkSize);
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $chunks++;

        // Flush periodically, not every chunk — reduces TCP small-packet overhead
        if ($chunks % $flushEvery === 0) {
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
        }
    }

    // Final flush for any remaining data
    if (ob_get_level() > 0) { ob_flush(); }
    flush();

    fclose($fh);
    exit;
}
