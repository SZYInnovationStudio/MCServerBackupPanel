<?php
// servers.php - 服务器管理
require_once 'config.php';
checkLogin();

$pdo = getDB();
$message = '';
$error = '';

// 处理删除
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        // 先检查是否有相关的备份任务
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE server_id = ?");
        $stmt->execute([$_GET['delete']]);
        $taskCount = $stmt->fetchColumn();
        
        if ($taskCount > 0) {
            $error = '无法删除：该服务器有 ' . $taskCount . ' 个备份任务，请先删除相关任务';
        } else {
            $stmt = $pdo->prepare("DELETE FROM servers WHERE id = ?");
            $stmt->execute([$_GET['delete']]);
            $message = '服务器已删除';
        }
    } catch (PDOException $e) {
        $error = '删除失败: ' . $e->getMessage();
    }
}

// 处理自动检测
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'auto') {
        $mcsmanager_path = $_POST['mcsmanager_path'] ?? '/opt/mcsmanager/daemon/data/InstanceConfig';
        
        // 扫描实例目录
        $instances = [];
        
        // 支持两种路径格式
        $paths_to_check = [
            $mcsmanager_path,
            $mcsmanager_path . '/InstanceConfig',
            '/opt/mcsmanager/daemon/data/InstanceConfig'
        ];
        
        foreach ($paths_to_check as $check_path) {
            if (is_dir($check_path)) {
                $files = glob($check_path . '/*.json');
                foreach ($files as $file) {
                    $content = @file_get_contents($file);
                    if ($content) {
                        $data = json_decode($content, true);
                        if ($data && isset($data['nickname'])) {
                            // 获取服务器路径（cwd 字段）
                            $serverPath = $data['cwd'] ?? '';
                            if (empty($serverPath) && isset($data['path'])) {
                                $serverPath = $data['path'];
                            }
                            
                            $instances[] = [
                                'name' => $data['nickname'],
                                'path' => $serverPath,
                                'type' => $data['type'] ?? 'unknown',
                                'file' => $file
                            ];
                        }
                    }
                }
                break;
            }
        }
        
        if (empty($instances)) {
            $error = '未检测到任何实例，请检查 MCSManager Daemon 安装路径是否正确';
        } else {
            $_SESSION['detected_instances'] = $instances;
            $message = '检测到 ' . count($instances) . ' 个实例，请选择要添加的服务器';
        }
    } 
    elseif ($_POST['action'] == 'add_selected' && isset($_POST['selected_servers'])) {
        $selected = $_POST['selected_servers'];
        $addedCount = 0;
        
        foreach ($selected as $serverData) {
            list($name, $path, $type) = explode('|', $serverData);
            // 检查是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE name = ?");
            $stmt->execute([$name]);
            $exists = $stmt->fetchColumn();
            
            if (!$exists) {
                $stmt = $pdo->prepare("INSERT INTO servers (name, path) VALUES (?, ?)");
                $stmt->execute([$name, $path]);
                $addedCount++;
            }
        }
        
        $message = '已添加 ' . $addedCount . ' 个服务器';
        if ($addedCount < count($selected)) {
            $message .= '（部分服务器已存在，已跳过）';
        }
        unset($_SESSION['detected_instances']);
    } 
    elseif ($_POST['action'] == 'manual') {
        $name = trim($_POST['name'] ?? '');
        $path = trim($_POST['path'] ?? '');
        
        if (empty($name) || empty($path)) {
            $error = '请填写服务器名称和路径';
        } elseif (!is_dir($path)) {
            $error = '服务器目录不存在: ' . $path;
        } else {
            // 检查是否已存在
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE name = ?");
            $stmt->execute([$name]);
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                $error = '服务器名称已存在';
            } else {
                $stmt = $pdo->prepare("INSERT INTO servers (name, path) VALUES (?, ?)");
                $stmt->execute([$name, $path]);
                $message = '服务器已添加';
            }
        }
    }
}

// 获取服务器列表
$servers = $pdo->query("SELECT * FROM servers ORDER BY id DESC")->fetchAll();
$detectedInstances = $_SESSION['detected_instances'] ?? [];

$siteConfig = getSiteConfig();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>服务器管理 - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
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
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
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
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 14px;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s;
        }
        .tab.active {
            color: #667eea;
            border-bottom: 2px solid #667eea;
            margin-bottom: -2px;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .checkbox-group {
            margin: 10px 0;
            padding: 10px;
            background: #f7fafc;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .checkbox-group:hover {
            background: #edf2f7;
        }
        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: normal;
            cursor: pointer;
            margin-bottom: 0;
        }
        .checkbox-group input {
            width: auto;
        }
        .server-info {
            margin-left: 25px;
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        .select-all {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
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
        .instance-count {
            background: #667eea;
            color: white;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 12px;
            margin-left: 10px;
        }
        .server-type {
            display: inline-block;
            padding: 2px 8px;
            background: #e2e8f0;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 8px;
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
    <script>
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
        
        function selectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.server-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }
        
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.server-checkbox');
            const checked = document.querySelectorAll('.server-checkbox:checked');
            const countSpan = document.getElementById('selected-count');
            if (countSpan) {
                countSpan.textContent = checked.length;
            }
        }
    </script>
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
            <a href="servers.php" class="nav-item active">
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
            <h2>服务器管理</h2>
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
        
        <div class="card">
            <div class="tabs">
                <button class="tab active" onclick="showTab('auto-tab')">🤖 自动检测添加</button>
                <button class="tab" onclick="showTab('manual-tab')">✏️ 手动添加</button>
            </div>
            
            <div id="auto-tab" class="tab-content active">
                <form method="POST">
                    <input type="hidden" name="action" value="auto">
                    <div class="form-group">
                        <label>MCSManager Daemon 实例数据目录</label>
                        <input type="text" name="mcsmanager_path" value="/opt/mcsmanager/daemon/data/InstanceConfig" placeholder="/opt/mcsmanager/daemon/data/InstanceConfig">
                        <small style="color: #718096; display: block; margin-top: 5px;">
                            💡 提示：系统将扫描该目录下的所有实例配置文件（*.json），自动读取实例名称和服务端目录（cwd字段）
                        </small>
                        <small style="color: #718096; display: block; margin-top: 5px;">
                            📁 常见路径：/opt/mcsmanager/daemon/data/InstanceConfig
                        </small>
                    </div>
                    <button type="submit" class="btn">🔍 检测实例</button>
                </form>
                
                <?php if (!empty($detectedInstances)): ?>
                <form method="POST" style="margin-top: 30px;">
                    <input type="hidden" name="action" value="add_selected">
                    
                    <div class="select-all">
                        <div class="checkbox-group" style="background: #edf2f7;">
                            <label>
                                <input type="checkbox" onclick="selectAll(this)"> ✅ 全选
                            </label>
                            <span style="margin-left: 15px; font-size: 12px;">已选择 <span id="selected-count">0</span> / <?php echo count($detectedInstances); ?> 个服务器</span>
                        </div>
                    </div>
                    
                    <?php foreach ($detectedInstances as $instance): ?>
                    <div class="checkbox-group">
                        <label>
                            <input type="checkbox" name="selected_servers[]" value="<?php echo htmlspecialchars($instance['name'] . '|' . $instance['path'] . '|' . $instance['type']); ?>" class="server-checkbox" onclick="updateSelectedCount()">
                            <strong><?php echo htmlspecialchars($instance['name']); ?></strong>
                            <span class="server-type"><?php echo htmlspecialchars($instance['type']); ?></span>
                        </label>
                        <div class="server-info">
                            📂 路径: <?php echo htmlspecialchars($instance['path'] ?: '未设置'); ?><br>
                            📄 配置文件: <?php echo htmlspecialchars(basename($instance['file'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <button type="submit" class="btn" style="margin-top: 15px;">➕ 添加选中服务器</button>
                </form>
                <?php endif; ?>
            </div>
            
            <div id="manual-tab" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="action" value="manual">
                    <div class="form-group">
                        <label>服务器名称 *</label>
                        <input type="text" name="name" required placeholder="例如：SERVER1">
                        <small style="color: #718096;">建议使用有意义的名称，便于识别</small>
                    </div>
                    <div class="form-group">
                        <label>服务器文件目录（绝对路径）*</label>
                        <input type="text" name="path" required placeholder="/data/mcserver">
                        <small style="color: #718096;">实例的路径，即服务端文件所在目录</small>
                    </div>
                    <button type="submit" class="btn">➕ 添加服务器</button>
                </form>
            </div>
        </div>
        
        <div class="card">
            <h3>📋 服务器列表</h3>
            <?php if (empty($servers)): ?>
                <div style="text-align: center; padding: 40px; color: #718096;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🖥️</div>
                    <p>暂无服务器，请通过上方方式添加</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>服务器名称</th>
                                <th>路径</th>
                                <th>备份任务</th>
                                <th>备份数量</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($servers as $server): 
                                // 获取该服务器的任务数量
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE server_id = ?");
                                $stmt->execute([$server['id']]);
                                $taskCount = $stmt->fetchColumn();
                                
                                // 获取该服务器的备份数量
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM backups WHERE server_id = ?");
                                $stmt->execute([$server['id']]);
                                $backupCount = $stmt->fetchColumn();
                            ?>
                            <tr>
                                <td><?php echo $server['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($server['name']); ?></strong>
                                    <?php if ($taskCount > 0): ?>
                                        <span class="server-type" style="background: #c6f6d5;">📋 <?php echo $taskCount; ?>个任务</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="font-size: 12px;"><?php echo htmlspecialchars($server['path']); ?></code>
                                    <?php if (!is_dir($server['path'])): ?>
                                        <span style="color: #ef4444; font-size: 11px;">⚠️ 目录不存在</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $taskCount; ?></td>
                                <td><?php echo $backupCount; ?></td>
                                <td class="actions">
                                    <a href="tasks.php?server_id=<?php echo $server['id']; ?>" class="btn btn-sm">📋 任务</a>
                                    <a href="backup_manager.php?server_id=<?php echo $server['id']; ?>" class="btn btn-sm">💾 备份</a>
                                    <a href="?delete=<?php echo $server['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定要删除该服务器吗？相关备份任务也会被删除。')">🗑️ 删除</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
    
    <script>
        // 初始化选中数量
        updateSelectedCount();
    </script>
</body>
</html>