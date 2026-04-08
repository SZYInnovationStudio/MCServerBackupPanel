<?php
// tasks.php - 备份任务管理
require_once 'config.php';
checkLogin();

$pdo = getDB();
$message = '';
$error = '';

// 处理删除任务
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        $message = '备份任务已删除';
    } catch (PDOException $e) {
        $error = '删除失败: ' . $e->getMessage();
    }
}

// 处理立即执行
if (isset($_GET['run']) && is_numeric($_GET['run'])) {
    $result = executeBackup($_GET['run']);
    if ($result['success']) {
        $message = $result['message'];
    } else {
        $error = $result['message'];
    }
}

// 处理启用/禁用任务
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $task_id = (int)$_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE tasks SET enabled = NOT enabled WHERE id = ?");
    $stmt->execute([$task_id]);
    $message = '任务状态已更新';
}

// 处理添加/编辑任务
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $server_id = isset($_POST['server_id']) ? (int)$_POST['server_id'] : 0;
    $source_path = trim($_POST['source_path'] ?? '');
    $backup_name = trim($_POST['backup_name'] ?? '');
    $schedule_time = !empty($_POST['schedule_time']) ? $_POST['schedule_time'] : null;
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    
    if (empty($server_id) || empty($source_path) || empty($backup_name)) {
        $error = '请填写所有必填字段（服务器、源路径、备份文件名）';
    } else {
        try {
            if ($task_id > 0) {
                $stmt = $pdo->prepare("UPDATE tasks SET server_id = ?, source_path = ?, backup_name = ?, schedule_time = ?, enabled = ? WHERE id = ?");
                $stmt->execute([$server_id, $source_path, $backup_name, $schedule_time, $enabled, $task_id]);
                $message = '备份任务已更新';
            } else {
                $stmt = $pdo->prepare("INSERT INTO tasks (server_id, source_path, backup_name, schedule_time, enabled) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$server_id, $source_path, $backup_name, $schedule_time, $enabled]);
                $message = '备份任务已添加';
            }
        } catch (PDOException $e) {
            $error = '保存失败: ' . $e->getMessage();
        }
    }
}

// 获取编辑的任务
$editTask = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editTask = $stmt->fetch(PDO::FETCH_ASSOC);
}

$servers = $pdo->query("SELECT id, name, path FROM servers ORDER BY name")->fetchAll();
$tasks = $pdo->query("SELECT t.*, s.name as server_name, s.path as server_path FROM tasks t JOIN servers s ON t.server_id = s.id ORDER BY t.id DESC")->fetchAll();

$siteConfig = getSiteConfig();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>备份任务 - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
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
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input {
            width: auto;
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
            display: inline-block;
        }
        .badge-enabled {
            background: #c6f6d5;
            color: #22543d;
        }
        .badge-disabled {
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
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .info-text {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        .server-path {
            font-family: monospace;
            font-size: 12px;
            background: #f7fafc;
            padding: 8px;
            border-radius: 6px;
            margin-top: 5px;
        }
        .example-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .row {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script>
        function updateServerPath() {
            var serverSelect = document.getElementById('server_id');
            var selectedOption = serverSelect.options[serverSelect.selectedIndex];
            var serverPath = selectedOption.getAttribute('data-path');
            if (serverPath) {
                document.getElementById('server_path_display').innerHTML = '<strong>服务器目录：</strong><code>' + serverPath + '</code>';
                document.getElementById('source_path_hint').innerHTML = '示例：<code>world</code> 或 <code>/world</code> 或 <code>plugins</code>';
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
            <a href="servers.php" class="nav-item">
                <span class="icon">🖥️</span>
                <span>服务器管理</span>
            </a>
            <a href="tasks.php" class="nav-item active">
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
            <h2>备份任务管理</h2>
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
            <h3><?php echo $editTask ? '✏️ 编辑备份任务' : '➕ 添加备份任务'; ?></h3>
            <form method="POST">
                <?php if ($editTask): ?>
                    <input type="hidden" name="task_id" value="<?php echo $editTask['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>选择服务器 *</label>
                    <select name="server_id" id="server_id" required onchange="updateServerPath()">
                        <option value="">请选择服务器</option>
                        <?php foreach ($servers as $server): ?>
                            <option value="<?php echo $server['id']; ?>" data-path="<?php echo htmlspecialchars($server['path']); ?>" <?php echo ($editTask && $editTask['server_id'] == $server['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($server['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="server_path_display" class="server-path">
                        <?php if ($editTask): ?>
                            <?php 
                            $selectedServer = null;
                            foreach ($servers as $s) {
                                if ($s['id'] == $editTask['server_id']) {
                                    $selectedServer = $s;
                                    break;
                                }
                            }
                            if ($selectedServer): ?>
                                <strong>服务器目录：</strong><code><?php echo htmlspecialchars($selectedServer['path']); ?></code>
                            <?php endif; ?>
                        <?php else: ?>
                            <small style="color: #718096;">请先选择服务器</small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>备份源文件夹 *</label>
                    <input type="text" name="source_path" value="<?php echo $editTask ? htmlspecialchars($editTask['source_path']) : ''; ?>" required placeholder="world">
                    <div class="info-text" id="source_path_hint">
                        💡 支持相对路径（相对于服务器目录）或绝对路径<br>
                        示例：<code>world</code>（备份 world 文件夹）或 <code>plugins</code>（备份 plugins 文件夹）
                    </div>
                </div>
                
                <div class="form-group">
                    <label>备份文件名 *</label>
                    <input type="text" name="backup_name" value="<?php echo $editTask ? htmlspecialchars($editTask['backup_name']) : '{server}_{date}.zip'; ?>" required placeholder="{server}_{date}.zip">
                    <div class="info-text">📝 可用变量：<code>{date}</code> 日期时间，<code>{server}</code> 服务器名</div>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label>定时执行时间（可选）</label>
                        <input type="time" name="schedule_time" value="<?php echo $editTask && $editTask['schedule_time'] ? substr($editTask['schedule_time'], 0, 5) : ''; ?>">
                        <div class="info-text">⏰ 留空表示仅手动执行</div>
                    </div>
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="enabled" id="enabled" <?php echo (!$editTask || $editTask['enabled']) ? 'checked' : ''; ?>>
                            <label for="enabled" style="margin-bottom: 0;">启用此任务</label>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn"><?php echo $editTask ? '更新任务' : '添加任务'; ?></button>
                <?php if ($editTask): ?>
                    <a href="tasks.php" class="btn btn-secondary" style="margin-left: 10px;">取消编辑</a>
                <?php endif; ?>
            </form>
            
            <div class="example-box">
                <strong>💡 使用说明：</strong><br>
                • <strong>备份源文件夹</strong>：输入要备份的文件夹名称，如 <code>world</code>、<code>plugins</code>、<code>logs</code><br>
                • 系统会自动在服务器目录下查找该文件夹并打包<br>
                • <strong>备份文件保存位置</strong>：自动保存在网站目录的 <code>/backups/服务器名/</code> 下<br>
                • <strong>公开下载</strong>：在备份管理页面可将备份设为公开，玩家即可下载
            </div>
        </div>
        
        <div class="card">
            <h3>📋 备份任务列表</h3>
            <?php if (empty($tasks)): ?>
                <div style="text-align: center; padding: 40px; color: #718096;">
                    <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
                    <p>暂无备份任务，请添加</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>服务器</th>
                                <th>源路径</th>
                                <th>备份文件名</th>
                                <th>定时</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td><?php echo $task['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($task['server_name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($task['source_path']); ?></code></td>
                                <td><small><?php echo htmlspecialchars($task['backup_name']); ?></small></td>
                                <td><?php echo $task['schedule_time'] ? substr($task['schedule_time'], 0, 5) : '手动'; ?></td>
                                <td>
                                    <span class="badge <?php echo $task['enabled'] ? 'badge-enabled' : 'badge-disabled'; ?>">
                                        <?php echo $task['enabled'] ? '启用' : '禁用'; ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="?run=<?php echo $task['id']; ?>" class="btn btn-success btn-sm" onclick="return confirm('确定立即执行此备份任务吗？')">▶ 执行</a>
                                    <a href="?toggle=<?php echo $task['id']; ?>" class="btn btn-secondary btn-sm"><?php echo $task['enabled'] ? '禁用' : '启用'; ?></a>
                                    <a href="?edit=<?php echo $task['id']; ?>" class="btn btn-sm">✏️ 编辑</a>
                                    <a href="?delete=<?php echo $task['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定删除此备份任务吗？')">🗑️ 删除</a>
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
</body>
</html>