<?php
// index.php - 公开备份下载页
require_once 'config.php';

$pdo = getDB();

// 获取公开的备份
$stmt = $pdo->prepare("
    SELECT b.*, s.name as server_name 
    FROM backups b 
    JOIN servers s ON b.server_id = s.id 
    WHERE b.is_public = 1 
    ORDER BY b.created_at DESC
");
$stmt->execute();
$publicBackups = $stmt->fetchAll();

$siteConfig = getSiteConfig();

// 处理下载
// 处理下载 - 直接重定向到文件 URL
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $backup_id = (int)$_GET['download'];
    $stmt = $pdo->prepare("SELECT b.*, s.name as server_name FROM backups b JOIN servers s ON b.server_id = s.id WHERE b.id = ? AND b.is_public = 1");
    $stmt->execute([$backup_id]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($backup && file_exists($backup['filepath'])) {
        // 构建可直接访问的文件 URL
        // 假设 backups 目录在网站根目录下，例如：/www/wwwroot/example.com/backups/
        // 那么 URL 路径就是 /backups/服务器名/文件名
        $server_name = urlencode($backup['server_name']);
        $filename = urlencode($backup['filename']);
        $file_url = "/backups/{$server_name}/{$filename}";
        
        // 302 临时重定向到文件真实地址
        header('HTTP/1.1 302 Found');
        header('Location: ' . $file_url);
        exit;
    } else {
        $error = '文件不存在或已被删除';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>备份下载 - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
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
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 50px;
        }
        .header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .header h1 {
            color: white;
            font-size: 32px;
            margin-bottom: 10px;
        }
        .header p {
            color: rgba(255,255,255,0.8);
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            margin-bottom: 30px;
        }
        .card h2 {
            color: #1a1a2e;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        .backup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .backup-item {
            background: #f7fafc;
            border-radius: 15px;
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .backup-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .backup-server {
            font-size: 18px;
            font-weight: bold;
            color: #1a1a2e;
            margin-bottom: 10px;
        }
        .backup-server .icon {
            margin-right: 8px;
        }
        .backup-filename {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 10px;
            word-break: break-all;
        }
        .backup-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #718096;
            margin-bottom: 15px;
        }
        .download-btn {
            display: inline-block;
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .download-btn:hover {
            transform: translateY(-2px);
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        .empty-state .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: rgba(255,255,255,0.7);
            font-size: 12px;
        }
        .footer a {
            color: white;
            text-decoration: none;
        }
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .backup-grid {
                grid-template-columns: 1fr;
            }
            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <?php if ($siteConfig['site_logo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $siteConfig['site_logo'])): ?>
                <img src="<?php echo htmlspecialchars($siteConfig['site_logo']); ?>" alt="Logo">
            <?php endif; ?>
            <h1><?php echo htmlspecialchars($siteConfig['site_name']); ?></h1>
            <p>服务器地图备份下载中心</p>
        </div>
        
        <div class="card">
            <h2>📦 公开备份列表</h2>
            
            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (empty($publicBackups)): ?>
                <div class="empty-state">
                    <div class="icon">📭</div>
                    <p>暂无公开备份文件</p>
                    <p style="font-size: 12px; margin-top: 10px;">管理员可在后台设置备份为公开</p>
                </div>
            <?php else: ?>
                <div class="backup-grid">
                    <?php foreach ($publicBackups as $backup): ?>
                        <div class="backup-item">
                            <div class="backup-server">
                                <span class="icon">🖥️</span>
                                <?php echo htmlspecialchars($backup['server_name']); ?>
                            </div>
                            <div class="backup-filename">
                                📄 <?php echo htmlspecialchars($backup['filename']); ?>
                            </div>
                            <div class="backup-meta">
                                <span>📅 <?php echo date('Y-m-d H:i:s', strtotime($backup['created_at'])); ?></span>
                                <span>💾 <?php echo round($backup['size'] / 1024 / 1024, 2); ?> MB</span>
                            </div>
                            <a href="?download=<?php echo $backup['id']; ?>" class="download-btn">⬇️ 下载备份</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            Powered by <a href="https://github.com/szyinnovationstudio/MCServerBackupPanel" target="_blank">MCServerBackupPanel</a> | 
            SZY创新工作室 版权所有
            <?php if ($siteConfig['footer_icp']): ?>
                | <a href="https://beian.miit.gov.cn/" target="_blank" style="color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars($siteConfig['footer_icp']); ?></a>
            <?php endif; ?>
            <?php if ($siteConfig['footer_ps']): ?>
                | <a href="http://www.beian.gov.cn/" target="_blank" style="color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars($siteConfig['footer_ps']); ?></a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>