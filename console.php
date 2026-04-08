<?php
// console.php - 后台首页
require_once 'config.php';
checkLogin();

$pdo = getDB();

// 统计数据
$serversCount = $pdo->query("SELECT COUNT(*) FROM servers")->fetchColumn();
$tasksCount = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$backupsCount = $pdo->query("SELECT COUNT(*) FROM backups")->fetchColumn();
$totalSize = $pdo->query("SELECT SUM(size) FROM backups")->fetchColumn();

// 最近备份
$recentBackups = $pdo->query("SELECT b.*, s.name as server_name FROM backups b JOIN servers s ON b.server_id = s.id ORDER BY b.created_at DESC LIMIT 5")->fetchAll();

$siteConfig = getSiteConfig();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>控制台 - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card h3 {
            color: #718096;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #1a1a2e;
        }
        .card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card h3 {
            margin-bottom: 20px;
            color: #1a1a2e;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
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
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success {
            background: #c6f6d5;
            color: #22543d;
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
                transition: transform 0.3s;
            }
            .main-content {
                margin-left: 0;
            }
        }
        .size-format {
            font-size: 12px;
            color: #718096;
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
            <a href="console.php" class="nav-item active">
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
            <a href="backup_manager.php" class="nav-item">
                <span class="icon">💾</span>
                <span>备份管理</span>
            </a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <h2>控制台</h2>
            <div class="user-info">
                <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <a href="logout.php" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>服务器数量</h3>
                <div class="number"><?php echo $serversCount; ?></div>
            </div>
            <div class="stat-card">
                <h3>备份任务</h3>
                <div class="number"><?php echo $tasksCount; ?></div>
            </div>
            <div class="stat-card">
                <h3>备份文件</h3>
                <div class="number"><?php echo $backupsCount; ?></div>
            </div>
            <div class="stat-card">
                <h3>总备份大小</h3>
                <div class="number"><?php echo round($totalSize / 1024 / 1024, 2); ?> MB</div>
            </div>
        </div>
        
        <div class="card">
            <h3>最近备份记录</h3>
            <table>
                <thead>
                    <tr><th>服务器</th><th>备份文件</th><th>大小</th><th>时间</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBackups as $backup): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($backup['server_name']); ?></td>
                        <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                        <td><span class="size-format"><?php echo round($backup['size'] / 1024 / 1024, 2); ?> MB</span></td>
                        <td><?php echo htmlspecialchars($backup['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentBackups)): ?>
                    <tr><td colspan="4" style="text-align: center;">暂无备份记录</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
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