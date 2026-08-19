<?php
/**
 * MCServerBackupPanel — Backup Task Management
 *
 * Manage backup tasks per server: create, edit, delete, run immediately.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/admin_layout.php';

$db = getDB();
$csrfToken = generateCSRF();

// Get all servers for dropdown
$allServers = $db->query("SELECT id, name, directory FROM servers ORDER BY name ASC")->fetchAll();
$selectedServerId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (isset($_POST['filter_server_id']) ? (int)$_POST['filter_server_id'] : ($allServers[0]['id'] ?? 0));

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    error_reporting(0);
    ini_set('display_errors', 0);

    $response = ['success' => false, 'message' => '未知错误'];

    try {
        if (!verifyCSRF()) {
            $response = ['success' => false, 'message' => 'CSRF验证失败'];
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'add') {
                $serverId         = (int)($_POST['server_id'] ?? 0);
                $backupFolder     = trim($_POST['backup_folder'] ?? '');
                $backupTime       = trim($_POST['backup_time'] ?? '');
                $backupDest       = trim($_POST['backup_destination'] ?? '');
                $backupFilename   = trim($_POST['backup_filename'] ?? '');
                $backupItems      = trim($_POST['backup_items'] ?? '');
                $autoDelete       = (int)($_POST['auto_delete'] ?? 0);
                $encrypted        = (int)($_POST['encrypted'] ?? 0);
                $encryptPassword  = trim($_POST['encrypt_password'] ?? '');
                $pathType         = ($_POST['backup_path_type'] ?? 'relative') === 'absolute' ? 'absolute' : 'relative';
                $defaultPublic    = (int)($_POST['default_public'] ?? 0);

                if ($serverId <= 0 || $backupFolder === '' || $backupTime === '' || $backupDest === '' || $backupFilename === '') {
                    $response = ['success' => false, 'message' => '所有字段均为必填项'];
                } elseif (!preg_match('/^\d{2}:\d{2}$/', $backupTime) || DateTime::createFromFormat('H:i', $backupTime) === false) {
                    $response = ['success' => false, 'message' => '备份时间格式无效'];
                } elseif ($encrypted && $encryptPassword === '') {
                    $response = ['success' => false, 'message' => '启用下载密码保护时必须填写密码'];
                } elseif ($encrypted && mb_strlen($encryptPassword) < 6) {
                    $response = ['success' => false, 'message' => '下载密码长度至少 6 位'];
                } else {
                    $encPass = ($encrypted && $encryptPassword !== '') ? encryptValue($encryptPassword) : null;
                    $stmt = $db->prepare("INSERT INTO backup_tasks (server_id, backup_folder, backup_time, backup_destination, backup_path_type, backup_filename, backup_items, auto_delete, encrypted, encrypt_password, default_public, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$serverId, $backupFolder, $backupTime, $backupDest, $pathType, $backupFilename, $backupItems ?: null, $autoDelete, $encrypted, $encPass, $defaultPublic]);
                    $response = ['success' => true];
                }
            }

            elseif ($action === 'edit') {
                $id               = (int)($_POST['id'] ?? 0);
                $backupFolder     = trim($_POST['backup_folder'] ?? '');
                $backupTime       = trim($_POST['backup_time'] ?? '');
                $backupDest       = trim($_POST['backup_destination'] ?? '');
                $backupFilename   = trim($_POST['backup_filename'] ?? '');
                $backupItems      = trim($_POST['backup_items'] ?? '');
                $autoDelete       = (int)($_POST['auto_delete'] ?? 0);
                $encrypted        = (int)($_POST['encrypted'] ?? 0);
                $encryptPassword  = trim($_POST['encrypt_password'] ?? '');
                $pathType         = ($_POST['backup_path_type'] ?? 'relative') === 'absolute' ? 'absolute' : 'relative';
                $defaultPublic    = (int)($_POST['default_public'] ?? 0);

                if ($id <= 0 || $backupFolder === '' || $backupTime === '' || $backupDest === '' || $backupFilename === '') {
                    $response = ['success' => false, 'message' => '所有字段均为必填项'];
                } elseif (!preg_match('/^\d{2}:\d{2}$/', $backupTime) || DateTime::createFromFormat('H:i', $backupTime) === false) {
                    $response = ['success' => false, 'message' => '备份时间格式无效'];
                } elseif ($encrypted && $encryptPassword !== '' && mb_strlen($encryptPassword) < 6) {
                    $response = ['success' => false, 'message' => '下载密码长度至少 6 位'];
                } else {
                    $encPass = ($encrypted && $encryptPassword !== '') ? encryptValue($encryptPassword) : null;
                    $hasError = false;
                    if ($encrypted && $encryptPassword === '') {
                        // 编辑时留空密码：复用原密码，若原任务无密码则报错
                        $existing = $db->prepare("SELECT encrypted, encrypt_password FROM backup_tasks WHERE id = ?");
                        $existing->execute([$id]);
                        $row = $existing->fetch();
                        if ($row && $row['encrypted'] && !empty($row['encrypt_password'])) {
                            $encPass = $row['encrypt_password'];
                        } else {
                            $response = ['success' => false, 'message' => '启用下载密码保护时必须填写密码'];
                            $hasError = true;
                        }
                    }

                    if (!$hasError) {
                        $stmt = $db->prepare("UPDATE backup_tasks SET backup_folder = ?, backup_time = ?, backup_destination = ?, backup_path_type = ?, backup_filename = ?, backup_items = ?, auto_delete = ?, encrypted = ?, encrypt_password = ?, default_public = ? WHERE id = ?");
                        $stmt->execute([$backupFolder, $backupTime, $backupDest, $pathType, $backupFilename, $backupItems ?: null, $autoDelete, $encrypted, $encPass, $defaultPublic, $id]);
                        $response = ['success' => true];
                    }
                }
            }

            elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $response = ['success' => false, 'message' => '无效任务ID'];
                } else {
                    $db->prepare("DELETE FROM backup_tasks WHERE id = ?")->execute([$id]);
                    $response = ['success' => true];
                }
            }

            elseif ($action === 'run') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $response = ['success' => false, 'message' => '无效任务ID'];
                } else {
                    set_time_limit(0);
                    @ini_set('memory_limit', '-1');

                    $task = $db->prepare("SELECT t.*, s.name AS server_name, s.directory AS server_dir FROM backup_tasks t JOIN servers s ON t.server_id = s.id WHERE t.id = ?");
                    $task->execute([$id]);
                    $task = $task->fetch();

                    if (!$task) {
                        $response = ['success' => false, 'message' => '任务不存在'];
                    } else {
                        $selectedItems = !empty($task['backup_items']) ? json_decode($task['backup_items'], true) ?: [] : [];
                        $sourceDir = $task['backup_folder'];
                        $isAbsolute = null;
                        if (($task['backup_path_type'] ?? '') === 'absolute') {
                            $isAbsolute = true;
                        }
                        $destDir = normalizeBackupPath($task['backup_destination'], $isAbsolute);
                        $filename = $task['backup_filename'];
                        $vars = [
                            '{server_name}' => $task['server_name'],
                            '{server}'      => $task['server_name'],
                            '{date}'        => date('Y-m-d'),
                            '{time}'        => date('H-i-s'),
                            '{datetime}'    => date('Y-m-d_H-i-s'),
                        ];
                        $filename = str_replace(array_keys($vars), array_values($vars), $filename);
                        $filename = safeFilename($filename);
                        if (!preg_match('/\.zip$/i', $filename)) $filename .= '.zip';
                        $filePath = preg_replace('#/+#', '/', rtrim($destDir, '/') . '/' . $filename);
                        $filePath = uniqueBackupPath($filePath);
                        $filename = basename($filePath);

                        if (!ensureDir($destDir)) {
                            $response = ['success' => false, 'message' => '无法创建备份目标目录：' . $destDir];
                        } else {
                            $encPassword = !empty($task['encrypted']) ? ($task['encrypt_password'] ?? null) : null;

                            // Insert job record
                            $jobStmt = $db->prepare("INSERT INTO backup_jobs (server_id, task_id, filename, status, message) VALUES (?, ?, ?, 'running', '开始备份...')");
                            $jobStmt->execute([(int)$task['server_id'], $id, $filename]);
                            $jobId = (int)$db->lastInsertId();

                            // Respond immediately so browser doesn't time out during long backup
                            sendResponseAndContinue([
                                'success' => true,
                                'job_id'  => $jobId,
                                'message' => '备份已开始（任务 #' . $jobId . '）'
                            ]);

                            // ── Create ZIP ──
                            logJob($db, $jobId, 'info', "Source: {$sourceDir}");
                            logJob($db, $jobId, 'info', "Dest:   {$filePath}");
                            logJob($db, $jobId, 'info', 'Phase 1: Creating ZIP archive');
                            [$zipOk, $zipResult] = createBackupZip($sourceDir, $filePath, $selectedItems, $db, $jobId);

                            if (!$zipOk) {
                                if (!@unlink($filePath)) {
                                    logJob($db, $jobId, 'error', 'Failed to remove incomplete backup file: ' . $filePath);
                                }
                                logJob($db, $jobId, 'error', 'Backup failed: ' . $zipResult);
                                setJobStatus($db, $jobId, 'failed', $zipResult);
                            } else {
                                clearstatcache();
                                $fileSize = 0; $exists = false;
                                for ($r = 0; $r < 5; $r++) {
                                    if (is_file($filePath)) { $sz = @filesize($filePath); if ($sz !== false && $sz > 0) { $fileSize = $sz; $exists = true; break; } }
                                    if ($r < 4) { usleep(100000); clearstatcache(); }
                                }
                                if (!$exists) {
                                    @unlink($filePath);
                                    logJob($db, $jobId, 'error', 'ZIP file is 0 bytes');
                                    setJobStatus($db, $jobId, 'failed', 'ZIP文件写入失败（0字节）');
                                } else {
                                    $dlPassword = null;
                                    if (!empty($task['encrypted']) && !empty($task['encrypt_password'])) {
                                        $encPassword = decryptValue($task['encrypt_password']);
                                        $dlPassword = password_hash($encPassword, PASSWORD_BCRYPT);
                                    }
                                    $isPublic = (int)($task['default_public'] ?? 0);
                                    $stmt = $db->prepare("INSERT INTO backup_records (server_id, task_id, filename, file_size, file_path, is_public, download_password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                                    $stmt->execute([(int)$task['server_id'], $id, $filename, $fileSize, $filePath, $isPublic, $dlPassword]);
                                    $recordId = (int)$db->lastInsertId();
                                    logJob($db, $jobId, 'info', 'Backup done: ' . formatSize($fileSize) . ' (record ' . $recordId . ')');
                                    setJobStatus($db, $jobId, 'success', '备份完成: ' . formatSize($fileSize));

                                    // Auto-delete old backups (keep only the latest)
                                    if (!empty($task['auto_delete'])) {
                                        try {
                                            $oldRecords = $db->prepare("SELECT id, file_path FROM backup_records WHERE task_id = ? AND id != ? ORDER BY created_at DESC");
                                            $oldRecords->execute([$id, $recordId]);
                                            foreach ($oldRecords->fetchAll() as $old) {
                                                if (!@unlink($old['file_path'])) {
                                                    logJob($db, $jobId, 'error', 'Failed to delete old backup file: ' . $old['file_path']);
                                                }
                                                $db->prepare("DELETE FROM backup_records WHERE id = ?")->execute([$old['id']]);
                                            }
                                            logJob($db, $jobId, 'info', 'Auto-deleted old backups');
                                        } catch (Exception $ex) {
                                            logJob($db, $jobId, 'error', 'Auto-delete error: ' . $ex->getMessage());
                                        }
                                    }
                                }
                            }
                            // Done — stop here
                            exit;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => '系统错误：' . $e->getMessage()];
    }

    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Execute a backup for the given task.
 *
 * @param array $task Task row with joined server data
 * @param PDO   $db   Database connection
 * @return array Result with success/error and message
 */
function executeBackup(array $task, PDO $db): array
{
    $selectedItems = [];
    if (!empty($task['backup_items'])) {
        $selectedItems = json_decode($task['backup_items'], true) ?: [];
    }

    $sourceDir = $task['backup_folder'];
    $isAbsolute = null;
    if (($task['backup_path_type'] ?? '') === 'absolute') {
        $isAbsolute = true;
    }
    $destDir   = normalizeBackupPath($task['backup_destination'], $isAbsolute);

    if (!is_dir($sourceDir)) {
        return ['success' => false, 'message' => '备份源目录不存在：' . $sourceDir];
    }

    // Ensure destination exists
    if (!ensureDir($destDir)) {
        return ['success' => false, 'message' => '无法创建备份目标目录：' . $destDir];
    }

    // Generate filename from template (supports variables)
    $filename = $task['backup_filename'];
    $vars = [
        '{server_name}' => $task['server_name'],
        '{server}'      => $task['server_name'], // backward compatibility
        '{date}'        => date('Y-m-d'),
        '{time}'        => date('H-i-s'),
        '{datetime}'    => date('Y-m-d_H-i-s'),
    ];
    $filename = str_replace(array_keys($vars), array_values($vars), $filename);
    $filename = safeFilename($filename);
    if (!preg_match('/\.zip$/i', $filename)) {
        $filename .= '.zip';
    }

    $filePath = preg_replace('#/+#', '/', rtrim($destDir, '/') . '/' . $filename);
    $filePath = uniqueBackupPath($filePath);
    $filename = basename($filePath);

    [$zipOk, $zipResult] = createBackupZip($sourceDir, $filePath, $selectedItems);

    if (!$zipOk) {
        @unlink($filePath);
        return ['success' => false, 'message' => '备份出错：' . $zipResult];
    }

    // 验证文件
    clearstatcache();
    $fileSize = 0;
    $exists = false;
    for ($r = 0; $r < 5; $r++) {
        if (is_file($filePath)) {
            $sz = @filesize($filePath);
            if ($sz !== false && $sz > 0) { $fileSize = $sz; $exists = true; break; }
        }
        if ($r < 4) { usleep(100000); clearstatcache(); }
    }
    if (!$exists) {
        @unlink($filePath);
        return ['success' => false, 'message' => 'ZIP文件写入失败（0字节）。'];
    }

        // Insert record
        $dlPassword = null;
        if (!empty($task['encrypted']) && !empty($task['encrypt_password'])) {
            $encPassword = decryptValue($task['encrypt_password']);
            $dlPassword = password_hash($encPassword, PASSWORD_BCRYPT);
        }

        $isPublic = (int)($task['default_public'] ?? 0);
        $stmt = $db->prepare("INSERT INTO backup_records (server_id, task_id, filename, file_size, file_path, is_public, download_password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$task['server_id'], $task['id'], $filename, (int)$fileSize, $filePath, $isPublic, $dlPassword]);

        // Auto-delete old backups: keep only the latest
        if (!empty($task['auto_delete'])) {
            $stmt = $db->prepare(
                "SELECT id, file_path FROM backup_records WHERE task_id = ? AND id != ? ORDER BY created_at DESC"
            );
            $stmt->execute([$task['id'], (int)$db->lastInsertId()]);
            $oldRecords = $stmt->fetchAll();
            foreach ($oldRecords as $old) {
                @unlink($old['file_path']);
                $db->prepare("DELETE FROM backup_records WHERE id = ?")->execute([$old['id']]);
            }
        }

        return [
            'success' => true,
            'message' => '备份完成：' . $filename . ' (' . formatSize($fileSize) . ')',
            'filename' => $filename,
            'size' => formatSize($fileSize)
        ];
}

// Fetch tasks for selected server
$tasks = [];
if ($selectedServerId > 0) {
    $stmt = $db->prepare(
        "SELECT t.*, s.name AS server_name, s.directory AS server_dir
         FROM backup_tasks t
         JOIN servers s ON t.server_id = s.id
         WHERE t.server_id = ?
         ORDER BY t.created_at DESC"
    );
    $stmt->execute([$selectedServerId]);
    $tasks = $stmt->fetchAll();
}

adminHeader('备份任务', 'tasks');
?>

<div class="page-content">
    <div class="page-header">
        <h2>备份任务管理</h2>
        <button class="btn btn-primary" onclick="openAddModal()" <?php echo $selectedServerId ? '' : 'disabled'; ?>>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            添加任务
        </button>
    </div>

    <!-- Server Selector -->
    <div class="card mb-24">
        <div class="flex items-center gap-16">
            <label class="form-label mb-0" for="filter-server" style="min-width:80px;">选择服务器：</label>
            <select id="filter-server" class="form-select" style="max-width:320px;" onchange="filterServer(this.value)">
                <option value="0">-- 请选择服务器 --</option>
                <?php foreach ($allServers as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php echo $selectedServerId === (int)$s['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($selectedServerId <= 0): ?>
    <div class="card">
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            <h3>请先选择服务器</h3>
            <p>在上方下拉菜单中选择一台服务器以查看和管理其备份任务。</p>
        </div>
    </div>
    <?php elseif (empty($tasks)): ?>
    <div class="card">
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            <h3>该服务器暂无备份任务</h3>
            <p>为服务器创建定时备份任务，确保数据安全。</p>
            <button class="btn btn-primary" onclick="openAddModal()">创建首个备份任务</button>
        </div>
    </div>
    <?php else: ?>
    <div class="card" style="padding:0;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>备份目录</th>
                        <th>执行时间</th>
                        <th>存放位置</th>
                        <th>文件名模板</th>
                        <th>备份内容</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td data-label="备份目录"><span class="text-mono" style="font-size:13px;"><?php echo htmlspecialchars($task['backup_folder']); ?></span></td>
                        <td data-label="执行时间"><span class="badge badge-blue">每天 <?php echo htmlspecialchars($task['backup_time']); ?></span></td>
                        <td data-label="存放位置"><span class="text-mono" style="font-size:12px;"><?php echo htmlspecialchars($task['backup_destination']); ?></span></td>
                        <td data-label="文件名模板"><?php echo htmlspecialchars($task['backup_filename']); ?></td>
                        <td data-label="备份内容" style="font-size:13px;"><?php
                            $itemsSummary = '全部文件';
                            if (!empty($task['backup_items'])) {
                                $items = json_decode($task['backup_items'], true);
                                if ($items && count($items) > 0) {
                                    $itemsSummary = count($items) . ' 个项目';
                                }
                            }
                            echo htmlspecialchars($itemsSummary);
                        ?></td>
                        <td data-label="状态" style="font-size:13px;">
                            <?php if (!empty($task['auto_delete'])): ?>
                                <span class="badge badge-green">自动清理</span>
                            <?php endif; ?>
                            <?php if (!empty($task['encrypted'])): ?>
                                <span class="badge badge-blue">需密码</span>
                            <?php endif; ?>
                            <?php if (empty($task['auto_delete']) && empty($task['encrypted'])): ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="操作" class="td-actions">
                            <button class="btn btn-secondary btn-sm" onclick="openEditModal(<?php $taskSafe = $task; unset($taskSafe['encrypt_password']); echo htmlspecialchars(json_encode($taskSafe)); ?>)">编辑</button>
                            <button class="btn btn-ghost btn-sm" onclick="runTask(<?php echo $task['id']; ?>)" id="run-btn-<?php echo $task['id']; ?>">立即执行</button>
                            <button class="btn btn-danger btn-sm" onclick="confirmDeleteTask(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars(addslashes($task['backup_folder'])); ?>')">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Task Modal -->
<div class="modal-overlay hidden" id="task-modal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="task-modal-title">添加备份任务</h2>
            <button class="btn btn-ghost btn-icon" onclick="closeModal('task-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="task-form" onsubmit="return submitTask(event)">
                <input type="hidden" name="action" id="task-form-action" value="add">
                <input type="hidden" name="id" id="task-form-id" value="0">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="server_id" id="task-server-id" value="<?php echo $selectedServerId; ?>">

                <?php
                // Get selected server info for defaults
                $selServer = null;
                foreach ($allServers as $s) {
                    if ((int)$s['id'] === $selectedServerId) { $selServer = $s; break; }
                }
                $defaultFolder = $selServer['directory'] ?? '';
                $defaultDest = ($selServer['name'] ?? 'server') . '/';
                $defaultFilename = ($selServer['name'] ?? 'server') . '_{date}_{time}.zip';
                ?>

                <div class="form-group">
                    <label class="form-label" for="task-folder">备份文件夹</label>
                    <input type="text" id="task-folder" name="backup_folder" class="form-input"
                           value="<?php echo htmlspecialchars($defaultFolder); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="task-time">备份时间</label>
                    <input type="time" id="task-time" name="backup_time" class="form-input"
                           value="03:00" required>
                    <div class="form-hint">每天此时自动执行备份任务。</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="task-dest">备份存放地址</label>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <select id="task-path-type" name="backup_path_type" class="form-select" style="width:auto;min-width:100px;" onchange="onTaskPathTypeChange()">
                            <option value="relative">相对路径</option>
                            <option value="absolute">绝对路径</option>
                        </select>
                    </div>
                    <input type="text" id="task-dest" name="backup_destination" class="form-input"
                           value="<?php echo htmlspecialchars($defaultDest); ?>" required>
                    <div class="form-hint" id="task-dest-hint">相对路径 — 存于网站 <code>backups/</code> 目录下（如 <code>SZYDMC-JAVA/</code>）。</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="task-filename">备份文件名模板</label>
                    <input type="text" id="task-filename" name="backup_filename" class="form-input"
                           value="<?php echo htmlspecialchars($defaultFilename); ?>" required>
                    <div class="form-hint">支持变量：{date} 日期, {time} 时间, {server} 服务器名。</div>
                </div>

                <div class="form-group">
                    <label class="form-label">选择备份内容 <span style="font-weight:400;color:var(--text-muted);">(留空则备份全部)</span></label>
                    <div id="task-file-browser">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="taskBrowserLoad('')">根目录</button>
                            <span id="task-breadcrumb" style="font-size:13px;color:var(--text-secondary);"></span>
                        </div>
                        <div id="task-file-list" style="max-height:200px;overflow-y:auto;border:1px solid var(--border-color);border-radius:var(--radius-input);padding:8px;"></div>
                        <div style="margin-top:4px;">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="taskToggleAll()">全选 / 取消全选</button>
                        </div>
                    </div>
                    <input type="hidden" name="backup_items" id="task-backup-items" value="">
                </div>

                <div class="form-group">
                    <label class="form-checkbox-label" style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="task-auto-delete" name="auto_delete" value="1"
                               style="width:18px;height:18px;cursor:pointer;">
                        <div>
                            <span style="font-size:14px;font-weight:500;">自动删除旧备份</span>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">任务执行时自动删除之前的备份，公开页始终只显示最新备份</div>
                        </div>
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label" for="task-default-public">新备份默认可见性</label>
                    <select id="task-default-public" name="default_public" class="form-select" style="width:auto;min-width:220px;">
                        <option value="0">私密（仅管理员可见）</option>
                        <option value="1">公开（公开下载页可见）</option>
                    </select>
                    <div class="form-hint">该任务每次生成的备份将默认采用此可见性，可在备份记录中单独修改。</div>
                </div>

                <div class="form-group" style="border:1px solid var(--border-color);border-radius:var(--radius-card);padding:16px;">
                    <label class="form-checkbox-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="task-encrypted" name="encrypted" value="1"
                               style="width:18px;height:18px;margin-top:2px;cursor:pointer;flex-shrink:0;">
                        <div>
                            <span style="font-size:14px;font-weight:500;">设置下载密码</span>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">该任务所有备份自动设置下载密码，用户在公开页下载时需输入密码</div>
                        </div>
                    </label>
                    <div id="task-encrypt-password-group" style="display:none;margin-top:12px;">
                        <label class="form-label" for="task-encrypt-password">下载密码</label>
                        <input type="text" id="task-encrypt-password" name="encrypt_password" class="form-input"
                               placeholder="请输入下载密码" autocomplete="off">
                        <div class="form-hint">设置后将被安全存储，后续备份自动沿用。编辑时留空则保持原有密码不变。</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full" id="btn-task-submit">创建任务</button>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay hidden" id="delete-task-modal">
    <div class="modal">
        <div class="modal-body">
            <div class="confirm-content">
                <div class="confirm-icon danger">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h3>确认删除任务</h3>
                <p id="delete-task-msg">确定要删除此备份任务吗？此操作不可撤销。</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('delete-task-modal')">取消</button>
            <button class="btn btn-danger" id="btn-confirm-delete-task">确认删除</button>
        </div>
    </div>
</div>

<input type="hidden" id="global-csrf" value="<?php echo $csrfToken; ?>">

<script>
var isTaskEditMode = false;

function filterServer(id) {
    window.location.href = 'tasks.php?server_id=' + id;
}

function openAddModal() {
    isTaskEditMode = false;
    document.getElementById('task-modal-title').textContent = '添加备份任务';
    document.getElementById('task-form-action').value = 'add';
    document.getElementById('task-form-id').value = '0';
    document.getElementById('task-folder').value = '<?php echo htmlspecialchars($defaultFolder); ?>';
    document.getElementById('task-time').value = '03:00';
    document.getElementById('task-path-type').value = 'relative';
    document.getElementById('task-dest').value = '<?php echo htmlspecialchars($defaultDest); ?>';
    document.getElementById('task-dest-hint').innerHTML = '相对路径 — 存于网站 <code>backups/</code> 目录下（如 <code>SZYDMC-JAVA/</code>）。';
    document.getElementById('task-filename').value = '<?php echo htmlspecialchars($defaultFilename); ?>';
    document.getElementById('task-backup-items').value = '';
    document.getElementById('task-auto-delete').checked = false;
    document.getElementById('task-default-public').value = '0';
    document.getElementById('task-encrypted').checked = false;
    document.getElementById('task-encrypt-password').value = '';
    document.getElementById('task-encrypt-password-group').style.display = 'none';
    document.getElementById('btn-task-submit').textContent = '创建任务';
    document.getElementById('task-modal').classList.remove('hidden');
    initTaskBrowser(<?php echo $selectedServerId; ?>, '');
}

function openEditModal(task) {
    isTaskEditMode = true;
    document.getElementById('task-modal-title').textContent = '编辑备份任务';
    document.getElementById('task-form-action').value = 'edit';
    document.getElementById('task-form-id').value = task.id;
    document.getElementById('task-folder').value = task.backup_folder;
    document.getElementById('task-time').value = task.backup_time;
    var pathType = (task.backup_path_type === 'absolute') ? 'absolute' : 'relative';
    document.getElementById('task-path-type').value = pathType;
    document.getElementById('task-dest').value = task.backup_destination;
    onTaskPathTypeChange();
    document.getElementById('task-filename').value = task.backup_filename;
    document.getElementById('task-backup-items').value = task.backup_items || '';
    document.getElementById('task-auto-delete').checked = (task.auto_delete == 1);
    document.getElementById('task-default-public').value = (task.default_public == 0) ? '0' : '1';
    document.getElementById('task-encrypted').checked = (task.encrypted == 1);
    var encPwdGroup = document.getElementById('task-encrypt-password-group');
    var encPwdInput = document.getElementById('task-encrypt-password');
    if (task.encrypted == 1) {
        encPwdGroup.style.display = 'block';
        encPwdInput.placeholder = '留空则保持原有密码不变';
        encPwdInput.value = '';
    } else {
        encPwdGroup.style.display = 'none';
        encPwdInput.placeholder = '请输入下载密码';
        encPwdInput.value = '';
    }
    document.getElementById('btn-task-submit').textContent = '保存修改';
    document.getElementById('task-modal').classList.remove('hidden');
    initTaskBrowser(<?php echo $selectedServerId; ?>, task.backup_items || '');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function submitTask(e) {
    e.preventDefault();
    var btn = document.getElementById('btn-task-submit');
    var formData = new FormData(document.getElementById('task-form'));
    btn.disabled = true;
    btn.classList.add('btn-loading');

    fetch('tasks.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.classList.remove('btn-loading');
        if (res.success) {
            showToast('success', isTaskEditMode ? '修改成功' : '创建成功', isTaskEditMode ? '任务已更新。' : '备份任务已创建。');
            setTimeout(function() { location.reload(); }, 500);
        } else {
            showToast('error', '操作失败', res.message);
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.classList.remove('btn-loading');
        showToast('error', '网络错误', '请稍后重试。');
    });
    return false;
}

function runTask(id) {
    var btn = document.getElementById('run-btn-' + id);
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> 执行中';

    fetch('tasks.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=run&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.textContent = '立即执行';
        if (res.success) {
            showToast('success', '备份完成', res.message);
        } else {
            showToast('error', '备份失败', res.message);
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = '立即执行';
        showToast('error', '错误', '请求失败，请重试。');
    });
}

function confirmDeleteTask(id, folder) {
    document.getElementById('delete-task-msg').textContent = '确定要删除此备份任务吗？（备份目录：' + folder + '）此操作不可撤销。';
    document.getElementById('btn-confirm-delete-task').onclick = function() {
        deleteTask(id);
    };
    document.getElementById('delete-task-modal').classList.remove('hidden');
}

function deleteTask(id) {
    var btn = document.getElementById('btn-confirm-delete-task');
    btn.disabled = true;
    btn.classList.add('btn-loading');
    fetch('tasks.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('success', '已删除', '备份任务已删除。');
            closeModal('delete-task-modal');
            setTimeout(function() { location.reload(); }, 500);
        } else {
            showToast('error', '删除失败', res.message);
            btn.disabled = false;
            btn.classList.remove('btn-loading');
        }
    });
}

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (!e.target.closest('.modal')) overlay.classList.add('hidden');
    });
});

// Toggle encryption password field
document.getElementById('task-encrypted').addEventListener('change', function() {
    var group = document.getElementById('task-encrypt-password-group');
    group.style.display = this.checked ? 'block' : 'none';
    if (!this.checked) {
        document.getElementById('task-encrypt-password').value = '';
    }
});

var taskCurrentPath = '';
var taskSelectedItems = {};

function initTaskBrowser(serverId, savedItemsJson) {
    taskCurrentPath = '';
    taskSelectedItems = {};
    if (savedItemsJson) {
        try {
            var items = JSON.parse(savedItemsJson);
            for (var i = 0; i < items.length; i++) {
                taskSelectedItems[items[i]] = true;
            }
        } catch(e) {}
    }
    taskBrowserLoad('');
}

function taskBrowserLoad(path) {
    var serverId = document.getElementById('task-server-id').value;
    if (!serverId) return;

    var list = document.getElementById('task-file-list');
    list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);"><span class="spinner"></span> 加载中...</div>';

    var formData = 'action=browse&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) +
        '&server_id=' + serverId + '&path=' + encodeURIComponent(path);

    fetch('servers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--danger);">' + data.message + '</div>';
            return;
        }
        taskCurrentPath = path;
        updateTaskBreadcrumb(path);
        renderTaskFileList(data.items, path);
        updateTaskHiddenField();
    })
    .catch(function() {
        list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--danger);">加载失败</div>';
    });
}

function updateTaskBreadcrumb(path) {
    var bc = document.getElementById('task-breadcrumb');
    if (!path) { bc.textContent = ''; return; }
    var parts = path.split('/');
    var html = '';
    for (var i = 0; i < parts.length; i++) {
        if (i > 0) html += ' / ';
        var partialPath = parts.slice(0, i + 1).join('/');
        html += '<a href="javascript:void(0)" onclick="taskBrowserLoad(\'' + escapeHtml(partialPath) + '\')" style="color:var(--accent);font-size:13px;">' + escapeHtml(parts[i]) + '</a>';
    }
    bc.innerHTML = html;
}

function renderTaskFileList(items, currentPath) {
    var list = document.getElementById('task-file-list');
    if (items.length === 0) {
        list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);">此目录为空</div>';
        return;
    }
    var html = '';
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var fullPath = currentPath ? currentPath + '/' + item.name : item.name;
        var checked = taskSelectedItems[fullPath] ? ' checked' : '';
        var icon = item.is_dir ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--warning);"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
        var sizeText = item.size !== null ? formatSizeJS(item.size) : '';
        html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 4px;border-bottom:1px solid var(--divider);">';
        html += '<input type="checkbox" ' + checked + ' onchange="taskToggleItem(\'' + escapeHtml(fullPath) + '\', this.checked)" style="width:16px;height:16px;flex-shrink:0;">';
        html += icon;
        if (item.is_dir) {
            html += '<a href="javascript:void(0)" onclick="taskBrowserLoad(\'' + escapeHtml(fullPath) + '\')" style="flex:1;font-size:13px;color:var(--text-primary);text-decoration:none;">' + escapeHtml(item.name) + '/</a>';
        } else {
            html += '<span style="flex:1;font-size:13px;">' + escapeHtml(item.name) + '</span>';
            html += '<span style="font-size:11px;color:var(--text-muted);">' + sizeText + '</span>';
        }
        html += '</div>';
    }
    list.innerHTML = html;
}

function taskToggleAll() {
    var checkboxes = document.querySelectorAll('#task-file-list input[type="checkbox"]');
    var anyUnchecked = false;
    checkboxes.forEach(function(cb) { if (!cb.checked) anyUnchecked = true; });
    var newState = anyUnchecked;
    checkboxes.forEach(function(cb) {
        cb.checked = newState;
        var row = cb.closest('div');
        if (row) {
            var link = row.querySelector('a');
            var span = row.querySelector('span');
            var nameEl = link || span;
            if (nameEl) {
                var name = nameEl.textContent.replace(/\/$/, '');
                var fullPath = taskCurrentPath ? taskCurrentPath + '/' + name : name;
                taskSelectedItems[fullPath] = newState;
            }
        }
    });
    updateTaskHiddenField();
}

function taskToggleItem(path, checked) {
    taskSelectedItems[path] = checked;
    updateTaskHiddenField();
}

function updateTaskHiddenField() {
    var selected = [];
    for (var path in taskSelectedItems) {
        if (taskSelectedItems[path]) selected.push(path);
    }
    document.getElementById('task-backup-items').value = selected.length > 0 ? JSON.stringify(selected) : '';
}

function formatSizeJS(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function onTaskPathTypeChange() {
    var type = document.getElementById('task-path-type').value;
    var hint = document.getElementById('task-dest-hint');
    var input = document.getElementById('task-dest');
    if (type === 'absolute') {
        hint.innerHTML = '绝对路径 — 服务器上的完整路径（如 <code>/mnt/disk/backups/</code>），请以 <code>/</code> 开头。';
        if (input.value.indexOf('/') !== 0) {
            input.placeholder = '/mnt/disk/backups/';
        }
    } else {
        hint.innerHTML = '相对路径 — 存于网站 <code>backups/</code> 目录下（如 <code>SZYDMC-JAVA/</code>）。';
        input.placeholder = '';
    }
}
</script>

<?php adminFooter(); ?>
