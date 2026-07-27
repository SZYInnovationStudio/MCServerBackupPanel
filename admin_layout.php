<?php
/**
 * MCServerBackupPanel — Admin Layout Shell
 *
 * Provides adminHeader() and adminFooter() for all admin pages.
 * Include after auth_check.php.
 *
 * @package MCSBP
 * @version 1.0.0
 */

/**
 * Render the admin shell header (sidebar + topbar + opening page-content).
 *
 * @param string $title      Page title for <title> and topbar h1.
 * @param string $activeNav  Nav key: console|setting|servers|tasks|backup
 */
function adminHeader(string $title, string $activeNav = 'console'): void
{
    $siteName = getConfig('site_name') ?: 'MCServerBackupPanel';
    $siteLogo = getConfig('site_logo') ?: '';
    $adminUser = htmlspecialchars($_SESSION['admin_username'] ?? 'Admin');
    $navItems = [
        'console' => ['label' => '仪表盘',   'url' => 'console.php', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>'],
        'setting' => ['label' => '网站设置', 'url' => 'setting.php',  'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>'],
        'servers' => ['label' => '服务器',   'url' => 'servers.php',  'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><circle cx="6" cy="6" r="1" fill="currentColor"/><circle cx="6" cy="18" r="1" fill="currentColor"/></svg>'],
        'tasks'   => ['label' => '备份任务', 'url' => 'tasks.php',    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
        'backup'  => ['label' => '备份管理', 'url' => 'backup.php',   'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>'],
        'job_status' => ['label' => '任务状态', 'url' => 'job_status.php', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
    ];
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> — <?php echo htmlspecialchars($siteName); ?></title>
    <?php if ($siteLogo): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($siteLogo); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="common.css">
</head>
<body>
<div class="admin-layout">
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <?php if ($siteLogo): ?>
                <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="Logo">
            <?php else: ?>
                <div style="width:32px;height:32px;border-radius:4px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;">M</div>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($siteName); ?></span>
            <button class="sidebar-close" onclick="closeSidebar()" aria-label="关闭菜单">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($navItems as $key => $item): ?>
            <a href="<?php echo $item['url']; ?>" class="<?php echo $activeNav === $key ? 'active' : ''; ?>">
                <?php echo $item['icon']; ?>
                <?php echo $item['label']; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">SZY创新工作室</div>
    </aside>

    <!-- Main Area -->
    <div class="main-area">
        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" onclick="toggleSidebar()" aria-label="菜单">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                    </svg>
                </button>
                <h1><?php echo htmlspecialchars($title); ?></h1>
            </div>
            <div class="topbar-right">
                <span class="user-info"><?php echo $adminUser; ?></span>
                <a href="logout.php" class="btn btn-ghost btn-sm">退出</a>
            </div>
        </header>
    <?php
}

/**
 * Render the admin shell footer (page-content, admin footer, toast container, JS).
 */
function adminFooter(): void
{
    $icp    = getConfig('icp_number') ?: '';
    $police = getConfig('police_number') ?: '';
    ?>
        <!-- Admin Footer -->
        <footer class="admin-footer">
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
    </div><!-- .main-area -->
</div><!-- .admin-layout -->

<!-- Toast Container -->
<div class="toast-container" id="toast-container"></div>

<script>
// Sidebar toggle
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-overlay').classList.remove('show');
}

// Toast notifications
function showToast(type, title, message) {
    var container = document.getElementById('toast-container');
    var icons = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML = '<div class="toast-icon">' + (icons[type] || icons.info) + '</div>' +
        '<div class="toast-body"><div class="toast-title">' + title + '</div>' +
        (message ? '<div class="toast-msg">' + message + '</div>' : '') + '</div>' +
        '<button class="toast-close" onclick="this.parentElement.remove()">&times;</button>';
    container.appendChild(toast);
    setTimeout(function() {
        if (toast.parentElement) {
            toast.classList.add('removing');
            setTimeout(function() { if (toast.parentElement) toast.remove(); }, 300);
        }
    }, 4000);
}
</script>
</body>
</html>
    <?php
}
