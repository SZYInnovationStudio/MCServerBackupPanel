<?php
// setting.php - 网站设置
require_once 'config.php';
checkLogin();

$pdo = getDB();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $site_name = $_POST['site_name'] ?? 'MCServerBackupPanel';
    $site_logo = $_POST['site_logo'] ?? '/assets/default-logo.png';
    $footer_icp = $_POST['footer_icp'] ?? '';
    $footer_ps = $_POST['footer_ps'] ?? '';
    
    try {
        $stmt = $pdo->prepare("UPDATE settings SET site_name = ?, site_logo = ?, footer_icp = ?, footer_ps = ? WHERE id = 1");
        $stmt->execute([$site_name, $site_logo, $footer_icp, $footer_ps]);
        $message = '设置已保存';
    } catch (PDOException $e) {
        $error = '保存失败: ' . $e->getMessage();
    }
}

$settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $settings = [
        'site_name' => 'MCServerBackupPanel',
        'site_logo' => '/assets/default-logo.png',
        'footer_icp' => '',
        'footer_ps' => ''
    ];
}

$siteConfig = getSiteConfig();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站设置 - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
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
            max-width: 800px;
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
        input, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        textarea {
            resize: vertical;
            min-height: 80px;
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
        }
        .btn:hover {
            transform: translateY(-2px);
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
            <a href="setting.php" class="nav-item active">
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
            <h2>网站设置</h2>
            <div class="user-info">
                <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <a href="logout.php" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <div class="card">
            <?php if ($message): ?>
                <div class="message message-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message message-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>网站名称</label>
                    <input type="text" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>网站头像路径</label>
                    <input type="text" name="site_logo" value="<?php echo htmlspecialchars($settings['site_logo']); ?>" placeholder="/assets/logo.png">
                    <small style="color: #718096;">建议将图片放在 assets 目录下</small>
                </div>
                <div class="form-group">
                    <label>网站备案号（可选）</label>
                    <input type="text" name="footer_icp" value="<?php echo htmlspecialchars($settings['footer_icp']); ?>" placeholder="京ICP备xxxxxx号">
                </div>
                <div class="form-group">
                    <label>公网安备号（可选）</label>
                    <input type="text" name="footer_ps" value="<?php echo htmlspecialchars($settings['footer_ps']); ?>" placeholder="京公网安备xxxxxx号">
                </div>
                <button type="submit" class="btn">保存设置</button>
            </form>
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