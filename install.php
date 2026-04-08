<?php
// install.php - 安装向导
error_reporting(E_ALL);
ini_set('display_errors', 1);

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

if ($step == 2 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_user = $_POST['db_user'] ?? 'root';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = $_POST['db_name'] ?? 'mcsbp';
    $site_name = $_POST['site_name'] ?? 'MCServerBackupPanel';
    $site_logo = $_POST['site_logo'] ?? '/assets/default-logo.png';
    $footer_icp = $_POST['footer_icp'] ?? '';
    $footer_ps = $_POST['footer_ps'] ?? '';
    $admin_user = $_POST['admin_user'] ?? '';
    $admin_pass = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    
    if (empty($admin_user) || empty($admin_pass)) {
        $error = '请填写管理员用户名和密码';
    } elseif ($admin_pass !== $admin_pass2) {
        $error = '两次输入的密码不一致';
    } elseif (strlen($admin_pass) < 6) {
        $error = '密码长度至少6位';
    } else {
        try {
            // 测试数据库连接
            $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 创建数据库
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");
            
            // 创建表结构
            $sql = "
            -- 网站设置表
            CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_name VARCHAR(255) NOT NULL DEFAULT 'MCServerBackupPanel',
                site_logo VARCHAR(500) NOT NULL DEFAULT '/assets/default-logo.png',
                footer_icp VARCHAR(255) DEFAULT '',
                footer_ps VARCHAR(255) DEFAULT '',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );

            -- 管理员表
            CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            -- 服务器表
            CREATE TABLE IF NOT EXISTS servers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                path VARCHAR(500) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            -- 备份任务表
            CREATE TABLE IF NOT EXISTS tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                server_id INT NOT NULL,
                source_path VARCHAR(500) NOT NULL,
                backup_path VARCHAR(500) NOT NULL DEFAULT 'backups',
                backup_name VARCHAR(255) NOT NULL,
                schedule_time TIME DEFAULT NULL,
                enabled TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
            );

            -- 备份记录表
            CREATE TABLE IF NOT EXISTS backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                server_id INT NOT NULL,
                task_id INT,
                filename VARCHAR(255) NOT NULL,
                filepath VARCHAR(500) NOT NULL,
                size BIGINT DEFAULT 0,
                is_public TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
            );
            ";
            $pdo->exec($sql);
            
            // 插入默认设置
            $stmt = $pdo->prepare("INSERT INTO settings (id, site_name, site_logo, footer_icp, footer_ps) VALUES (1, ?, ?, ?, ?)");
            $stmt->execute([$site_name, $site_logo, $footer_icp, $footer_ps]);
            
            // 创建管理员
            $hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
            $stmt->execute([$admin_user, $hashed]);
            
            // 创建必要目录
            $dirs = ['backups', 'logs', 'assets'];
            foreach ($dirs as $dir) {
                if (!is_dir(__DIR__ . '/' . $dir)) {
                    mkdir(__DIR__ . '/' . $dir, 0755, true);
                }
            }
            
            // 创建默认logo占位文件
            $defaultLogo = __DIR__ . '/assets/default-logo.png';
            if (!file_exists($defaultLogo)) {
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="#667eea"/><text x="50" y="67" text-anchor="middle" fill="white" font-size="40" font-weight="bold">B</text></svg>';
                file_put_contents($defaultLogo, $svg);
            }
            
            // 生成config.php
            $configContent = '<?php
// config.php - 系统配置文件
session_start();

define(\'DB_HOST\', \'' . addslashes($db_host) . '\');
define(\'DB_USER\', \'' . addslashes($db_user) . '\');
define(\'DB_PASS\', \'' . addslashes($db_pass) . '\');
define(\'DB_NAME\', \'' . addslashes($db_name) . '\');

// 获取网站根目录
define(\'SITE_ROOT\', __DIR__);
define(\'BACKUP_ROOT\', SITE_ROOT . \'/backups\');

// 获取网站配置
function getSiteConfig() {
    static $config = null;
    if ($config === null) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$config) {
                $config = [
                    \'site_name\' => \'MCServerBackupPanel\',
                    \'site_logo\' => \'/assets/default-logo.png\',
                    \'footer_icp\' => \'\',
                    \'footer_ps\' => \'\'
                ];
            }
        } catch (PDOException $e) {
            $config = [
                \'site_name\' => \'MCServerBackupPanel\',
                \'site_logo\' => \'/assets/default-logo.png\',
                \'footer_icp\' => \'\',
                \'footer_ps\' => \'\'
            ];
        }
    }
    return $config;
}

// 检查登录状态
function checkLogin() {
    if (!isset($_SESSION[\'admin_logged_in\']) || $_SESSION[\'admin_logged_in\'] !== true) {
        header(\'Location: login.php\');
        exit;
    }
}

// 获取数据库连接
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    return $pdo;
}

// 记录日志
function logMessage($message, $type = \'info\') {
    $logDir = SITE_ROOT . \'/logs\';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    if (is_writable($logDir)) {
        $logFile = $logDir . \'/backup.log\';
        $timestamp = date(\'Y-m-d H:i:s\');
        @file_put_contents($logFile, "[$timestamp][$type] $message\\n", FILE_APPEND);
    }
}

// 获取完整的源目录路径（相对于服务器目录）
function getSourcePath($serverPath, $sourcePath) {
    // 如果是绝对路径（以 / 开头），直接返回
    if (preg_match(\'/^\\//\', $sourcePath)) {
        return rtrim($sourcePath, \'/\\\\\');
    }
    // 相对路径，基于服务器目录
    return rtrim($serverPath, \'/\\\\\') . \'/\' . ltrim($sourcePath, \'/\\\\\');
}

// 获取备份目录（网站目录下的 backups 文件夹）
function getBackupDir($serverName) {
    $backupDir = BACKUP_ROOT . \'/\' . preg_replace(\'/[^a-zA-Z0-9_\\-]/u\', \'_\', $serverName);
    return rtrim($backupDir, \'/\\\\\');
}

// 执行备份
function executeBackup($taskId) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT t.*, s.name as server_name, s.path as server_path FROM tasks t JOIN servers s ON t.server_id = s.id WHERE t.id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$task) {
            return [\'success\' => false, \'message\' => \'备份任务不存在\'];
        }
        
        // 获取完整的源目录路径
        $sourceDir = getSourcePath($task[\'server_path\'], $task[\'source_path\']);
        
        if (!is_dir($sourceDir)) {
            return [\'success\' => false, \'message\' => \'源目录不存在: \' . $sourceDir];
        }
        
        if (!is_readable($sourceDir)) {
            return [\'success\' => false, \'message\' => \'源目录不可读: \' . $sourceDir];
        }
        
        // 获取备份目录（网站目录下）
        $backupDir = getBackupDir($task[\'server_name\']);
        
        // 创建备份目录
        if (!is_dir($backupDir)) {
            $oldUmask = umask(0);
            if (!@mkdir($backupDir, 0755, true)) {
                umask($oldUmask);
                return [\'success\' => false, \'message\' => \'无法创建备份目录: \' . $backupDir];
            }
            umask($oldUmask);
        }
        
        if (!is_writable($backupDir)) {
            return [\'success\' => false, \'message\' => \'备份目录不可写: \' . $backupDir];
        }
        
        // 生成备份文件名
        $date = date(\'Y-m-d_H-i-s\');
        $filename = str_replace(\'{date}\', $date, $task[\'backup_name\']);
        $filename = str_replace(\'{server}\', $task[\'server_name\'], $filename);
        if (!preg_match(\'/\.zip$/i\', $filename)) {
            $filename .= \'.zip\';
        }
        $filename = preg_replace(\'/[^a-zA-Z0-9_\\-\\.]/u\', \'_\', $filename);
        $backupFile = $backupDir . \'/\' . $filename;
        
        // 创建备份
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [\'success\' => false, \'message\' => \'无法创建备份文件\'];
        }
        
        // 递归添加文件
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $addedCount = 0;
        foreach ($files as $file) {
            if ($file->isFile() && $file->isReadable()) {
                $filePath = $file->getRealPath();
                if ($filePath === false) continue;
                $relativePath = substr($filePath, strlen($sourceDir) + 1);
                if ($relativePath === false || $relativePath === \'\') continue;
                $zip->addFile($filePath, $relativePath);
                $addedCount++;
            }
        }
        
        $zip->close();
        
        if (!file_exists($backupFile)) {
            return [\'success\' => false, \'message\' => \'备份文件创建失败\'];
        }
        
        $size = filesize($backupFile);
        
        $stmt = $pdo->prepare("INSERT INTO backups (server_id, task_id, filename, filepath, size, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$task[\'server_id\'], $taskId, $filename, $backupFile, $size]);
        
        logMessage("备份完成: {$task[\'server_name\']} - {$filename} (文件数: $addedCount, 大小: " . round($size/1024/1024, 2) . "MB)", \'info\');
        
        return [\'success\' => true, \'message\' => "备份成功！共备份 $addedCount 个文件", \'file\' => $backupFile];
        
    } catch (Exception $e) {
        logMessage("备份失败: " . $e->getMessage(), \'error\');
        return [\'success\' => false, \'message\' => \'备份失败: \' . $e->getMessage()];
    }
}

// 恢复备份
function restoreBackup($backupId) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT b.*, s.path as server_path, s.name as server_name FROM backups b JOIN servers s ON b.server_id = s.id WHERE b.id = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$backup) {
            return [\'success\' => false, \'message\' => \'备份不存在\'];
        }
        
        if (!file_exists($backup[\'filepath\'])) {
            return [\'success\' => false, \'message\' => \'备份文件不存在\'];
        }
        
        $serverPath = $backup[\'server_path\'];
        if (empty($serverPath)) {
            return [\'success\' => false, \'message\' => \'服务器路径为空\'];
        }
        
        if (!is_dir($serverPath)) {
            return [\'success\' => false, \'message\' => \'服务器目录不存在: \' . $serverPath];
        }
        
        if (!is_writable($serverPath)) {
            return [\'success\' => false, \'message\' => \'服务器目录不可写: \' . $serverPath];
        }
        
        // 清空服务器目录
        $files = glob($serverPath . \'/*\');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $subFiles = glob($file . \'/*\');
                foreach ($subFiles as $subFile) {
                    if (is_file($subFile)) unlink($subFile);
                }
                rmdir($file);
            }
        }
        
        $zip = new ZipArchive();
        if ($zip->open($backup[\'filepath\']) !== true) {
            return [\'success\' => false, \'message\' => \'无法打开备份文件\'];
        }
        
        $zip->extractTo($serverPath);
        $zip->close();
        
        logMessage("恢复完成: {$backup[\'server_name\']} 从备份 {$backup[\'filename\']}", \'info\');
        
        return [\'success\' => true, \'message\' => \'恢复成功\'];
        
    } catch (Exception $e) {
        logMessage("恢复失败: " . $e->getMessage(), \'error\');
        return [\'success\' => false, \'message\' => \'恢复失败: \' . $e->getMessage()];
    }
}

// 设置备份公开状态
function setBackupPublic($backupId, $isPublic) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE backups SET is_public = ? WHERE id = ?");
        $stmt->execute([$isPublic ? 1 : 0, $backupId]);
        return [\'success\' => true, \'message\' => $isPublic ? \'已公开\' : \'已取消公开\'];
    } catch (Exception $e) {
        return [\'success\' => false, \'message\' => \'操作失败: \' . $e->getMessage()];
    }
}

// 删除备份
function deleteBackup($backupId) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT filepath FROM backups WHERE id = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($backup && file_exists($backup[\'filepath\'])) {
            unlink($backup[\'filepath\']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ?");
        $stmt->execute([$backupId]);
        
        return [\'success\' => true, \'message\' => \'备份已删除\'];
    } catch (Exception $e) {
        return [\'success\' => false, \'message\' => \'删除失败: \' . $e->getMessage()];
    }
}
?>';
            file_put_contents(__DIR__ . '/config.php', $configContent);
            
            $success = '安装成功！';
            $step = 3;
            
        } catch (PDOException $e) {
            $error = '数据库错误: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = '安装错误: ' . $e->getMessage();
        }
    }
}

if ($step == 3 && isset($_GET['delete'])) {
    if (unlink(__FILE__)) {
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCServerBackupPanel - 安装向导</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .install-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            max-width: 650px;
            width: 100%;
            overflow: hidden;
        }
        .install-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .install-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .install-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .install-body {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .success {
            background: #c6f6d5;
            color: #22543d;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 0 20px;
        }
        .step {
            text-align: center;
            flex: 1;
            position: relative;
        }
        .step .circle {
            width: 40px;
            height: 40px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
            color: #718096;
        }
        .step.active .circle {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .step .label {
            font-size: 12px;
            color: #718096;
        }
        .step.active .label {
            color: #667eea;
            font-weight: bold;
        }
        h3 {
            margin-bottom: 20px;
            color: #333;
            font-size: 18px;
        }
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #e2e8f0;
        }
        .check-item {
            padding: 10px;
            margin: 5px 0;
            background: #f7fafc;
            border-radius: 8px;
            font-size: 14px;
        }
        .check-pass {
            color: #22543d;
        }
        .check-fail {
            color: #c53030;
        }
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        @media (max-width: 640px) {
            .row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <h1>MCServerBackupPanel</h1>
            <p>服务器备份管理系统 - 安装向导</p>
        </div>
        
        <div class="step-indicator">
            <div class="step <?php echo $step >= 1 ? 'active' : ''; ?>">
                <div class="circle">1</div>
                <div class="label">环境检查</div>
            </div>
            <div class="step <?php echo $step >= 2 ? 'active' : ''; ?>">
                <div class="circle">2</div>
                <div class="label">配置信息</div>
            </div>
            <div class="step <?php echo $step >= 3 ? 'active' : ''; ?>">
                <div class="circle">3</div>
                <div class="label">完成安装</div>
            </div>
        </div>
        
        <div class="install-body">
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if ($step == 1): ?>
                <h3>📋 系统环境检查</h3>
                <?php
                $checks = [
                    'PHP版本 >= 7.3' => version_compare(PHP_VERSION, '7.3', '>='),
                    'PDO扩展' => extension_loaded('pdo'),
                    'PDO MySQL扩展' => extension_loaded('pdo_mysql'),
                    'ZipArchive扩展' => class_exists('ZipArchive'),
                    'JSON扩展' => function_exists('json_encode'),
                    '目录可写' => is_writable(__DIR__),
                ];
                foreach ($checks as $name => $result):
                ?>
                    <div class="check-item <?php echo $result ? 'check-pass' : 'check-fail'; ?>">
                        <?php echo $name; ?>: <?php echo $result ? '✓ 通过' : '✗ 失败'; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php 
                $allPass = true;
                foreach ($checks as $result) {
                    if (!$result) $allPass = false;
                }
                ?>
                
                <?php if ($allPass): ?>
                    <div style="margin-top: 30px;">
                        <a href="?step=2" class="btn" style="display: block; text-align: center; text-decoration: none;">下一步</a>
                    </div>
                <?php else: ?>
                    <div class="error" style="margin-top: 20px;">请解决上述问题后继续安装</div>
                <?php endif; ?>
                
            <?php elseif ($step == 2): ?>
                <h3>🗄️ 数据库配置</h3>
                <form method="POST">
                    <div class="row">
                        <div class="form-group">
                            <label>数据库主机</label>
                            <input type="text" name="db_host" value="localhost" required>
                        </div>
                        <div class="form-group">
                            <label>数据库端口</label>
                            <input type="text" name="db_port" value="3306">
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group">
                            <label>数据库用户名</label>
                            <input type="text" name="db_user" value="root" required>
                        </div>
                        <div class="form-group">
                            <label>数据库密码</label>
                            <input type="password" name="db_pass">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>数据库名称</label>
                        <input type="text" name="db_name" value="mcsbp" required>
                    </div>
                    
                    <hr>
                    
                    <h3>🌐 网站设置</h3>
                    <div class="row">
                        <div class="form-group">
                            <label>网站名称</label>
                            <input type="text" name="site_name" value="MCServerBackupPanel">
                        </div>
                        <div class="form-group">
                            <label>网站头像路径</label>
                            <input type="text" name="site_logo" value="/assets/default-logo.png">
                        </div>
                    </div>
                    <div class="row">
                        <div class="form-group">
                            <label>网站备案号（可选）</label>
                            <input type="text" name="footer_icp" placeholder="京ICP备xxxxxx号">
                        </div>
                        <div class="form-group">
                            <label>公网安备号（可选）</label>
                            <input type="text" name="footer_ps" placeholder="京公网安备xxxxxx号">
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h3>👤 管理员账号</h3>
                    <div class="row">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" name="admin_user" required>
                        </div>
                        <div class="form-group">
                            <label>密码</label>
                            <input type="password" name="admin_pass" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>确认密码</label>
                        <input type="password" name="admin_pass2" required>
                    </div>
                    
                    <button type="submit" class="btn">开始安装</button>
                </form>
                
            <?php elseif ($step == 3): ?>
                <h3>✅ 安装完成！</h3>
                <div class="success">
                    <strong>MCServerBackupPanel 已成功安装！</strong><br>
                    您现在可以登录管理后台了。
                </div>
                <div style="margin-top: 20px;">
                    <a href="?step=3&delete=1" class="btn" style="display: block; text-align: center; text-decoration: none;">进入管理后台</a>
                </div>
                <p style="margin-top: 20px; text-align: center; font-size: 12px; color: #718096;">
                    ⚠️ 提示：install.php 将在您点击进入后台后自动删除
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>