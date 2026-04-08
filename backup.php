<?php
// backup.php - 备份执行/列表/恢复
require_once 'config.php';
checkLogin();

$pdo = getDB();
$server_id = isset($_GET['server_id']) ? (int)$_GET['server_id'] : 0;
$message = '';
$error = '';

// 获取服务器信息
$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$server_id]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    header('Location: servers.php');
    exit;
}

// 处理立即备份
if (isset($_GET['backup_now'])) {
    $task_id = (int)$_GET['backup_now'];
    $result = executeBackup($task_id);
    if ($result['success']) {
        $message = $result['message'];
    } else {
        $error = $result['message'];
    }
}

// 处理恢复
if (isset($_POST['restore'])) {
    $backup_id = (int)$_POST['backup_id'];
    $result = restoreBackup($backup_id);
    if ($result['success']) {
        $message = $result['message'];
    } else {
        $error = $result['message'];
    }
}

// 处理公开/取消公开
if (isset($_GET['toggle_public']) && is_numeric($_GET['toggle_public'])) {
    $backup_id = (int)$_GET['toggle_public'];
    $stmt = $pdo->prepare("UPDATE backups SET is_public = NOT is_public WHERE id = ? AND server_id = ?");
    $stmt->execute([$backup_id, $server_id]);
    $message = '设置已更新';
}

// 处理删除备份
if (isset($_GET['delete_backup']) && is_numeric($_GET['delete_backup'])) {
    $backup_id = (int)$_GET['delete_backup'];
    $stmt = $pdo->prepare("SELECT filepath FROM backups WHERE id = ? AND server_id = ?");
    $stmt->execute([$backup_id, $server_id]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($backup && file_exists($backup['filepath'])) {
        unlink($backup['filepath']);
    }
    $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ? AND server_id = ?");
    $stmt->execute([$backup_id, $server_id]);
    $message = '备份已删除';
}

// 获取该服务器的备份任务
$tasks = $pdo->prepare("SELECT * FROM tasks WHERE server_id = ?");
$tasks->execute([$server_id]);
$tasks = $tasks->fetchAll();

// 获取备份列表
$backups = $pdo->prepare("SELECT * FROM backups WHERE server_id = ? ORDER BY created_at DESC");
$backups->execute([$server_id]);
$backups = $backups->fetchAll();

$siteConfig = getSiteConfig();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>备份管理 - <?php echo htmlspecialchars($server['name']); ?> - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f7fafc;
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 100;
        }
        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-header img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin-bottom: 15px;
        }
        .sidebar-header h3 {
            font-size: 18px;
        }
        .sidebar-nav {
            padding: 20px 0;
        }
        .nav-item {
            padding: 12px 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
        }
        .nav-item:hover, .nav-item.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .nav-item .icon {
            width: 20px;
            text-align: center;
        }
        .main-content {
            margin-left: 260px;
            padding: 20px;
        }
        .top-bar {
            background: white;
            border-radius: 15px;
            padding: 15px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .top-bar h2 {
            color: #1a1a2e;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .logout-btn {
            padding: 8px 16px;
            background: #ef4444;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            transition: background 0.3s;
        }
        .logout-btn:hover {
            background: #dc2626;
        }
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }
        .card h3 {
            margin-bottom: 20px;
            color: #1a1a2e;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        .btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-secondary {
            background: #718096;
        }
        .btn-success {
            background: #48bb78;
        }
        .btn-warning {
            background: #ed8936;
        }
        .btn-danger {
            background: #ef4444;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th {
            color: #718096;
            font-weight: 600;
            font-size: 14px;
        }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .message {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .message-success {
            background: #c6f6d5;
            color: #22543d;
        }
        .message-error {
            background: #fed7d7;
            color: #c53030;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-public {
            background: #bee3f8;
            color: #2c5282;
        }
        .badge-private {
            background: #e2e8f0;
            color: #4a5568;
        }
        .size-format {
            font-size: 12px;
            color: #718096;
        }
        .back-link {
            margin-bottom: 20px;
        }
        .back-link a {
            color: #667eea;
            text-decoration: none;
        }
        .footer {
            text-align: center;
            padding: 20px;
            margin-top: 40px;
            color: #718096;
            font-size: 12px;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        .task-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .task-item {
            background: #f7fafc;
            border-radius: 10px;
            padding: 15px;
            flex: 1;
            min-width: 200px;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <?php if ($siteConfig['site_logo']): ?>
                <img src="<?php echo htmlspecialchars($siteConfig['site_logo']); ?>" alt="Logo">
            <?php endif; ?>
            <h3><?php echo htmlspecialchars($siteConfig['site_name']); ?></h3>
        </div>
        <div class="sidebar-nav">
            <a href="console.php" class="nav-item">
                <span class="icon">📊</span>
                <span>控制台</span>
            </a>
            <a href="setting.php" class="nav-item">
                <span class="icon">⚙️</span>
                <span>网站设置</span>
            </a>
            <a href="servers.php" class="nav-item">
                <span class="icon">🖥️</span>
                <span>服务器管理</span>
            </a>
            <a href="tasks.php" class="nav-item active">
                <span class="icon">📋</span>
                <span>备份任务</span>
            </a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>备份管理 - <?php echo htmlspecialchars($server['name']); ?></h2>
            <div class="user-info">
                <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <a href="logout.php" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <div class="back-link">
            <a href="servers.php">← 返回服务器列表</a>
        </div>
        
        <?php if ($message): ?>
            <div class="message message-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message message-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h3>备份任务</h3>
            <?php if (empty($tasks)): ?>
                <p style="color: #718096;">暂无备份任务，请先 <a href="tasks.php">创建备份任务</a></p>
            <?php else: ?>
                <div class="task-list">
                    <?php foreach ($tasks as $task): ?>
                        <div class="task-item">
                            <strong>📁 <?php echo htmlspecialchars($task['backup_name']); ?></strong><br>
                            <small>源路径: <?php echo htmlspecialchars($task['source_path']); ?></small><br>
                            <small>保存至: <?php echo htmlspecialchars($task['backup_path']); ?></small><br>
                            <a href="?server_id=<?php echo $server_id; ?>&backup_now=<?php echo $task['id']; ?>" class="btn btn-success btn-sm" style="margin-top: 10px;" onclick="return confirm('确定立即执行备份吗？')">立即备份</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>备份列表</h3>
            <?php if (empty($backups)): ?>
                <p style="color: #718096; text-align: center; padding: 40px;">暂无备份文件</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>文件名</th><th>大小</th><th>创建时间</th><th>状态</th><th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($backup['filename']); ?></strong></td>
                            <td><span class="size-format"><?php echo round($backup['size'] / 1024 / 1024, 2); ?> MB</span></td>
                            <td><?php echo htmlspecialchars($backup['created_at']); ?></td>
                            <td>
                                <span class="badge <?php echo $backup['is_public'] ? 'badge-public' : 'badge-private'; ?>">
                                    <?php echo $backup['is_public'] ? '公开' : '私有'; ?>
                                </span>
                            </td>
                            <td class="actions">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('确定要从这个备份恢复吗？这将覆盖当前服务器文件！')">
                                    <input type="hidden" name="backup_id" value="<?php echo $backup['id']; ?>">
                                    <button type="submit" name="restore" class="btn btn-warning btn-sm">恢复</button>
                                </form>
                                <a href="?server_id=<?php echo $server_id; ?>&toggle_public=<?php echo $backup['id']; ?>" class="btn btn-secondary btn-sm">
                                    <?php echo $backup['is_public'] ? '取消公开' : '公开下载'; ?>
                                </a>
                                <a href="?server_id=<?php echo $server_id; ?>&delete_backup=<?php echo $backup['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定删除此备份吗？')">删除</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            Powered by <a href="https://github.com/szyinnovationstudio/MCServerBackupPanel" target="_blank">MCServerBackupPanel</a> | 
            SZY创新工作室 版权所有
            <?php if ($siteConfig['footer_icp']): ?>
                | <a href="https://beian.miit.gov.cn/" target="_blank"><?php echo htmlspecialchars($siteConfig['footer_icp']); ?></a>
            <?php endif; ?>
            <?php if ($siteConfig['footer_ps']): ?>
                | <a href="http://www.beian.gov.cn/" target="_blank"><?php echo htmlspecialchars($siteConfig['footer_ps']); ?></a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>