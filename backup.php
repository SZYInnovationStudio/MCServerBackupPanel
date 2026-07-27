<?php
/**
 * MCServerBackupPanel — Backup Management
 *
 * View, download, restore, and manage backup records.
 * Supports immediate backup per server and file proxy download.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/admin_layout.php';

$db = getDB();
$csrfToken = generateCSRF();

// Get all servers
$allServers = $db->query("SELECT id, name, directory FROM servers ORDER BY name ASC")->fetchAll();
$selectedServerId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : 0;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 开启输出缓冲，捕获 PHP 警告/错误输出，防止破坏 JSON 响应
    ob_start();
    error_reporting(0);
    ini_set('display_errors', 0);

    $response = ['success' => false, 'message' => '未知错误'];

    try {
        if (!verifyCSRF()) {
            $response = ['success' => false, 'message' => 'CSRF验证失败'];
        } else {
            $action = $_POST['action'] ?? '';

            // Toggle public/private
            if ($action === 'toggle_public') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("UPDATE backup_records SET is_public = NOT is_public WHERE id = ?");
                $stmt->execute([$id]);
                $response = ['success' => true];
            }

            // Delete record
            elseif ($action === 'delete_record') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $response = ['success' => false, 'message' => '无效记录ID'];
                } else {
                    $record = $db->prepare("SELECT * FROM backup_records WHERE id = ?");
                    $record->execute([$id]);
                    $record = $record->fetch();
                    if ($record) {
                        if (file_exists($record['file_path'])) {
                            @unlink($record['file_path']);
                        }
                        $db->prepare("DELETE FROM backup_records WHERE id = ?")->execute([$id]);
                    }
                    $response = ['success' => true];
                }
            }

            // Restore backup
            elseif ($action === 'restore') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $response = ['success' => false, 'message' => '无效记录ID'];
                } else {
                    $record = $db->prepare(
                        "SELECT br.*, s.name AS server_name, s.directory AS server_dir
                         FROM backup_records br
                         JOIN servers s ON br.server_id = s.id
                         WHERE br.id = ?"
                    );
                    $record->execute([$id]);
                    $record = $record->fetch();

                    if (!$record) {
                        $response = ['success' => false, 'message' => '备份记录不存在'];
                    } elseif (!file_exists($record['file_path'])) {
                        $response = ['success' => false, 'message' => '备份文件不存在'];
                    } else {
                        $serverDir = rtrim($record['server_dir'], '/') . '/';
                        $preRestoreDone = false;
                        // Pre-restore backup — attempt to snapshot current server state
                        try {
                            $tempBackupDir = BACKUP_ROOT . '/pre_restore/';
                            ensureDir($tempBackupDir);
                            $tempFile = $tempBackupDir . 'pre_restore_' . $record['server_name'] . '_' . date('Ymd_His') . '.zip';
                            $tempZip = new ZipArchive();
                            if ($tempZip->open($tempFile, ZipArchive::CREATE) === true) {
                                $files = new RecursiveIteratorIterator(
                                    new RecursiveDirectoryIterator($serverDir, RecursiveDirectoryIterator::SKIP_DOTS),
                                    RecursiveIteratorIterator::SELF_FIRST
                                );
                                foreach ($files as $file) {
                                    $filePath = $file->getRealPath();
                                    $relativePath = substr($filePath, strlen($serverDir));
                                    if ($file->isDir()) {
                                        $tempZip->addEmptyDir($relativePath);
                                    } else {
                                        $tempZip->addFile($filePath, $relativePath);
                                    }
                                }
                                $tempZip->close();
                                if (is_file($tempFile) && filesize($tempFile) > 0) {
                                    $preRestoreDone = true;
                                }
                            }
                        } catch (Exception $e) {
                            // Log the failure but don't block restore
                            error_log('MCSBP pre-restore backup failed: ' . $e->getMessage());
                        }

                        $zip = new ZipArchive();
                        if ($zip->open($record['file_path']) !== true) {
                            $response = ['success' => false, 'message' => '无法打开备份文件'];
                        } else {
                            ensureDir($serverDir);
                            // Validate each entry to prevent ZIP Slip attacks
                            $zipSlip = false;
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $entryName = $zip->getNameIndex($i);
                                if ($entryName === false) continue;
                                $entryName = str_replace('\\', '/', $entryName);
                                if (strpos($entryName, '..') !== false || strpos($entryName, '/') === 0) {
                                    $zipSlip = true;
                                    break;
                                }
                            }
                            if ($zipSlip) {
                                $zip->close();
                                $response = ['success' => false, 'message' => '备份文件包含不安全路径，拒绝恢复'];
                            } else {
                                $zip->extractTo($serverDir);
                                $zip->close();
                                $restoreMsg = $preRestoreDone
                                    ? '备份已恢复至服务器目录。恢复前已自动备份原文件。'
                                    : '备份已恢复至服务器目录。（注意：恢复前自动备份失败，无回滚文件）';
                                $response = ['success' => true, 'message' => $restoreMsg];
                            }
                        }
                    }
                }
            }

            // Set download password
            elseif ($action === 'set_password') {
                $id = (int)($_POST['id'] ?? 0);
                $pwd = trim($_POST['password'] ?? '');
                if ($id <= 0) {
                    $response = ['success' => false, 'message' => '无效记录ID'];
                } else {
                    if ($pwd === '') {
                        $db->prepare("UPDATE backup_records SET download_password = NULL WHERE id = ?")->execute([$id]);
                    } else {
                        $hash = password_hash($pwd, PASSWORD_BCRYPT);
                        $db->prepare("UPDATE backup_records SET download_password = ? WHERE id = ?")->execute([$hash, $id]);
                    }
                    $response = ['success' => true, 'message' => $pwd === '' ? '密码已清除' : '密码已设置'];
                }
            }

            // Rename backup record
            elseif ($action === 'rename') {
                $id       = (int)($_POST['id'] ?? 0);
                $newName  = trim($_POST['new_name'] ?? '');
                if ($id <= 0 || $newName === '') {
                    $response = ['success' => false, 'message' => '无效参数'];
                } else {
                    $record = $db->prepare("SELECT * FROM backup_records WHERE id = ?");
                    $record->execute([$id]);
                    $record = $record->fetch();
                    if (!$record) {
                        $response = ['success' => false, 'message' => '记录不存在'];
                    } else {
                        $oldPath = $record['file_path'];
                        $dir = dirname($oldPath);
                        $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
                        $newFileName = safeFilename($newName);
                        if (!preg_match('/\.' . $ext . '$/i', $newFileName)) {
                            $newFileName .= '.' . $ext;
                        }
                        $newPath = $dir . '/' . $newFileName;
                        $newPath = str_replace('\\', '/', $newPath);
                        if (file_exists($newPath)) {
                            $response = ['success' => false, 'message' => '同名文件已存在'];
                        } elseif (!rename($oldPath, $newPath)) {
                            $response = ['success' => false, 'message' => '文件重命名失败'];
                        } else {
                            $db->prepare("UPDATE backup_records SET file_path = ?, filename = ? WHERE id = ?")
                                ->execute([$newPath, $newFileName, $id]);
                            $response = ['success' => true, 'message' => '已重命名为：' . $newFileName];
                        }
                    }
                }
            }

            // Immediate backup
            elseif ($action === 'backup_now') {
                set_time_limit(0);
                @ini_set('memory_limit', '-1');

                $serverId = (int)($_POST['server_id'] ?? 0);
                $selectedItems = json_decode($_POST['backup_items'] ?? '[]', true) ?: [];
                if ($serverId <= 0) {
                    $response = ['success' => false, 'message' => '无效服务器ID'];
                } else {
                    $server = $db->prepare("SELECT * FROM servers WHERE id = ?");
                    $server->execute([$serverId]);
                    $server = $server->fetch();

                    if (!$server) {
                        $response = ['success' => false, 'message' => '服务器不存在'];
                    } elseif (!is_dir($server['directory'])) {
                        $response = ['success' => false, 'message' => '服务器目录不存在：' . $server['directory']];
                    } else {
                        $sourceDir = $server['directory'];
                        $backupDest = trim($_POST['backup_destination'] ?? '');
                        $isAbsolute = ($_POST['backup_path_type'] ?? 'relative') === 'absolute';
                        $destDir = $backupDest !== ''
                            ? normalizeBackupPath($backupDest, $isAbsolute)
                            : BACKUP_ROOT . '/' . safeFilename($server['name']);
                        if (!ensureDir($destDir)) {
                            $response = ['success' => false, 'message' => '无法创建备份目录：' . $destDir];
                        } else {
                            $filename = safeFilename($server['name']) . '_' . date('Y-m-d_H-i-s') . '.zip';

                            // Custom filename with variable support
                            $customFilename = trim($_POST['backup_filename'] ?? '');
                            if ($customFilename !== '') {
                                $vars = [
                                    '{server_name}' => $server['name'],
                                    '{date}'        => date('Y-m-d'),
                                    '{time}'        => date('H-i-s'),
                                    '{datetime}'    => date('Y-m-d_H-i-s'),
                                ];
                                $customFilename = str_replace(array_keys($vars), array_values($vars), $customFilename);
                                $customFilename = safeFilename($customFilename);
                                if (!preg_match('/\.zip$/i', $customFilename)) {
                                    $customFilename .= '.zip';
                                }
                                $filename = $customFilename;
                            }

                            $filePath = preg_replace('#/+#', '/', rtrim($destDir, '/') . '/' . $filename);

                            // Insert job record
                            $jobStmt = $db->prepare("INSERT INTO backup_jobs (server_id, task_id, filename, status, message) VALUES (?, NULL, ?, 'running', '开始备份...')");
                            $jobStmt->execute([$serverId, $filename]);
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
                                @unlink($filePath);
                                logJob($db, $jobId, 'error', 'Backup failed: ' . $zipResult);
                                setJobStatus($db, $jobId, 'failed', $zipResult);
                            } else {
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
                                    logJob($db, $jobId, 'error', 'ZIP file is 0 bytes');
                                    setJobStatus($db, $jobId, 'failed', 'ZIP文件写入失败（0字节）');
                                } else {
                                    $stmt = $db->prepare("INSERT INTO backup_records (server_id, task_id, filename, file_size, file_path, is_public, created_at) VALUES (?, NULL, ?, ?, ?, 1, NOW())");
                                    $stmt->execute([$serverId, $filename, $fileSize, $filePath]);
                                    $recordId = (int)$db->lastInsertId();
                                    logJob($db, $jobId, 'info', 'Backup done: ' . formatSize($fileSize) . ' (record ' . $recordId . ')');
                                    setJobStatus($db, $jobId, 'success', '备份完成: ' . formatSize($fileSize));
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

    // 丢弃缓冲区内 PHP 意外输出的警告/错误，确保返回纯 JSON
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle download via proxy
if (isset($_GET['download']) && !empty($_SESSION['admin_id'])) {
    $downloadId = (int)$_GET['download'];
    if ($downloadId > 0) {
        $record = $db->prepare("SELECT * FROM backup_records WHERE id = ?");
        $record->execute([$downloadId]);
        $record = $record->fetch();

        if ($record && file_exists($record['file_path'])) {
            $filePath = $record['file_path'];
            $filename = $record['filename'];

            // Path security: allow files under BACKUP_ROOT or absolute paths outside it
            $realPath = str_replace('\\', '/', (string) @realpath($filePath));
            $baseAllowed = str_replace('\\', '/', (string) @realpath(BACKUP_ROOT));

            $allowDownload = false;
            if ($realPath && $baseAllowed) {
                // Under BACKUP_ROOT → always allowed
                $allowDownload = (strpos($realPath, $baseAllowed) === 0);
            }
            // Absolute path outside BACKUP_ROOT → allowed if readable
            if (!$allowDownload && $realPath) {
                $allowDownload = is_readable($realPath);
            }

            // Files with download_password: require password verification
            if ($allowDownload && !empty($record['download_password'])) {
                if (empty($_SESSION['download_auth_' . $downloadId])) {
                    header('Location: backup.php');
                    exit;
                }
                unset($_SESSION['download_auth_' . $downloadId]);
                session_write_close();
                // Serve the file directly (password already verified, no decryption needed)
            }

            if ($allowDownload) {
                streamDownload($filePath, $filename);
                // streamDownload exits internally
            }
        }
    }
    // Fall through to normal page if download fails
}

// Handle password verification for encrypted download (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_admin_download') {
    ob_start();
    try {
        if (!verifyCSRF()) {
            echo json_encode(['success' => false, 'message' => 'CSRF验证失败，请刷新页面后重试。']);
            ob_end_clean();
            exit;
        }
        $recordId = (int)($_POST['record_id'] ?? 0);
        $password = $_POST['password'] ?? '';

        $record = $db->prepare("SELECT * FROM backup_records WHERE id = ?");
        $record->execute([$recordId]);
        $record = $record->fetch();

        if (!$record || empty($record['download_password'])) {
            echo json_encode(['success' => false, 'message' => '该备份无密码保护']);
        } elseif (!password_verify($password, $record['download_password'])) {
            echo json_encode(['success' => false, 'message' => '密码错误']);
        } else {
            $_SESSION['download_auth_' . $recordId] = true;
            session_write_close();
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    ob_end_clean();
    exit;
}

// Fetch backup records
$query = "
    SELECT br.*, s.name AS server_name
    FROM backup_records br
    JOIN servers s ON br.server_id = s.id
";
$params = [];
if ($selectedServerId > 0) {
    $query .= " WHERE br.server_id = ?";
    $params[] = $selectedServerId;
}
$query .= " ORDER BY br.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

adminHeader('备份管理', 'backup');
?>

<div class="page-content">
    <div class="page-header">
        <h2>备份管理</h2>
    </div>

    <!-- Server quick backup -->
    <?php if (!empty($allServers)): ?>
    <div class="card mb-24">
        <div class="card-header">
            <h2 class="card-title">立即备份</h2>
        </div>
        <div style="padding:16px 20px 20px;">
            <!-- Server selector -->
            <div class="form-group">
                <label class="form-label">选择服务器</label>
                <select id="backup-server-select" class="form-select" style="max-width:320px;" onchange="onServerChange()">
                    <option value="">-- 请选择服务器 --</option>
                    <?php foreach ($allServers as $server): ?>
                    <option value="<?php echo $server['id']; ?>"><?php echo htmlspecialchars($server['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- File browser -->
            <div id="file-browser-panel" class="hidden" style="margin-top:12px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <div id="breadcrumb-nav" style="font-size:13px;color:var(--text-secondary);">
                        <span class="breadcrumb-link" onclick="loadDirectory(window._browseServerId, '')" style="cursor:pointer;color:var(--accent);">根目录</span>
                    </div>
                    <button class="btn btn-ghost btn-sm" onclick="toggleAll()" id="btn-toggle-all">全选 / 取消全选</button>
                </div>
                <div id="file-list-container" style="max-height:280px;overflow-y:auto;border:1px solid var(--border-color);border-radius:6px;padding:8px;">
                    <div style="text-align:center;padding:24px;color:var(--text-muted);">请选择服务器以浏览文件</div>
                </div>
            </div>

            <!-- Backup filename -->
            <div id="filename-row" class="hidden" style="margin-top:12px;">
                <label class="form-label">
                    备份文件名
                    <span style="font-weight:400;font-size:12px;color:var(--text-muted);margin-left:8px;">
                        可用变量：<code style="font-family:var(--font-mono);font-size:11px;background:var(--divider);padding:1px 4px;border-radius:3px;">{server_name}</code>
                        <code style="font-family:var(--font-mono);font-size:11px;background:var(--divider);padding:1px 4px;border-radius:3px;">{date}</code>
                        <code style="font-family:var(--font-mono);font-size:11px;background:var(--divider);padding:1px 4px;border-radius:3px;">{time}</code>
                        <code style="font-family:var(--font-mono);font-size:11px;background:var(--divider);padding:1px 4px;border-radius:3px;">{datetime}</code>
                    </span>
                </label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="text" id="backup-filename-input" class="form-input" style="max-width:420px;font-family:var(--font-mono);font-size:13px;" placeholder="{server_name}_{date}_{time}.zip" spellcheck="false" oninput="previewFilename()">
                    <span id="filename-preview" style="font-size:12px;color:var(--text-muted);white-space:nowrap;"></span>
                </div>
            </div>

            <!-- Backup destination -->
            <div id="dest-row" class="hidden" style="margin-top:12px;">
                <label class="form-label" for="backup-dest-input">备份存放地址</label>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <select id="backup-path-type" class="form-select" style="width:auto;min-width:100px;" onchange="onBackupPathTypeChange()">
                        <option value="relative">相对路径</option>
                        <option value="absolute">绝对路径</option>
                    </select>
                </div>
                <input type="text" id="backup-dest-input" class="form-input" style="max-width:420px;font-family:var(--font-mono);font-size:13px;" placeholder="">
                <div class="form-hint" id="backup-dest-hint">相对路径 — 存于网站 <code>backups/</code> 目录下（如 <code>SZYDMC-JAVA/</code>）。</div>
            </div>

            <!-- Backup button -->
            <div id="backup-action-row" class="hidden" style="margin-top:16px;">
                <button class="btn btn-primary" id="btn-backup-selected" onclick="startBackup()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    立即备份
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter & Records -->
    <div class="card mb-24">
        <div class="card-header">
            <h2 class="card-title">备份记录</h2>
            <select class="form-select" style="width:auto;min-width:180px;" onchange="filterServer(this.value)">
                <option value="0">全部服务器</option>
                <?php foreach ($allServers as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php echo $selectedServerId === (int)$s['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (empty($records)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/>
                <polyline points="13 2 13 9 20 9"/>
            </svg>
            <h3>暂无备份记录</h3>
            <p>执行备份任务或点击上方"立即备份"按钮创建备份。</p>
        </div>
        <?php else: ?>
        <div class="table-container" style="padding:0 20px 20px;">
            <table>
                <thead>
                    <tr>
                        <th>文件名</th>
                        <th>服务器</th>
                        <th>大小</th>
                        <th>时间</th>
                        <th>公开</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                    <tr>
                        <td data-label="文件名"><span class="text-mono" style="font-size:13px;"><?php echo htmlspecialchars($record['filename']); ?></span></td>
                        <td data-label="服务器"><?php echo htmlspecialchars($record['server_name']); ?></td>
                        <td data-label="大小"><?php echo formatSize((int)$record['file_size']); ?></td>
                        <td data-label="时间"><?php echo htmlspecialchars($record['created_at']); ?></td>
                        <td data-label="公开">
                            <?php if ($record['is_public']): ?>
                                <span class="badge badge-green">公开</span>
                            <?php else: ?>
                                <span class="badge badge-slate">私有</span>
                            <?php endif; ?>
                            <?php if (!empty($record['download_password'])): ?>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-left:4px;color:var(--warning);" title="已设密码"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            <?php endif; ?>
                        </td>
                        <td data-label="操作" class="td-actions">
                            <?php if (!empty($record['download_password'])): ?>
                            <button class="btn btn-ghost btn-sm" onclick="downloadEncrypted(<?php echo $record['id']; ?>)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>下载
                            </button>
                            <?php else: ?>
                            <a href="backup.php?download=<?php echo $record['id']; ?>" class="btn btn-ghost btn-sm">下载</a>
                            <?php endif; ?>
                            <button class="btn btn-ghost btn-sm" onclick="openPwdModal(<?php echo $record['id']; ?>, <?php echo !empty($record['download_password']) ? 'true' : 'false'; ?>)"><?php echo !empty($record['download_password']) ? '更改密码' : '设密码'; ?></button>
                            <?php if (!empty($record['download_password'])): ?>
                            <button class="btn btn-ghost btn-sm" style="color:var(--danger);" onclick="clearPassword(<?php echo $record['id']; ?>)">取消密码</button>
                            <?php endif; ?>
                            <button class="btn btn-ghost btn-sm" onclick="openRenameModal(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars(addslashes($record['filename'])); ?>')">重命名</button>
                            <button class="btn btn-ghost btn-sm" onclick="togglePublic(<?php echo $record['id']; ?>)">
                                <?php echo $record['is_public'] ? '设为私有' : '设为公开'; ?>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="confirmRestore(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars(addslashes($record['filename'])); ?>', '<?php echo htmlspecialchars(addslashes($record['server_name'])); ?>')">恢复</button>
                            <button class="btn btn-danger btn-sm" onclick="confirmDeleteRecord(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars(addslashes($record['filename'])); ?>')">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Restore Confirm Modal -->
<div class="modal-overlay hidden" id="restore-modal">
    <div class="modal">
        <div class="modal-body">
            <div class="confirm-content">
                <div class="confirm-icon danger">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h3>确认恢复备份</h3>
                <p id="restore-msg">恢复操作将覆盖服务器当前文件。系统会在恢复前自动创建一份临时备份。请确认此操作。</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('restore-modal')">取消</button>
            <button class="btn btn-danger" id="btn-confirm-restore">确认恢复</button>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay hidden" id="delete-record-modal">
    <div class="modal">
        <div class="modal-body">
            <div class="confirm-content">
                <div class="confirm-icon danger">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h3>确认删除备份</h3>
                <p id="delete-record-msg">删除后将永久移除备份文件及记录，此操作不可撤销。</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('delete-record-modal')">取消</button>
            <button class="btn btn-danger" id="btn-confirm-delete-record">确认删除</button>
        </div>
    </div>
</div>

<!-- Set Password Modal -->
<div class="modal-overlay hidden" id="password-modal">
    <div class="modal pw-modal" style="max-width:400px;">
        <form id="set-pwd-form" onsubmit="event.preventDefault(); savePassword();">
        <div class="modal-body" style="text-align:center;padding:28px 24px 20px;">
            <div class="pw-modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                    <circle cx="12" cy="16" r="1"/>
                </svg>
            </div>
            <div class="pw-modal-title">设置下载密码</div>
            <div class="pw-modal-desc">为当前备份设置下载密码保护，用户在公开页下载时需输入密码。</div>
            <div class="form-group">
                <input type="password" id="pwd-input" class="form-input pw-input-field" placeholder="请输入密码">
                <p class="form-hint" style="margin-top:6px;font-size:12px;color:var(--text-muted);">留空则取消密码保护，无需密码即可下载</p>
            </div>
        </div>
        <div class="pw-modal-footer">
            <button class="btn btn-secondary" onclick="closePwdModal()" type="button">取消</button>
            <button class="btn btn-primary" id="btn-save-pwd" type="submit">保存</button>
        </div>
        </form>
    </div>
</div>

<style>
/* 设密码弹窗 — 与公开下载页密码弹窗保持一致的精致风格 */
.pw-modal-icon {
    width: 56px; height: 56px; border-radius: 50%;
    background: #fffbeb; color: var(--warning);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
}
.pw-modal-icon svg { width: 28px; height: 28px; }
.pw-modal-title { font-size: 17px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
.pw-modal-desc { font-size: 13px; color: var(--text-muted); margin-bottom: 20px; line-height: 1.5; }
.pw-modal-footer { display: flex; gap: 10px; padding: 0 24px 20px; }
.pw-modal-footer .btn { flex: 1; }
.pw-input-field { text-align: center; font-size: 16px; }

@media (max-width: 639px) {
    #password-modal.modal-overlay,
    #enc-download-modal.modal-overlay {
        padding: 20px;
        align-items: center;
    }
    #password-modal .modal,
    #enc-download-modal .modal {
        max-width: 92vw;
        border-radius: 14px;
        margin: 0;
    }
    .pw-modal-icon { width: 48px; height: 48px; margin-bottom: 12px; }
    .pw-modal-icon svg { width: 24px; height: 24px; }
    .pw-modal-title { font-size: 16px; }
    .pw-modal-desc { font-size: 13px; margin-bottom: 16px; }
    .pw-input-field { height: 48px; font-size: 16px; }
    .pw-modal-footer { padding: 0 20px 20px; gap: 8px; }
    .pw-modal-footer .btn { height: 44px; font-size: 14px; }

    /* 重命名弹窗 — 同样居中，不底部弹出 */
    #rename-modal.modal-overlay {
        padding: 20px;
        align-items: center;
    }
    #rename-modal .modal {
        max-width: 92vw;
        border-radius: 14px;
        margin: 0;
    }
    #rename-modal .modal-header { padding: 18px 18px 0; }
    #rename-modal .modal-header h2 { font-size: 16px; }
    #rename-modal .modal-body { padding: 16px 18px; }
    #rename-modal .modal-footer {
        flex-direction: row;
        padding: 0 18px 18px;
        gap: 8px;
    }
    #rename-modal .modal-footer .btn {
        flex: 1;
        height: 44px;
        font-size: 14px;
    }

    /* 确认弹窗（恢复/删除） — 居中，不底部弹出 */
    #restore-modal.modal-overlay,
    #delete-record-modal.modal-overlay {
        padding: 20px;
        align-items: center;
    }
    #restore-modal .modal,
    #delete-record-modal .modal {
        max-width: 92vw;
        border-radius: 14px;
        margin: 0;
    }
    #restore-modal .modal-body,
    #delete-record-modal .modal-body {
        padding: 24px 20px 16px;
    }
    #restore-modal .confirm-icon,
    #delete-record-modal .confirm-icon {
        width: 48px; height: 48px; margin-bottom: 12px;
    }
    #restore-modal .confirm-icon svg,
    #delete-record-modal .confirm-icon svg {
        width: 24px; height: 24px;
    }
    #restore-modal h3,
    #delete-record-modal h3 { font-size: 16px; margin-bottom: 6px; }
    #restore-modal p,
    #delete-record-modal p { font-size: 13px; }
    #restore-modal .modal-footer,
    #delete-record-modal .modal-footer {
        flex-direction: row;
        padding: 0 20px 20px;
        gap: 8px;
    }
    #restore-modal .modal-footer .btn,
    #delete-record-modal .modal-footer .btn {
        flex: 1;
        height: 44px;
        font-size: 14px;
    }
}
</style>

<!-- Encrypted download password modal (admin) -->
<div class="modal-overlay hidden" id="enc-download-modal">
    <div class="modal pw-modal" style="max-width:400px;">
        <form id="enc-dl-form" onsubmit="event.preventDefault(); submitEncDownload();">
        <div class="modal-body" style="text-align:center;padding:28px 24px 20px;">
            <div class="pw-modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                    <circle cx="12" cy="16" r="1"/>
                </svg>
            </div>
            <div class="pw-modal-title">输入下载密码</div>
            <div class="pw-modal-desc">此备份文件已设置下载密码保护，请输入密码以下载。</div>
            <div class="form-group">
                <input type="password" id="enc-dl-pwd-input" class="form-input pw-input-field" placeholder="请输入下载密码" autocomplete="off">
            </div>
        </div>
        <div class="pw-modal-footer">
            <button class="btn btn-secondary" onclick="closeEncDownloadModal()" type="button">取消</button>
            <button class="btn btn-primary" id="btn-enc-dl-submit" type="submit">解锁下载</button>
        </div>
        </form>
    </div>
</div>

<!-- Rename Modal -->
<div class="modal-overlay hidden" id="rename-modal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h2>重命名备份文件</h2>
            <button class="btn btn-ghost btn-sm" onclick="closeModal('rename-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">文件名</label>
                <input type="text" id="rename-input" class="form-input" placeholder="请输入新文件名">
                <p class="form-hint">扩展名 .zip 会自动补齐</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('rename-modal')">取消</button>
            <button class="btn btn-primary" id="btn-rename-submit" onclick="doRename()">确认重命名</button>
        </div>
    </div>
</div>

<input type="hidden" id="global-csrf" value="<?php echo $csrfToken; ?>">

<script>
function filterServer(id) {
    window.location.href = 'backup.php?server_id=' + id;
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function togglePublic(id) {
    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_public&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            location.reload();
        }
    });
}

function confirmRestore(id, filename, serverName) {
    document.getElementById('restore-msg').textContent = '即将恢复备份「' + filename + '」至服务器「' + serverName + '」。恢复操作将覆盖服务器当前文件。系统会在恢复前自动创建一份临时备份。请确认此操作。';
    document.getElementById('btn-confirm-restore').onclick = function() { doRestore(id); };
    document.getElementById('restore-modal').classList.remove('hidden');
}

function doRestore(id) {
    var btn = document.getElementById('btn-confirm-restore');
    btn.disabled = true;
    btn.classList.add('btn-loading');
    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=restore&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('success', '恢复成功', res.message);
            closeModal('restore-modal');
        } else {
            showToast('error', '恢复失败', res.message);
        }
        btn.disabled = false;
        btn.classList.remove('btn-loading');
    });
}

function confirmDeleteRecord(id, filename) {
    document.getElementById('delete-record-msg').textContent = '确定要删除备份「' + filename + '」吗？删除后将永久移除备份文件及记录，此操作不可撤销。';
    document.getElementById('btn-confirm-delete-record').onclick = function() { deleteRecord(id); };
    document.getElementById('delete-record-modal').classList.remove('hidden');
}

function deleteRecord(id) {
    var btn = document.getElementById('btn-confirm-delete-record');
    btn.disabled = true;
    btn.classList.add('btn-loading');
    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete_record&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('success', '已删除', '备份记录已删除。');
            closeModal('delete-record-modal');
            setTimeout(function() { location.reload(); }, 500);
        } else {
            showToast('error', '删除失败', res.message);
            btn.disabled = false;
            btn.classList.remove('btn-loading');
        }
    });
}

// ===== 取消密码 =====
function clearPassword(id) {
    if (!confirm('确定要取消此备份的下载密码吗？取消后任何人都可以直接下载。')) return;

    var btn = document.querySelector('button[onclick="clearPassword(' + id + ')"]');
    if (btn) { btn.disabled = true; btn.textContent = '请稍后...'; }

    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=set_password&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + id + '&password='
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('success', '已取消', '下载密码已清除');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showToast('error', '操作失败', res.message || '未知错误');
            if (btn) { btn.disabled = false; btn.textContent = '取消密码'; }
        }
    })
    .catch(function() {
        showToast('error', '网络错误', '请求失败，请重试');
        if (btn) { btn.disabled = false; btn.textContent = '取消密码'; }
    });
}

// ===== Rename =====
var _renameId = 0;
function openRenameModal(id, currentName) {
    _renameId = id;
    document.getElementById('rename-input').value = currentName.replace(/\.zip$/i, '');
    document.getElementById('rename-modal').classList.remove('hidden');
    setTimeout(function() { document.getElementById('rename-input').focus(); document.getElementById('rename-input').select(); }, 100);
}
function doRename() {
    var newName = document.getElementById('rename-input').value.trim();
    if (!newName) return;
    var btn = document.getElementById('btn-rename-submit');
    btn.disabled = true;
    btn.textContent = '处理中...';
    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=rename&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + _renameId + '&new_name=' + encodeURIComponent(newName)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('success', '成功', res.message);
            closeModal('rename-modal');
            setTimeout(function() { location.reload(); }, 500);
        } else {
            btn.disabled = false;
            btn.textContent = '确认重命名';
            showToast('error', '失败', res.message);
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = '确认重命名';
        showToast('error', '错误', '网络请求失败');
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var renameInput = document.getElementById('rename-input');
    if (renameInput) {
        renameInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doRename();
        });
    }
});

// ===== Password Modal =====
var _pwdRecordId = 0;

function openPwdModal(id, hasPassword) {
    _pwdRecordId = id;
    document.getElementById('pwd-input').value = '';
    document.querySelector('#password-modal .pw-modal-title').textContent = hasPassword ? '更改下载密码' : '设置下载密码';
    document.querySelector('#password-modal .pw-modal-desc').textContent = hasPassword ? '修改此备份的下载密码，用户在公开页下载时需输入密码。' : '为当前备份设置下载密码保护，用户在公开页下载时需输入密码。';
    document.getElementById('password-modal').classList.remove('hidden');
}

function closePwdModal() {
    document.getElementById('password-modal').classList.add('hidden');
    _pwdRecordId = 0;
}

// --- Encrypted download password modal ---
var _encDownloadId = 0;
function downloadEncrypted(id) {
    _encDownloadId = id;
    document.getElementById('enc-dl-pwd-input').value = '';
    document.getElementById('enc-download-modal').classList.remove('hidden');
    setTimeout(function() { document.getElementById('enc-dl-pwd-input').focus(); }, 100);
}
function closeEncDownloadModal() {
    document.getElementById('enc-download-modal').classList.add('hidden');
    _encDownloadId = 0;
}
function submitEncDownload() {
    var pwd = document.getElementById('enc-dl-pwd-input').value.trim();
    if (!pwd) { return; }
    var btn = document.getElementById('btn-enc-dl-submit');
    btn.disabled = true;
    btn.textContent = '验证中...';
    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=verify_admin_download&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&record_id=' + _encDownloadId + '&password=' + encodeURIComponent(pwd)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            closeEncDownloadModal();
            window.location.href = 'backup.php?download=' + _encDownloadId;
        } else {
            btn.disabled = false;
            btn.textContent = '解锁下载';
            showToast('error', '密码错误', res.message);
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = '解锁下载';
        showToast('error', '错误', '网络请求失败');
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var encInput = document.getElementById('enc-dl-pwd-input');
    if (encInput) {
        encInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') submitEncDownload();
        });
    }
});

function savePassword() {
    var pwd = document.getElementById('pwd-input').value.trim();
    var btn = document.getElementById('btn-save-pwd');
    btn.disabled = true;
    btn.classList.add('btn-loading');

    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=set_password&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + _pwdRecordId + '&password=' + encodeURIComponent(pwd)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.classList.remove('btn-loading');
        if (res.success) {
            showToast('success', '成功', res.message);
            closePwdModal();
            setTimeout(function() { location.reload(); }, 500);
        } else {
            showToast('error', '失败', res.message);
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.classList.remove('btn-loading');
        showToast('error', '错误', '网络请求失败');
    });
}

// ===== File Browser =====
window._browseServerId = 0;
window._browseItems = [];
window._browseChecked = {};
window._browseCurrentPath = '';

function onServerChange() {
    var sel = document.getElementById('backup-server-select');
    var sid = parseInt(sel.value) || 0;
    var panel = document.getElementById('file-browser-panel');
    var actionRow = document.getElementById('backup-action-row');
    var filenameRow = document.getElementById('filename-row');
    var destRow = document.getElementById('dest-row');

    if (sid > 0) {
        window._browseServerId = sid;
        window._browseCurrentPath = '';
        window._browseChecked = {};
        panel.classList.remove('hidden');
        filenameRow.classList.remove('hidden');
        destRow.classList.remove('hidden');
        actionRow.classList.remove('hidden');

        // Set default filename template
        var defaultName = sel.options[sel.selectedIndex].text + '_{date}_{time}.zip';
        document.getElementById('backup-filename-input').value = defaultName;
        document.getElementById('backup-filename-input').setAttribute('placeholder', defaultName);
        previewFilename();

        initFileBrowser(sid);
    } else {
        window._browseServerId = 0;
        panel.classList.add('hidden');
        filenameRow.classList.add('hidden');
        destRow.classList.add('hidden');
        actionRow.classList.add('hidden');
    }
}

/** 实时预览解析后的文件名 */
function previewFilename() {
    var input = document.getElementById('backup-filename-input');
    var preview = document.getElementById('filename-preview');
    var val = input.value.trim();
    if (!val) {
        preview.textContent = '';
        return;
    }
    var sel = document.getElementById('backup-server-select');
    var serverName = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : 'Server';
    var now = new Date();
    var pad = function(n) { return n < 10 ? '0' + n : n; };
    var dateStr = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    var timeStr = pad(now.getHours()) + '-' + pad(now.getMinutes()) + '-' + pad(now.getSeconds());
    var datetimeStr = dateStr + '_' + timeStr;

    var resolved = val
        .replace(/\{server_name\}/g, serverName)
        .replace(/\{date\}/g, dateStr)
        .replace(/\{time\}/g, timeStr)
        .replace(/\{datetime\}/g, datetimeStr);

    if (!resolved.endsWith('.zip')) resolved += '.zip';
    preview.textContent = '→ ' + resolved;
}

function initFileBrowser(serverId) {
    window._browseCurrentPath = '';
    loadDirectory(serverId, '');
}

function loadDirectory(serverId, path) {
    window._browseCurrentPath = path;
    document.getElementById('file-list-container').innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-muted);">加载中...</div>';

    fetch('servers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=browse&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&server_id=' + serverId + '&path=' + encodeURIComponent(path)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            window._browseItems = res.items;
            updateBreadcrumb(path);
            buildFileList(res.items, serverId, path);
        } else {
            document.getElementById('file-list-container').innerHTML = '<div style="text-align:center;padding:24px;color:var(--danger);">' + escapeHtml(res.message || '加载失败') + '</div>';
        }
    })
    .catch(function() {
        document.getElementById('file-list-container').innerHTML = '<div style="text-align:center;padding:24px;color:var(--danger);">网络请求失败</div>';
    });
}

function updateBreadcrumb(path) {
    var bc = document.getElementById('breadcrumb-nav');
    if (!path) {
        bc.innerHTML = '<span style="color:var(--accent);">根目录</span>';
        return;
    }
    var parts = path.split('/');
    var html = '<span class="breadcrumb-link" onclick="loadDirectory(window._browseServerId, \'\')" style="cursor:pointer;color:var(--accent);">根目录</span>';
    var cumulative = '';
    for (var i = 0; i < parts.length; i++) {
        cumulative += (cumulative ? '/' : '') + parts[i];
        html += ' <span style="color:var(--text-muted);">›</span> ';
        if (i === parts.length - 1) {
            html += '<span style="color:var(--text-primary);">' + escapeHtml(parts[i]) + '</span>';
        } else {
            html += '<span class="breadcrumb-link" onclick="loadDirectory(window._browseServerId, \'' + escapeHtml(cumulative).replace(/'/g, "\\'") + '\')" style="cursor:pointer;color:var(--accent);">' + escapeHtml(parts[i]) + '</span>';
        }
    }
    bc.innerHTML = html;
}

function buildFileList(items, serverId, currentPath) {
    if (!items || items.length === 0) {
        document.getElementById('file-list-container').innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-muted);">此目录为空</div>';
        return;
    }

    var getKey = function(item) {
        return currentPath ? currentPath + '/' + item.name : item.name;
    };

    // Auto-check newly loaded items
    for (var i = 0; i < items.length; i++) {
        var k = getKey(items[i]);
        if (!(k in window._browseChecked)) {
            window._browseChecked[k] = true;
        }
    }

    var html = '';
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var key = getKey(item);
        var checked = window._browseChecked[key] ? 'checked' : '';

        html += '<div style="display:flex;align-items:center;padding:4px 4px;border-bottom:1px solid var(--divider);font-size:13px;">';
        html += '<input type="checkbox" ' + checked + ' onchange="window._browseChecked[\'' + escapeHtml(key).replace(/'/g, "\\'") + '\'] = this.checked" style="margin-right:8px;flex-shrink:0;">';

        if (item.is_dir) {
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--warning);flex-shrink:0;margin-right:4px;"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>';
            html += '<span onclick="loadDirectory(window._browseServerId, \'' + escapeHtml(key).replace(/'/g, "\\'") + '\')" style="cursor:pointer;color:var(--accent);flex:1;">' + escapeHtml(item.name) + '/</span>';
        } else {
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);flex-shrink:0;margin-right:4px;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
            html += '<span style="flex:1;">' + escapeHtml(item.name) + '</span>';
            if (item.size != null) {
                html += '<span style="color:var(--text-muted);font-size:12px;flex-shrink:0;">' + formatSizeJS(item.size) + '</span>';
            }
        }
        html += '</div>';
    }
    document.getElementById('file-list-container').innerHTML = html;
}

function toggleAll() {
    var allChecked = true;
    for (var k in window._browseChecked) {
        if (!window._browseChecked[k]) { allChecked = false; break; }
    }
    var newVal = !allChecked;
    for (var k in window._browseChecked) {
        window._browseChecked[k] = newVal;
    }
    // Rebuild the list to reflect changes
    buildFileList(window._browseItems, window._browseServerId, window._browseCurrentPath);
}

function getSelectedItems() {
    var result = [];
    for (var k in window._browseChecked) {
        if (window._browseChecked[k]) {
            result.push(k);
        }
    }
    return result;
}

function startBackup() {
    var serverId = window._browseServerId;
    if (!serverId) {
        showToast('warning', '提示', '请先选择服务器');
        return;
    }
    var selected = getSelectedItems();
    var filenameInput = document.getElementById('backup-filename-input');
    var customFilename = filenameInput ? filenameInput.value.trim() : '';
    var destInput = document.getElementById('backup-dest-input');
    var backupDest = destInput ? destInput.value.trim() : '';
    var pathTypeSelect = document.getElementById('backup-path-type');
    var pathType = pathTypeSelect ? pathTypeSelect.value : 'relative';
    var btn = document.getElementById('btn-backup-selected');
    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner spinner-white"></span> 备份中...';

    fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=backup_now&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&server_id=' + serverId + '&backup_items=' + encodeURIComponent(JSON.stringify(selected)) + '&backup_filename=' + encodeURIComponent(customFilename) + '&backup_destination=' + encodeURIComponent(backupDest) + '&backup_path_type=' + encodeURIComponent(pathType)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        if (res.success) {
            showToast('success', '备份完成', res.message);
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showToast('error', '备份失败', res.message);
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        var msg = '请求失败';
        if (err && err.message) {
            msg += '：' + err.message;
        }
        showToast('error', '备份出错', msg + '。可能是目录过大导致超时，或 PHP ZipArchive 扩展未安装。');
    });
}

function formatSizeJS(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    while (bytes >= 1024 && i < units.length - 1) {
        bytes /= 1024;
        i++;
    }
    return bytes.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function onBackupPathTypeChange() {
    var type = document.getElementById('backup-path-type').value;
    var hint = document.getElementById('backup-dest-hint');
    var input = document.getElementById('backup-dest-input');
    if (type === 'absolute') {
        hint.innerHTML = '绝对路径 — 服务器上的完整路径（如 <code>/mnt/disk/backups/</code>），请以 <code>/</code> 开头。';
        input.placeholder = '/mnt/disk/backups/';
    } else {
        hint.innerHTML = '相对路径 — 存于网站 <code>backups/</code> 目录下（如 <code>SZYDMC-JAVA/</code>）。';
        input.placeholder = '';
    }
}

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (!e.target.closest('.modal')) overlay.classList.add('hidden');
    });
});
</script>

<?php adminFooter(); ?>
