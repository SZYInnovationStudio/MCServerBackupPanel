<?php
/**
 * MCServerBackupPanel — Server Management
 *
 * Add, edit, delete Minecraft servers. Supports auto-detection
 * from MCSManager Daemon instance configs and manual entry.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/admin_layout.php';

$db = getDB();
$csrfToken = generateCSRF();

// Handle CRUD actions
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
                $name = trim($_POST['name'] ?? '');
                $directory = trim($_POST['directory'] ?? '');
                if ($name === '' || $directory === '') {
                    $response = ['success' => false, 'message' => '名称和目录不能为空'];
                } else {
                    $stmt = $db->prepare("INSERT INTO servers (name, directory, created_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$name, $directory]);
                    $response = ['success' => true];
                }
            }

            elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $directory = trim($_POST['directory'] ?? '');
                if ($id <= 0 || $name === '' || $directory === '') {
                    $response = ['success' => false, 'message' => '参数无效'];
                } else {
                    $stmt = $db->prepare("UPDATE servers SET name = ?, directory = ? WHERE id = ?");
                    $stmt->execute([$name, $directory, $id]);
                    $response = ['success' => true];
                }
            }

            elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $response = ['success' => false, 'message' => '无效的服务器ID'];
                } else {
                    // 先物理删除该服务器所有备份归档，避免产生孤儿文件
                    $records = $db->prepare("SELECT file_path FROM backup_records WHERE server_id = ?");
                    $records->execute([$id]);
                    $failedFiles = [];
                    foreach ($records->fetchAll() as $r) {
                        if (!empty($r['file_path']) && file_exists($r['file_path']) && !@unlink($r['file_path'])) {
                            $failedFiles[] = $r['file_path'];
                        }
                    }

                    $db->prepare("DELETE FROM backup_records WHERE server_id = ?")->execute([$id]);
                    $db->prepare("DELETE FROM backup_tasks WHERE server_id = ?")->execute([$id]);
                    $db->prepare("DELETE FROM servers WHERE id = ?")->execute([$id]);

                    if (!empty($failedFiles)) {
                        $response = ['success' => true, 'message' => '服务器已删除，但有 ' . count($failedFiles) . ' 个归档文件删除失败（请手动清理）'];
                    } else {
                        $response = ['success' => true];
                    }
                }
            }

            elseif ($action === 'detect') {
                $daemonPath = trim($_POST['daemon_path'] ?? '/opt/mcsmanager/daemon');
                $instanceConfigPath = rtrim($daemonPath, '/') . '/data/InstanceConfig/';
                $servers = [];

                if (is_dir($instanceConfigPath)) {
                    $files = glob($instanceConfigPath . '*.json');
                    foreach ($files as $file) {
                        $content = @file_get_contents($file);
                        if ($content === false) continue;
                        $config = json_decode($content, true);
                        if ($config && isset($config['nickname'])) {
                            $servers[] = [
                                'name'      => $config['nickname'],
                                'directory' => $config['cwd'] ?? '',
                                'filename'  => basename($file)
                            ];
                        }
                    }
                }

                $response = ['success' => true, 'servers' => $servers];
            }

            elseif ($action === 'batch_add') {
                $selected = json_decode($_POST['selected'] ?? '[]', true) ?: [];
                if (empty($selected)) {
                    $response = ['success' => false, 'message' => '未选中任何服务器'];
                } else {
                    $stmt = $db->prepare("INSERT INTO servers (name, directory, created_at) VALUES (?, ?, NOW())");
                    foreach ($selected as $s) {
                        $name = trim($s['name'] ?? '');
                        $dir = trim($s['directory'] ?? '');
                        if ($name && $dir) {
                            $stmt->execute([$name, $dir]);
                        }
                    }
                    $response = ['success' => true];
                }
            }

            elseif ($action === 'browse') {
                $serverId = (int)($_POST['server_id'] ?? 0);
                $subPath = trim($_POST['path'] ?? '');
                if ($serverId <= 0) {
                    $response = ['success' => false, 'message' => '无效服务器ID'];
                } else {
                    $server = $db->prepare("SELECT * FROM servers WHERE id = ?");
                    $server->execute([$serverId]);
                    $server = $server->fetch();
                    if (!$server) {
                        $response = ['success' => false, 'message' => '服务器不存在'];
                    } else {
                        $baseDir = rtrim(str_replace('\\', '/', $server['directory']), '/');
                        // Security: reject path traversal (.., null bytes, absolute paths in subPath)
                        $subPath = str_replace('\\', '/', $subPath);
                        if (strpos($subPath, "\0") !== false
                            || strpos($subPath, '..') !== false
                            || strpos($subPath, '/') === 0) {
                            $response = ['success' => false, 'message' => '无效路径'];
                        } else {
                            $currentDir = $baseDir . ($subPath !== '' ? '/' . $subPath : '');
                            // Double-check: resolved path must stay within baseDir
                            $resolved = realpath($currentDir);
                            $baseReal = str_replace('\\', '/', (string) realpath($baseDir));
                            if ($resolved === false || $baseReal === '' || strpos(str_replace('\\', '/', $resolved), $baseReal) !== 0) {
                                $response = ['success' => false, 'message' => '拒绝访问'];
                            } else {
                                $currentDir = str_replace('\\', '/', $currentDir);

                                if (!is_dir($currentDir)) {
                                    $response = ['success' => false, 'message' => '目录不存在：' . $currentDir];
                                } else {
                                    $items = [];
                                    $entries = scandir($currentDir);
                                    foreach ($entries as $entry) {
                                        if ($entry === '.' || $entry === '..') continue;
                                        $fullPath = $currentDir . '/' . $entry;
                                        $relPath = ($subPath ? $subPath . '/' : '') . $entry;
                                        $isDir = is_dir($fullPath);
                                        $items[] = [
                                            'name' => $entry,
                                            'path' => $relPath,
                                            'is_dir' => $isDir,
                                            'size' => $isDir ? null : (is_file($fullPath) ? filesize($fullPath) : 0),
                                        ];
                                    }
                                    // Sort: directories first, then files
                                    usort($items, function($a, $b) {
                                        if ($a['is_dir'] && !$b['is_dir']) return -1;
                                        if (!$a['is_dir'] && $b['is_dir']) return 1;
                                        return strcasecmp($a['name'], $b['name']);
                                    });
                                    $response = ['success' => true, 'items' => $items, 'current_path' => $subPath];
                                }
                            }
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

// Fetch all servers with task count
$servers = $db->query(
    "SELECT s.*, COUNT(t.id) AS task_count
     FROM servers s
     LEFT JOIN backup_tasks t ON s.id = t.server_id
     GROUP BY s.id
     ORDER BY s.created_at DESC"
)->fetchAll();

adminHeader('服务器管理', 'servers');
?>

<div class="page-content">
    <div class="page-header">
        <h2>服务器管理</h2>
        <button class="btn btn-primary" onclick="openAddModal()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            添加服务器
        </button>
    </div>

    <!-- Server List -->
    <?php if (empty($servers)): ?>
    <div class="card">
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
                <circle cx="6" cy="6" r="1" fill="currentColor"/><circle cx="6" cy="18" r="1" fill="currentColor"/>
            </svg>
            <h3>暂无服务器</h3>
            <p>添加您的 Minecraft 服务器以开始创建备份任务。</p>
            <button class="btn btn-primary" onclick="openAddModal()">添加第一台服务器</button>
        </div>
    </div>
    <?php else: ?>
    <div class="card" style="padding:0;">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>服务器名称</th>
                        <th>目录路径</th>
                        <th>备份任务</th>
                        <th>添加时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($servers as $server): ?>
                    <tr>
                        <td data-label="服务器名称"><?php echo htmlspecialchars($server['name']); ?></td>
                        <td data-label="目录路径"><span class="text-mono"><?php echo htmlspecialchars($server['directory']); ?></span></td>
                        <td data-label="备份任务"><?php echo (int)$server['task_count']; ?></td>
                        <td data-label="添加时间"><?php echo htmlspecialchars($server['created_at']); ?></td>
                        <td data-label="操作" class="td-actions">
                            <button class="btn btn-secondary btn-sm" onclick="openEditModal(<?php echo $server['id']; ?>, '<?php echo htmlspecialchars(addslashes($server['name'])); ?>', '<?php echo htmlspecialchars(addslashes($server['directory'])); ?>')">编辑</button>
                            <a href="backup.php?server_id=<?php echo $server['id']; ?>" class="btn btn-ghost btn-sm">查看备份</a>
                            <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $server['id']; ?>, '<?php echo htmlspecialchars(addslashes($server['name'])); ?>')">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Server Modal -->
<div class="modal-overlay hidden" id="server-modal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modal-title">添加服务器</h2>
            <button class="btn btn-ghost btn-icon" onclick="closeModal('server-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Tabs -->
            <div class="modal-tabs" id="modal-tabs">
                <button class="modal-tab active" onclick="switchTab('detect')">自动检测</button>
                <button class="modal-tab" onclick="switchTab('manual')">手动输入</button>
            </div>

            <!-- Tab: Auto Detect -->
            <div class="tab-panel active" id="tab-detect">
                <div class="form-group">
                    <label class="form-label" for="daemon_path">MCSManager Daemon 安装地址</label>
                    <input type="text" id="daemon_path" class="form-input" value="/opt/mcsmanager/daemon" placeholder="/opt/mcsmanager/daemon">
                    <div class="form-hint">系统将扫描该路径下 data/InstanceConfig/ 中的JSON配置文件。</div>
                </div>
                <button class="btn btn-secondary w-full" id="btn-detect" onclick="detectServers()">开始扫描</button>
                <div class="mt-16 hidden" id="detect-result">
                    <div class="detected-list" id="detected-list"></div>
                    <button class="btn btn-primary w-full mt-16" onclick="batchAddServers()">批量添加选中服务器</button>
                </div>
            </div>

            <!-- Tab: Manual -->
            <div class="tab-panel" id="tab-manual">
                <form id="server-form" onsubmit="return submitServer(event)">
                    <input type="hidden" name="action" id="form-action" value="add">
                    <input type="hidden" name="server_id" id="form-server-id" value="0">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                    <div class="form-group">
                        <label class="form-label" for="server-name">服务器名称</label>
                        <input type="text" id="server-name" name="name" class="form-input" placeholder="我的世界服务器" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="server-dir">服务器目录（绝对路径）</label>
                        <input type="text" id="server-dir" name="directory" class="form-input" placeholder="/opt/minecraft/server1" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-full" id="btn-submit">添加服务器</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay hidden" id="delete-modal">
    <div class="modal">
        <div class="modal-body">
            <div class="confirm-content">
                <div class="confirm-icon danger">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h3>确认删除</h3>
                <p id="delete-server-name">确定要删除此服务器吗？该操作将同时删除所有关联的备份任务和记录，且不可撤销。</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('delete-modal')">取消</button>
            <button class="btn btn-danger" id="btn-confirm-delete">确认删除</button>
        </div>
    </div>
</div>

<input type="hidden" id="global-csrf" value="<?php echo $csrfToken; ?>">

<script>
var isEditMode = false;

function openAddModal() {
    isEditMode = false;
    document.getElementById('modal-title').textContent = '添加服务器';
    document.getElementById('form-action').value = 'add';
    document.getElementById('form-server-id').value = '0';
    document.getElementById('server-name').value = '';
    document.getElementById('server-dir').value = '';
    document.getElementById('btn-submit').textContent = '添加服务器';
    document.getElementById('modal-tabs').style.display = '';
    document.getElementById('tab-manual').classList.remove('active');
    document.getElementById('tab-detect').classList.add('active');
    document.getElementById('detect-result').classList.add('hidden');
    switchTab('detect');
    document.getElementById('server-modal').classList.remove('hidden');
}

function openEditModal(id, name, directory) {
    isEditMode = true;
    document.getElementById('modal-title').textContent = '编辑服务器';
    document.getElementById('form-action').value = 'edit';
    document.getElementById('form-server-id').value = id;
    document.getElementById('server-name').value = name;
    document.getElementById('server-dir').value = directory;
    document.getElementById('btn-submit').textContent = '保存修改';
    document.getElementById('modal-tabs').style.display = 'none';
    switchTab('manual');
    document.getElementById('server-modal').classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById('tab-' + tab).classList.add('active');
    document.querySelectorAll('.modal-tab').forEach(function(t) { t.classList.remove('active'); });
    if (tab === 'detect') {
        document.querySelectorAll('.modal-tab')[0].classList.add('active');
    } else {
        document.querySelectorAll('.modal-tab')[1] ? document.querySelectorAll('.modal-tab')[1].classList.add('active') : null;
    }
}

function detectServers() {
    var path = document.getElementById('daemon_path').value;
    var btn = document.getElementById('btn-detect');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> 扫描中...';
    var container = document.getElementById('detect-result');

    fetch('servers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=detect&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&daemon_path=' + encodeURIComponent(path)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.textContent = '开始扫描';
        container.classList.remove('hidden');
        var list = document.getElementById('detected-list');
        if (!res.success || res.servers.length === 0) {
            list.innerHTML = '<div class="empty-state" style="padding:24px;"><p>未检测到任何服务器实例。</p></div>';
        } else {
            list.innerHTML = res.servers.map(function(s, i) {
                return '<div class="detected-item">' +
                    '<input type="checkbox" id="detect-' + i + '" value="' + i + '" checked>' +
                    '<label for="detect-' + i + '"><strong>' + escapeHtml(s.name) + '</strong></label>' +
                    '<span class="detected-path">' + (s.directory ? escapeHtml(s.directory) : '(未知路径)') + '</span>' +
                    '</div>';
            }).join('');
            window._detectedServers = res.servers;
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = '开始扫描';
        showToast('error', '扫描失败', '无法连接到指定路径，请检查 Daemon 安装地址是否正确。');
    });
}

function batchAddServers() {
    if (!window._detectedServers) return;
    var selected = [];
    document.querySelectorAll('#detected-list input[type=checkbox]:checked').forEach(function(cb) {
        var s = window._detectedServers[parseInt(cb.value)];
        if (s) selected.push(s);
    });
    if (selected.length === 0) {
        showToast('warning', '未选中服务器', '请至少勾选一个服务器实例。');
        return;
    }
    var btn = document.querySelector('#detect-result .btn-primary');
    var originalText = btn.textContent;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner spinner-white"></span> 添加中...';

    fetch('servers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=batch_add&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&selected=' + encodeURIComponent(JSON.stringify(selected))
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (res.success) {
            showToast('success', '添加成功', '已批量添加 ' + selected.length + ' 台服务器。');
            setTimeout(function() { location.reload(); }, 500);
        } else {
            showToast('error', '添加失败', res.message);
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = originalText;
        showToast('error', '请求失败', '网络错误，请重试。' + (err.message || ''));
    });
}

function submitServer(e) {
    e.preventDefault();
    var formData = new FormData(document.getElementById('server-form'));
    var btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.classList.add('btn-loading');

    fetch('servers.php', {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.classList.remove('btn-loading');
        if (res.success) {
            showToast('success', isEditMode ? '修改成功' : '添加成功', isEditMode ? '服务器信息已更新。' : '服务器已成功添加。');
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

function confirmDelete(id, name) {
    document.getElementById('delete-server-name').textContent = '确定要删除服务器「' + name + '」吗？该操作将同时删除所有关联的备份任务和记录，且不可撤销。';
    document.getElementById('btn-confirm-delete').onclick = function() {
        deleteServer(id);
    };
    document.getElementById('delete-modal').classList.remove('hidden');
}

function deleteServer(id) {
    var btn = document.getElementById('btn-confirm-delete');
    btn.disabled = true;
    btn.classList.add('btn-loading');
    fetch('servers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&csrf_token=' + encodeURIComponent(document.getElementById('global-csrf').value) + '&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('success', '删除成功', '服务器及其关联数据已删除。');
            closeModal('delete-modal');
            setTimeout(function() { location.reload(); }, 500);
        } else {
            showToast('error', '删除失败', res.message);
            btn.disabled = false;
            btn.classList.remove('btn-loading');
        }
    });
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (!e.target.closest('.modal')) overlay.classList.add('hidden');
    });
});
</script>

<?php adminFooter(); ?>
