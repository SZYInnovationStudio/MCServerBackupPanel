<?php
/**
 * MCServerBackupPanel — Installation Wizard
 *
 * Sets up database, admin account, and site configuration.
 * Self-deletes after successful installation.
 *
 * @package MCSBP
 * @version 1.0.0
 */

// If already installed (install.lock exists), redirect to main page
if (file_exists(__DIR__ . '/install.lock')) {
    if (file_exists(__FILE__)) {
        @unlink(__FILE__);
    }
    header('Location: ' . (file_exists(__DIR__ . '/config.php') ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') : '') . '/index.php');
    exit;
}

$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$error = '';
$success = '';

/**
 * Render the common HTML header for install page.
 */
function installHeader(): void
{
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 — MCServerBackupPanel</title>
    <link rel="stylesheet" href="common.css">
</head>
<body class="install-page">
    <?php
}

/**
 * Render the common HTML footer for install page.
 */
function installFooter(): void
{
    ?>
</body>
</html>
    <?php
}

// ================================
// Step 1: Show form
// ================================
if ($step === 1):
    installHeader();
    ?>
<div class="install-card">
    <div class="install-logo">
        <h1>MCServerBackupPanel</h1>
        <p>SZY创新工作室 — 安装向导</p>
    </div>

    <?php if ($error): ?>
    <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="install.php" novalidate id="install-form">
        <input type="hidden" name="step" value="2">

        <h3 class="mb-16" style="font-size:14px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">站点信息</h3>

        <div class="form-group">
            <label class="form-label" for="site_name">网站名称</label>
            <input type="text" id="site_name" name="site_name" class="form-input" value="MCServerBackupPanel" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="site_logo">网站头像 URL <span class="optional">(选填)</span></label>
            <input type="url" id="site_logo" name="site_logo" class="form-input" placeholder="https://example.com/logo.png">
        </div>
        <div class="form-group">
            <label class="form-label" for="icp_number">ICP备案号 <span class="optional">(选填)</span></label>
            <input type="text" id="icp_number" name="icp_number" class="form-input" placeholder="京ICP备XXXXXXXX号">
        </div>
        <div class="form-group">
            <label class="form-label" for="police_number">公网安备号 <span class="optional">(选填)</span></label>
            <input type="text" id="police_number" name="police_number" class="form-input" placeholder="京公网安备 XXXXXXXXXX号">
        </div>

        <h3 class="mb-16 mt-32" style="font-size:14px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">管理员账户</h3>

        <div class="form-group">
            <label class="form-label" for="username">管理员用户名</label>
            <input type="text" id="username" name="username" class="form-input" required autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="password">管理员密码</label>
            <input type="password" id="password" name="password" class="form-input" required minlength="6">
        </div>
        <div class="form-group">
            <label class="form-label" for="password_confirm">确认密码</label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-input" required minlength="6">
            <div class="form-error" id="password-error">两次输入的密码不一致</div>
        </div>

        <h3 class="mb-16 mt-32" style="font-size:14px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.5px;">数据库连接</h3>

        <div class="form-group">
            <label class="form-label" for="db_host">数据库主机</label>
            <input type="text" id="db_host" name="db_host" class="form-input" value="localhost" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="db_port">数据库端口</label>
            <input type="text" id="db_port" name="db_port" class="form-input" value="3306" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="db_name">数据库名称</label>
            <input type="text" id="db_name" name="db_name" class="form-input" value="mcsbp" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="db_user">数据库用户名</label>
            <input type="text" id="db_user" name="db_user" class="form-input" value="root" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="db_pass">数据库密码</label>
            <input type="password" id="db_pass" name="db_pass" class="form-input" placeholder="留空表示无密码">
        </div>

        <button type="submit" class="btn btn-primary w-full mt-24" id="submit-btn">开始安装</button>
    </form>
</div>

<script>
document.getElementById('install-form').addEventListener('submit', function(e) {
    var pw = document.getElementById('password').value;
    var pw2 = document.getElementById('password_confirm').value;
    if (pw !== pw2) {
        e.preventDefault();
        document.getElementById('password-error').style.display = 'block';
        document.getElementById('password_confirm').parentElement.classList.add('has-error');
    }
});
</script>

<?php
    installFooter();

// ================================
// Step 2: Process installation
// ================================
elseif ($step === 2):

    $siteName      = trim($_POST['site_name'] ?? 'MCServerBackupPanel');
    $siteLogo      = trim($_POST['site_logo'] ?? '');
    $icpNumber     = trim($_POST['icp_number'] ?? '');
    $policeNumber  = trim($_POST['police_number'] ?? '');
    $username      = trim($_POST['username'] ?? '');
    $password      = $_POST['password'] ?? '';
    $passwordConf  = $_POST['password_confirm'] ?? '';
    $dbHost        = trim($_POST['db_host'] ?? 'localhost');
    $dbPort        = trim($_POST['db_port'] ?? '3306');
    $dbName        = trim($_POST['db_name'] ?? 'mcsbp');
    $dbUser        = trim($_POST['db_user'] ?? 'root');
    $dbPass        = $_POST['db_pass'] ?? '';

    // Validation
    $errors = [];
    if ($siteName === '') $errors[] = '请填写网站名称。';
    if ($username === '') $errors[] = '请填写管理员用户名。';
    if (strlen($password) < 6) $errors[] = '密码长度不能少于6位。';
    if ($password !== $passwordConf) $errors[] = '两次密码输入不一致。';
    if ($dbHost === '') $errors[] = '请填写数据库主机。';
    if ($dbName === '') $errors[] = '请填写数据库名称。';
    if ($dbUser === '') $errors[] = '请填写数据库用户名。';

    if (!empty($errors)) {
        $error = implode('<br>', $errors);
        $step = 1;
        installHeader();
        ?>
<div class="install-card">
    <div class="install-logo">
        <h1>MCServerBackupPanel</h1>
        <p>SZY创新工作室 — 安装向导</p>
    </div>
    <div class="login-error"><?php echo $error; ?></div>
    <p class="text-center mt-16"><a href="install.php" class="btn btn-secondary">返回重新填写</a></p>
</div>
        <?php
        installFooter();
        exit;
    }

    // Try database connection
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbHost, $dbPort);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        // Import database.sql
        $sqlPath = __DIR__ . '/database.sql';
        if (!file_exists($sqlPath)) {
            throw new RuntimeException('找不到 database.sql 文件。');
        }

        $sqlContent = file_get_contents($sqlPath);
        // Remove comments and split by semicolons
        $sqlContent = preg_replace('/\/\*.*?\*\//s', '', $sqlContent);
        $sqlContent = preg_replace('/--.*$/m', '', $sqlContent);
        $statements = array_filter(
            array_map('trim', explode(';', $sqlContent)),
            function($s) { return $s !== ''; }
        );

        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }

        // Insert admin account
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $insertAdmin = $pdo->prepare("INSERT INTO admin (username, password, created_at) VALUES (?, ?, NOW())");
        $insertAdmin->execute([$username, $hashedPassword]);

        // Insert site config
        $insertConfig = $pdo->prepare(
            "INSERT INTO config (site_name, site_logo, icp_number, police_number, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())"
        );
        $insertConfig->execute([$siteName, $siteLogo ?: null, $icpNumber ?: null, $policeNumber ?: null]);

        // Write config.php with actual DB credentials
        $configContent = <<<PHP
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
    session_start();
}

// --------------------------------------------------
// Database Configuration
// --------------------------------------------------
define('DB_HOST', '{$dbHost}');
define('DB_PORT', '{$dbPort}');
define('DB_NAME', '{$dbName}');
define('DB_USER', '{$dbUser}');
define('DB_PASS', '{$dbPass}');
define('DB_CHARSET', 'utf8mb4');

// --------------------------------------------------
// Site Paths
// --------------------------------------------------
define('SITE_URL', rtrim(dirname(\$_SERVER['SCRIPT_NAME']), '/\\\\'));
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
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        \$options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, \$options);

        // Auto-migration
        \$migrations = [
            ["ALTER TABLE backup_tasks ADD COLUMN backup_path_type VARCHAR(10) NOT NULL DEFAULT 'relative' COMMENT 'relative or absolute' AFTER backup_destination", [1060]],
        ];
        foreach (\$migrations as [\$sql, \$ignoreCodes]) {
            try {
                \$pdo->exec(\$sql);
            } catch (PDOException \$e) {
                \$code = (int) \$e->getCode();
                if (!in_array(\$code, \$ignoreCodes, true) && \$e->getCode() !== '42S21') {}
            }
        }
    }
    return \$pdo;
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
    if (empty(\$_SESSION['csrf_token'])) {
        \$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return \$_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST.
 *
 * @return bool
 */
function verifyCSRF(): bool
{
    \$token = \$_POST['csrf_token'] ?? '';
    return !empty(\$_SESSION['csrf_token']) && hash_equals(\$_SESSION['csrf_token'], \$token);
}

/**
 * Format file size to human-readable string.
 *
 * @param int \$bytes
 * @return string
 */
function formatSize(int \$bytes): string
{
    if (\$bytes === 0) {
        return '0 B';
    }
    \$units = ['B', 'KB', 'MB', 'GB', 'TB'];
    \$i = floor(log(\$bytes, 1024));
    return round(\$bytes / pow(1024, \$i), 2) . ' ' . \$units[\$i];
}

/**
 * Get site config value by key.
 *
 * @param string \$key
 * @return string|null
 */
function getConfig(string \$key): ?string
{
    static \$config = null;
    if (\$config === null) {
        try {
            \$db = getDB();
            \$stmt = \$db->query("SELECT * FROM config LIMIT 1");
            \$config = \$stmt->fetch() ?: [];
        } catch (Exception \$e) {
            \$config = [];
        }
    }
    return \$config[\$key] ?? null;
}

/**
 * Refresh cached config.
 */
function refreshConfig(): void
{
    \$db = getDB();
    \$stmt = \$db->query("SELECT * FROM config LIMIT 1");
    \$GLOBALS['_config_cache'] = \$stmt->fetch() ?: [];
}

/**
 * Generate a safe filename for backups.
 *
 * @param string \$name
 * @return string
 */
function safeFilename(string \$name): string
{
    return preg_replace('/[^a-zA-Z0-9_\\-\\p{Han}.]/u', '_', \$name);
}

/**
 * Ensure a directory exists, create recursively if not.
 *
 * @param string \$path
 * @return bool
 */
function ensureDir(string \$path): bool
{
    if (!is_dir(\$path)) {
        return mkdir(\$path, 0755, true);
    }
    return true;
}
PHP;

        file_put_contents(__DIR__ . '/config.php', $configContent);

        // Append additional utility functions that aren't in the template
        $appendFunctions = <<<'PHPFUNC'

// --------------------------------------------------
// Encryption Helpers
// --------------------------------------------------
define('APP_SECRET', hash('sha256', DB_HOST . DB_NAME . DB_USER . __FILE__));

function encryptValue(string $plaintext): string
{
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', APP_SECRET, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decryptValue(string $encoded): string
{
    $data = base64_decode($encoded);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'aes-256-cbc', APP_SECRET, 0, $iv);
}

function normalizeBackupPath(string $path, ?bool $isAbsolute = null): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($isAbsolute === null) {
        $isAbsolute = (strpos($path, '/') === 0 && stripos($path, '/backups') !== 0);
    }
    if ($isAbsolute) {
        $result = preg_replace('#/+#', '/', '/' . $path);
        return rtrim($result, '/') . '/';
    }
    if (stripos($path, '/backups/') === 0) {
        $path = substr($path, 9);
    } elseif (stripos($path, '/backups') === 0) {
        $path = substr($path, 8);
    }
    $path = ltrim($path, '/');
    $result = rtrim(BACKUP_ROOT, '/') . '/' . $path;
    return preg_replace('#/+#', '/', $result);
}

function createBackupZip(string $sourceDir, string $destFile, array $selectedItems = [], ?PDO $db = null, ?int $jobId = null): array
{
    $sourceDir = rtrim(str_replace('\\', '/', realpath($sourceDir) ?: $sourceDir), '/') . '/';
    ignore_user_abort(true);
    @set_time_limit(0);

    $log = function(string $msg) use ($db, $jobId) {
        if ($db && $jobId) logJob($db, $jobId, 'info', $msg);
    };

    foreach ($selectedItems as $item) {
        $normalized = str_replace('\\', '/', trim($item));
        if ($normalized === '' || strpos($normalized, '..') !== false || strpos($normalized, '/') === 0) {
            return [false, "Invalid backup item: {$item}"];
        }
    }

    $destDir = dirname($destFile);
    $freeBytes = @disk_free_space($destDir);
    if ($freeBytes !== false) {
        if ($freeBytes < 100 * 1024 * 1024) {
            return [false, "磁盘空间不足（仅剩 " . formatSize($freeBytes) . "），备份中止"];
        }
    }

    $zipBin = null;
    foreach (['/usr/bin/zip', '/usr/local/bin/zip', '/bin/zip', '/usr/sbin/zip'] as $c) {
        if (@is_executable($c)) { $zipBin = $c; break; }
    }
    $hasExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));

    if ($zipBin && $hasExec) {
        $startTime = microtime(true);
        @unlink($destFile);
        $escSource = escapeshellarg($sourceDir);
        $escDest   = escapeshellarg($destFile);
        if (empty($selectedItems)) {
            $cmd = "cd {$escSource} && {$zipBin} -r -q -0 {$escDest} . 2>&1";
        } else {
            $items = implode(' ', array_map('escapeshellarg', $selectedItems));
            $cmd = "cd {$escSource} && {$zipBin} -r -q -0 {$escDest} {$items} 2>&1";
        }
        $output = []; $retCode = 0;
        @exec($cmd, $output, $retCode);
        if ($retCode === 0 && is_file($destFile) && filesize($destFile) > 0) {
            return [true, 1];
        }
        if (is_file($destFile) && filesize($destFile) > 0) {
            $fh = @fopen($destFile, 'rb');
            if ($fh) {
                $header = fread($fh, 4); fclose($fh);
                if ($header === "PK\x03\x04" || $header === "PK\x05\x06" || $header === "PK\x07\x08") {
                    return [true, 1];
                }
            }
        }
        @unlink($destFile);
        $errStr = implode('; ', array_slice(array_filter($output), -3));
        if (stripos($errStr, 'No space left') !== false || stripos($errStr, 'write error') !== false) {
            return [false, "磁盘空间不足，备份中止"];
        }
    }

    if (!class_exists('ZipArchive')) {
        return [false, 'ZipArchive 扩展未安装，无法创建备份'];
    }
    $freeBytes = @disk_free_space($destDir);
    if ($freeBytes !== false && $freeBytes < 100 * 1024 * 1024) {
        return [false, "磁盘空间不足（仅剩 " . formatSize($freeBytes) . "），无法继续"];
    }

    try {
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CATCH_GET_CHILD | RecursiveDirectoryIterator::SKIP_DOTS;
        $zip = new ZipArchive();
        if ($zip->open($destFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [false, '无法创建ZIP文件'];
        }
        $zip->setCompressionMethod(ZipArchive::CM_STORE);
        $fileCount = 0; $skipCount = 0;

        $iterations = empty($selectedItems)
            ? [$sourceDir]
            : array_map(function($item) use ($sourceDir) { return $sourceDir . ltrim($item, '/'); }, $selectedItems);

        foreach ($iterations as $path) {
            $path = str_replace('\\', '/', $path);
            if (is_file($path)) {
                if (is_readable($path)) {
                    $rp = ltrim(str_replace('\\', '/', str_replace($sourceDir, '', $path)), '/');
                    $zip->addFile($path, $rp);
                    $fileCount++;
                }
                continue;
            }
            if (!is_dir($path)) continue;
            try {
                $inner = new RecursiveDirectoryIterator($path, $flags);
            } catch (\UnexpectedValueException $e) { continue; }
            $iter = new RecursiveIteratorIterator($inner, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iter as $file) {
                try {
                    $fp = $file->getRealPath();
                    if ($fp === false) continue;
                    $fp = str_replace('\\', '/', $fp);
                    $rp = substr($fp, strlen($sourceDir));
                    if ($file->isDir()) { $zip->addEmptyDir($rp); }
                    else { if ($file->isReadable()) { $zip->addFile($fp, $rp); $fileCount++; } }
                } catch (\RuntimeException $e) { continue; }
            }
        }
        if ($fileCount === 0) { $zip->close(); @unlink($destFile); return [false, "无文件可备份"]; }
        if (!$zip->close()) { @unlink($destFile); return [false, '写入失败（磁盘满？）']; }
        return [true, $fileCount];
    } catch (Exception $e) {
        @unlink($destFile);
        return [false, '异常：' . $e->getMessage()];
    }
}

function logJob(PDO $db, ?int $jobId, string $level, string $message): void
{
    if (!$jobId) return;
    $line = '[' . date('H:i:s') . '] [' . strtoupper($level) . '] ' . $message;
    try {
        $db->prepare("UPDATE backup_jobs SET log_text = CONCAT(COALESCE(log_text, ''), CHAR(10 USING utf8mb4), ?) WHERE id = ?")
           ->execute([$line, $jobId]);
    } catch (Throwable $e) {}
}

function setJobStatus(PDO $db, ?int $jobId, string $status, string $message = ''): void
{
    if (!$jobId) return;
    try {
        $db->prepare("UPDATE backup_jobs SET status = ?, message = ? WHERE id = ?")
           ->execute([$status, $message, $jobId]);
    } catch (Throwable $e) {}
}

function compactJobIds(PDO $db): void
{
    try {
        $rows = $db->query("SELECT id FROM backup_jobs ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $nextId = 1;
        foreach ($rows as $rid) {
            if ((int)$rid === $nextId) { $nextId++; } else { break; }
        }
        $db->exec("ALTER TABLE backup_jobs AUTO_INCREMENT = $nextId");
    } catch (Throwable $e) {}
}

function sendResponseAndContinue(array $data): void
{
    while (ob_get_level()) ob_end_clean();
    ob_start();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $size = ob_get_length();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . $size);
    header('Connection: close');
    ob_end_flush(); @ob_flush(); @flush();
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    ignore_user_abort(true);
    set_time_limit(0);
}

function streamDownload(string $filePath, string $filename): void
{
    $fh = @fopen($filePath, 'rb');
    if (!$fh) {
        header('HTTP/1.1 500 Internal Server Error');
        exit;
    }
    $fileSize = @filesize($filePath);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    if ($fileSize !== false && $fileSize > 0) {
        header('Content-Length: ' . $fileSize);
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Accept-Ranges: bytes');
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', 'Off');
    @ini_set('memory_limit', '-1');
    @set_time_limit(0);
    stream_set_read_buffer($fh, 0);
    $chunkSize = 8 * 1024 * 1024;
    $flushEvery = 8;
    $chunks = 0;
    while (!feof($fh) && connection_aborted() === 0) {
        $chunk = fread($fh, $chunkSize);
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $chunks++;
        if ($chunks % $flushEvery === 0) { ob_flush(); flush(); }
    }
    ob_flush(); flush();
    fclose($fh);
    exit;
}
PHPFUNC;
        file_put_contents(__DIR__ . '/config.php', $appendFunctions, FILE_APPEND);

        // Create backups directory
        if (!is_dir(__DIR__ . '/backups')) {
            mkdir(__DIR__ . '/backups', 0755, true);
        }
        // Create .htaccess to prevent direct access
        file_put_contents(__DIR__ . '/backups/.htaccess', "Deny from all\n");

        // Create index.php in backups for extra safety
        file_put_contents(__DIR__ . '/backups/index.php', "<?php\nhttp_response_code(403);\nexit;\n");

        // Create install lock
        file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));

        $success = true;

    } catch (Exception $e) {
        $error = '安装失败：' . $e->getMessage();
        $success = false;
    }

    installHeader();
    ?>
<div class="install-card">
    <div class="install-logo">
        <h1>MCServerBackupPanel</h1>
        <p>SZY创新工作室</p>
    </div>

    <?php if ($success): ?>
        <div style="text-align:center;">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--success-light);color:var(--success);display:inline-flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:16px;">
                &#10003;
            </div>
            <h2 style="margin-bottom:8px;">安装成功</h2>
            <p style="color:var(--text-secondary);margin-bottom:24px;">
                MCServerBackupPanel 已成功安装。<br>安装文件即将自动删除。
            </p>
            <a href="login.php" class="btn btn-primary">前往登录</a>
        </div>
        <?php
        // Delete self
        @unlink(__FILE__);
        ?>
    <?php else: ?>
        <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
        <p class="text-center mt-16"><a href="install.php" class="btn btn-secondary">返回重新填写</a></p>
    <?php endif; ?>
</div>
    <?php
    installFooter();

endif;
