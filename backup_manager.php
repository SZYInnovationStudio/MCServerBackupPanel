<?php
// backup_manager.php - 备份管理页面
require_once 'config.php';
checkLogin();

$pdo = getDB();
$message = '';
$error = '';

// 处理公开/取消公开
if (isset($_GET['toggle_public']) && is_numeric($_GET['toggle_public'])) {
    $backup_id = (int)$_GET['toggle_public'];
    $stmt = $pdo->prepare("SELECT is_public FROM backups WHERE id = ?");
    $stmt->execute([$backup_id]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($backup) {
        $result = setBackupPublic($backup_id, !$backup['is_public']);
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

// 处理删除备份
if (isset($_GET['delete_backup']) && is_numeric($_GET['delete_backup'])) {
    $result = deleteBackup((int)$_GET['delete_backup']);
    if ($result['success']) {
        $message = $result['message'];
    } else {
        $error = $result['message'];
    }
}

// 获取所有备份（按服务器分组）
$servers = $pdo->query("SELECT id, name FROM servers ORDER BY name")->fetchAll();

$allBackups = [];
foreach ($servers as $server) {
    $stmt = $pdo->prepare("SELECT * FROM backups WHERE server_id = ? ORDER BY created_at DESC");
    $stmt->execute([$server['id']]);
    $allBackups[$server['name']] = $stmt->fetchAll();
}

$siteConfig = getSiteConfig();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>备份管理 - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card h3 .badge {
            font-size: 12px;
            padding: 2px 8px;
            background: #667eea;
            color: white;
            border-radius: 20px;
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
            display: inline-block;
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
        .btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            border: none;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5a67d8;
        }
        .btn-success {
            background: #48bb78;
            color: white;
        }
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        .btn-sm {
            padding: 4px 10px;
            font-size: 11px;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #718096;
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
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .actions {
                flex-direction: column;
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
            <a href="tasks.php" class="nav-item">
                <span class="icon">📋</span>
                <span>备份任务</span>
            </a>
            <a href="backup_manager.php" class="nav-item active">
                <span class="icon">💾</span>
                <span>备份管理</span>
            </a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>备份管理</h2>
            <div class="user-info">
                <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <a href="logout.php" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="message message-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message message-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php
        $hasBackups = false;
        foreach ($allBackups as $serverName => $backups):
            if (empty($backups)) continue;
            $hasBackups = true;
        ?>
        <div class="card">
            <h3>
                🖥️ <?php echo htmlspecialchars($serverName); ?>
                <span class="badge"><?php echo count($backups); ?> 个备份</span>
            </h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>文件名</th>
                            <th>大小</th>
                            <th>创建时间</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($backup['filename']); ?></strong>
                                <br>
                                <small style="color: #718096;"><?php echo htmlspecialchars($backup['filepath']); ?></small>
                            </td>
                            <td class="size-format"><?php echo round($backup['size'] / 1024 / 1024, 2); ?> MB</td>
                            <td class="size-format"><?php echo date('Y-m-d H:i:s', strtotime($backup['created_at'])); ?></td>
                            <td>
                                <span class="badge <?php echo $backup['is_public'] ? 'badge-public' : 'badge-private'; ?>">
                                    <?php echo $backup['is_public'] ? '🌍 公开' : '🔒 私有'; ?>
                                </span>
                            </td>
                            <td class="actions">
                                <?php if ($backup['is_public']): ?>
                                    <a href="../index.php?download=<?php echo $backup['id']; ?>" class="btn btn-primary btn-sm" target="_blank">📥 下载</a>
                                <?php else: ?>
                                    <button class="btn btn-primary btn-sm" disabled style="opacity:0.5;" title="私有备份不可下载">📥 下载</button>
                                <?php endif; ?>
                                <a href="?toggle_public=<?php echo $backup['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('确定要<?php echo $backup['is_public'] ? '取消公开' : '公开'; ?>此备份吗？')">
                                    <?php echo $backup['is_public'] ? '🔒 取消公开' : '🌍 公开'; ?>
                                </a>
                                <a href="?delete_backup=<?php echo $backup['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定删除此备份吗？此操作不可恢复！')">
                                    🗑️ 删除
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (!$hasBackups): ?>
        <div class="card">
            <div class="empty-state">
                <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
                <p>暂无备份文件</p>
                <p style="font-size: 12px; margin-top: 8px;">请在「备份任务」中创建任务并执行备份</p>
            </div>
        </div>
        <?php endif; ?>
        
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