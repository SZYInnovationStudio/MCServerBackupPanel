<?php
/**
 * MCServerBackupPanel — 公开备份下载页
 *
 * 展示可公开下载的备份文件，支持密码保护下载。
 * 无需登录，精美的卡片式布局。
 *
 * @package MCSBP
 * @version 1.1.0
 */

// Auto-redirect to installer if not yet installed
if (!file_exists(__DIR__ . '/install.lock') && file_exists(__DIR__ . '/install.php')) {
    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/install.php');
    exit;
}

require_once __DIR__ . '/config.php';

// ============================================================
// POST: 验证下载密码
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_password') {
    $id = (int)($_POST['id'] ?? 0);
    $password = $_POST['password'] ?? '';

    if ($id <= 0 || $password === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '请输入密码'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, download_password FROM backup_records WHERE id = ? AND is_public = 1");
        $stmt->execute([$id]);
        $record = $stmt->fetch();

        if (!$record) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '备份记录不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 该备份未设置密码 — 理论上不会走到这里（前端已判断）
        if (empty($record['download_password'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '此备份未设置密码保护'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!password_verify($password, $record['download_password'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '密码错误，请重试'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 密码正确，写入 session 授权并立即持久化
        $_SESSION['download_auth_' . $id] = true;
        session_write_close();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'download_url' => SITE_URL . '/index.php?download=' . $id], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '系统错误，请稍后重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================
// GET: 下载代理（检查密码授权）
// ============================================================
if (isset($_GET['download'])) {
    $downloadId = (int)$_GET['download'];
    if ($downloadId > 0) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM backup_records WHERE id = ? AND is_public = 1");
            $stmt->execute([$downloadId]);
            $record = $stmt->fetch();

            if ($record) {
                // 检查是否需要密码且是否已通过验证
                $needsPassword = !empty($record['download_password']);
                $hasAuth = !empty($_SESSION['download_auth_' . $downloadId]);

                if ($needsPassword && !$hasAuth) {
                    // 未授权，重定向回主页
                    $redirect = (SITE_URL ?: '') . '/index.php';
                    header('Location: ' . ($redirect ?: '/index.php'));
                    exit;
                }

                if (file_exists($record['file_path'])) {
                    $filePath = $record['file_path'];
                    $filename = $record['filename'];

                    // Path security: allow files under BACKUP_ROOT or absolute paths outside it
                    $realPath = str_replace('\\', '/', (string) @realpath($filePath));
                    $baseAllowed = str_replace('\\', '/', (string) @realpath(BACKUP_ROOT));

                    $allowDownload = false;
                    if ($realPath && $baseAllowed) {
                        $allowDownload = (strpos($realPath, $baseAllowed) === 0);
                    }
                    if (!$allowDownload && $realPath) {
                        $allowDownload = is_readable($realPath);
                    }

                    if ($allowDownload) {
                        // 下载后清除授权，防止同一 URL 被重复使用
                        if ($hasAuth) {
                            unset($_SESSION['download_auth_' . $downloadId]);
                            session_write_close();
                        }

                        streamDownload($filePath, $filename);
                        // streamDownload exits internally
                    }
                }
            }
        } catch (Exception $e) {
            // Fall through
        }
    }
    // 下载失败 — 跳回主页
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

// ============================================================
// GET: 正常页面渲染
// ============================================================

/**
 * 根据服务器名称生成一个稳定的颜色（用于头像背景）。
 */
function serverAvatarColor(string $name): string
{
    $palette = [
        '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7',
        '#06b6d4', '#0891b2', '#0ea5e9', '#0284c7',
        '#10b981', '#059669', '#14b8a6', '#0d9488',
        '#f59e0b', '#d97706', '#f97316', '#ea580c',
        '#ef4444', '#dc2626', '#ec4899', '#db2777',
    ];
    $hash = abs(crc32($name));
    return $palette[$hash % count($palette)];
}

try {
    $db = getDB();
    $siteName = getConfig('site_name') ?: 'MCServerBackupPanel';
    $siteLogo = getConfig('site_logo') ?: '';

    // 获取公开备份列表
    $stmt = $db->query(
        "SELECT br.*, s.name AS server_name
         FROM backup_records br
         JOIN servers s ON br.server_id = s.id
         WHERE br.is_public = 1
         ORDER BY br.created_at DESC"
    );
    $records = $stmt->fetchAll();
} catch (Exception $e) {
    $siteName = 'MCServerBackupPanel';
    $siteLogo = '';
    $records = [];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>公开备份 — <?php echo htmlspecialchars($siteName); ?></title>
    <?php if ($siteLogo): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($siteLogo); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="common.css">
    <style>
        /* ===== 页面专用样式 ===== */

        /* 卡片内部布局增强 */
        .backup-card {
            gap: 12px;
            position: relative;
            overflow: hidden;
        }
        .backup-card:hover {
            border-color: var(--accent);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08), 0 2px 8px rgba(0,0,0,0.04);
            transform: translateY(-2px);
        }

        /* 卡片顶部：头像 + 服务器信息 */
        .card-top {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .card-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
            flex-shrink: 0;
            letter-spacing: 1px;
            user-select: none;
        }
        .card-server-info {
            flex: 1;
            min-width: 0;
        }
        .card-server-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .card-server-date {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* 文件名 */
        .card-filename {
            font-family: 'Cascadia Code', 'JetBrains Mono', 'Fira Code', ui-monospace, 'SF Mono', 'Consolas', 'Microsoft YaHei', 'PingFang SC', monospace;
            font-size: 13px;
            color: var(--text-secondary);
            background: var(--bg-hover);
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            word-break: break-all;
            line-height: 1.4;
            letter-spacing: -0.01em;
        }

        /* 底部 meta */
        .card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--divider);
        }
        .card-size {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .card-size svg {
            width: 14px;
            height: 14px;
            color: var(--text-muted);
        }
        .card-lock-icon {
            display: inline-flex;
            align-items: center;
            color: var(--warning);
            flex-shrink: 0;
        }
        .card-lock-icon svg {
            width: 14px;
            height: 14px;
        }

        /* 下载按钮 */
        .card-download {
            width: 100%;
            margin-top: 4px;
        }
        .card-download.btn-primary {
            gap: 8px;
        }

        /* ===== 密码弹窗 ===== */
        .pw-modal {
            max-width: 400px;
        }
        .pw-modal .modal-body {
            text-align: center;
            padding: 28px 24px 20px;
        }
        .pw-modal-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #fffbeb;
            color: var(--warning);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .pw-modal-icon svg {
            width: 28px;
            height: 28px;
        }
        .pw-modal-title {
            font-size: 17px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
        }
        .pw-modal-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .pw-modal-footer {
            display: flex;
            gap: 10px;
            padding: 0 24px 20px;
        }
        .pw-modal-footer .btn {
            flex: 1;
        }
        .pw-error {
            display: none;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--danger);
            margin-top: 8px;
            text-align: center;
            justify-content: center;
        }
        .pw-error.show {
            display: flex;
        }

        /* 移动端：密码弹窗居中、不全屏，保持精致感 */
        @media (max-width: 639px) {
            #password-modal.modal-overlay {
                padding: 20px;
                align-items: center;
            }
            #password-modal .modal {
                max-width: 92vw;
                border-radius: 14px;
                margin: 0;
            }
            .pw-modal .modal-body {
                padding: 24px 20px 16px;
            }
            .pw-modal-icon {
                width: 48px;
                height: 48px;
                margin-bottom: 12px;
            }
            .pw-modal-icon svg {
                width: 24px;
                height: 24px;
            }
            .pw-modal-title {
                font-size: 16px;
            }
            .pw-modal-desc {
                font-size: 13px;
                margin-bottom: 16px;
            }
            .pw-modal .form-group {
                margin-bottom: 0;
            }
            .pw-modal #pw-input {
                height: 48px;
                font-size: 16px;
                text-align: center;
            }
            .pw-modal-footer {
                padding: 0 20px 20px;
                gap: 8px;
            }
            .pw-modal-footer .btn {
                height: 44px;
                font-size: 14px;
            }
        }

        /* Header Logo 64px */
        .public-header .brand img {
            width: 64px;
            height: 64px;
            border-radius: 50%;
        }
        .public-header .brand .logo-fallback {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: 700;
        }
        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .brand-text .tagline {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 400;
        }

        /* Responsive: Tablet 2 columns */
        @media (max-width: 1023px) and (min-width: 640px) {
            .backup-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body class="public-page">

    <!-- ========== Header ========== -->
    <header class="public-header">
        <div class="brand">
            <?php if ($siteLogo): ?>
                <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="Logo">
            <?php else: ?>
                <div class="logo-fallback">M</div>
            <?php endif; ?>
            <div class="brand-text">
                <h1><?php echo htmlspecialchars($siteName); ?></h1>
                <span class="tagline">公开备份下载</span>
            </div>
        </div>
    </header>

    <!-- ========== Main ========== -->
    <main class="public-main">
        <?php if (empty($records)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/>
                <polyline points="13 2 13 9 20 9"/>
            </svg>
            <h3>暂无公开备份</h3>
            <p>管理员尚未设置任何公开备份。请稍后再来查看。</p>
        </div>
        <?php else: ?>
        <div class="backup-grid">
            <?php foreach ($records as $record):
                $hasPassword = !empty($record['download_password']);
                $firstLetter = mb_substr($record['server_name'], 0, 1, 'UTF-8');
                $avatarBg = serverAvatarColor($record['server_name']);
            ?>
            <div class="backup-card">
                <!-- 顶部：头像 + 服务器名 + 日期 -->
                <div class="card-top">
                    <div class="card-avatar" style="background:<?php echo htmlspecialchars($avatarBg); ?>;">
                        <?php echo htmlspecialchars($firstLetter); ?>
                    </div>
                    <div class="card-server-info">
                        <div class="card-server-name"><?php echo htmlspecialchars($record['server_name']); ?></div>
                        <div class="card-server-date"><?php echo htmlspecialchars($record['created_at']); ?></div>
                    </div>
                </div>

                <!-- 文件名 -->
                <div class="card-filename"><?php echo htmlspecialchars($record['filename']); ?></div>

                <!-- 底部：文件大小 + 下载按钮 -->
                <div class="card-meta">
                    <span class="card-size">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <?php echo htmlspecialchars(formatSize((int)$record['file_size'])); ?>
                    </span>
                    <?php if ($hasPassword): ?>
                    <span class="card-lock-icon" title="需要密码">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0110 0v4"/>
                        </svg>
                    </span>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary card-download"
                        onclick="handleDownload(<?php echo (int)$record['id']; ?>, <?php echo $hasPassword ? 'true' : 'false'; ?>)" type="button">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    下载备份
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <?php
    $icp    = getConfig('icp_number') ?: '';
    $police = getConfig('police_number') ?: '';
    ?>
    <!-- ========== Footer ========== -->
    <footer class="public-footer">
        <div>Powered by MCSBP</div>
        <div>开源地址：<a href="https://github.com/szyinnovationstudio/MCServerBackupPanel" target="_blank" rel="noopener">github.com/szyinnovationstudio/MCServerBackupPanel</a></div>
        <div>&copy; SZY创新工作室 版权所有</div>
        <?php if ($icp): ?>
        <div><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener"><?php echo htmlspecialchars($icp); ?></a></div>
        <?php endif; ?>
        <?php if ($police): ?>
        <div><?php echo htmlspecialchars($police); ?></div>
        <?php endif; ?>
    </footer>

    <!-- ========== 密码弹窗（自包含） ========== -->
    <div class="modal-overlay hidden" id="password-modal">
        <div class="modal pw-modal">
            <div class="modal-body">
                <div class="pw-modal-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0110 0v4"/>
                        <circle cx="12" cy="16" r="1"/>
                    </svg>
                </div>
                <div class="pw-modal-title">此备份需要密码</div>
                <div class="pw-modal-desc">该备份文件已设置下载密码保护，请输入密码以继续下载。</div>
                <div class="form-group">
                    <input type="password"
                           id="pw-input"
                           class="form-input"
                           placeholder="请输入下载密码"
                           autocomplete="off"
                           style="text-align:center;font-size:16px;">
                </div>
                <div class="pw-error" id="pw-error">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span id="pw-error-msg"></span>
                </div>
            </div>
            <div class="pw-modal-footer">
                <button class="btn btn-secondary" onclick="closePwModal()" type="button">取消</button>
                <button class="btn btn-primary" id="pw-submit-btn" onclick="submitPassword()" type="button">确认下载</button>
            </div>
        </div>
    </div>

    <script>
    var currentDownloadId = 0;

    /**
     * 处理下载按钮点击
     */
    function handleDownload(id, hasPassword) {
        if (!hasPassword) {
            // 无密码，直接下载
            window.location.href = 'index.php?download=' + id;
            return;
        }
        // 有密码，弹出弹窗
        currentDownloadId = id;
        document.getElementById('pw-input').value = '';
        document.getElementById('pw-error').classList.remove('show');
        document.getElementById('password-modal').classList.remove('hidden');
        setTimeout(function() {
            document.getElementById('pw-input').focus();
        }, 150);
    }

    /**
     * 关闭密码弹窗
     */
    function closePwModal() {
        document.getElementById('password-modal').classList.add('hidden');
        currentDownloadId = 0;
    }

    /**
     * 提交密码
     */
    function submitPassword() {
        var password = document.getElementById('pw-input').value.trim();
        var btn = document.getElementById('pw-submit-btn');
        var errorDiv = document.getElementById('pw-error');
        var errorMsg = document.getElementById('pw-error-msg');

        if (!password) {
            errorMsg.textContent = '请输入密码';
            errorDiv.classList.add('show');
            return;
        }

        btn.disabled = true;
        btn.classList.add('btn-loading');
        errorDiv.classList.remove('show');

        fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=verify_password&id=' + currentDownloadId + '&password=' + encodeURIComponent(password)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            btn.classList.remove('btn-loading');
            if (res.success) {
                closePwModal();
                if (res.download_url) {
                    window.location.href = res.download_url;
                }
            } else {
                errorMsg.textContent = res.message || '密码错误';
                errorDiv.classList.add('show');
                document.getElementById('pw-input').value = '';
                document.getElementById('pw-input').focus();
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.classList.remove('btn-loading');
            errorMsg.textContent = '网络错误，请重试';
            errorDiv.classList.add('show');
        });
    }

    // 弹窗内回车提交
    document.getElementById('pw-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            submitPassword();
        }
    });

    // 点击遮罩关闭
    document.getElementById('password-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePwModal();
        }
    });
    </script>

</body>
</html>
