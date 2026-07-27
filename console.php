<?php
/**
 * MCServerBackupPanel — Dashboard
 *
 * Admin dashboard with statistics, recent backups, and schedule reminders.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/admin_layout.php';

$db = getDB();

// Fetch statistics
$serverCount = (int)$db->query("SELECT COUNT(*) FROM servers")->fetchColumn();
$taskCount   = (int)$db->query("SELECT COUNT(*) FROM backup_tasks")->fetchColumn();
$backupCount = (int)$db->query("SELECT COUNT(*) FROM backup_records")->fetchColumn();
$publicCount = (int)$db->query("SELECT COUNT(*) FROM backup_records WHERE is_public = 1")->fetchColumn();

// Today's pending tasks (tasks whose backup_time has not yet passed today)
$now = date('H:i');
$todayTasks = $db->prepare(
    "SELECT t.*, s.name AS server_name FROM backup_tasks t
     JOIN servers s ON t.server_id = s.id
     WHERE t.backup_time >= ? ORDER BY t.backup_time ASC"
);
$todayTasks->execute([$now]);
$pendingTasks = $todayTasks->fetchAll();

// Recent backup records (last 10)
$recentBackups = $db->query(
    "SELECT br.*, s.name AS server_name FROM backup_records br
     JOIN servers s ON br.server_id = s.id
     ORDER BY br.created_at DESC LIMIT 10"
)->fetchAll();

// ====================== PAGE CONTENT ======================
adminHeader('仪表盘', 'console');
?>

<div class="page-content">
    <!-- Stat Cards -->
    <div class="stat-grid mb-24">
        <div class="card stat-card">
            <div class="stat-icon blue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>
            </div>
            <div>
                <div class="stat-value"><?php echo $serverCount; ?></div>
                <div class="stat-label">服务器总数</div>
            </div>
        </div>
        <div class="card stat-card">
            <div class="stat-icon green">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="stat-value"><?php echo $taskCount; ?></div>
                <div class="stat-label">备份任务数</div>
            </div>
        </div>
        <div class="card stat-card">
            <div class="stat-icon slate">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <div class="stat-value"><?php echo $backupCount; ?></div>
                <div class="stat-label">已完成备份</div>
            </div>
        </div>
        <div class="card stat-card">
            <div class="stat-icon amber">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            </div>
            <div>
                <div class="stat-value"><?php echo $publicCount; ?></div>
                <div class="stat-label">公开备份数</div>
            </div>
        </div>
    </div>

    <!-- Pending Task Reminder -->
    <?php if (!empty($pendingTasks)): ?>
    <div class="reminder-banner">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>今日还有 <strong><?php echo count($pendingTasks); ?></strong> 个备份任务待执行：
            <?php foreach ($pendingTasks as $pt): ?>
                <?php echo htmlspecialchars($pt['server_name']); ?> (<?php echo htmlspecialchars($pt['backup_time']); ?>)
            <?php endforeach; ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Recent Backups -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">最近备份记录</h2>
            <a href="backup.php" class="btn btn-secondary btn-sm">查看全部</a>
        </div>
        <?php if (empty($recentBackups)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/>
                <polyline points="13 2 13 9 20 9"/>
            </svg>
            <h3>暂无备份记录</h3>
            <p>创建服务器和备份任务后，备份记录将在此处显示。</p>
            <a href="tasks.php" class="btn btn-primary btn-sm">创建备份任务</a>
        </div>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>文件名</th>
                        <th>服务器</th>
                        <th>大小</th>
                        <th>时间</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBackups as $record): ?>
                    <tr>
                        <td data-label="文件名"><span class="text-mono"><?php echo htmlspecialchars($record['filename']); ?></span></td>
                        <td data-label="服务器"><?php echo htmlspecialchars($record['server_name']); ?></td>
                        <td data-label="大小"><?php echo formatSize((int)$record['file_size']); ?></td>
                        <td data-label="时间"><?php echo htmlspecialchars($record['created_at']); ?></td>
                        <td data-label="状态">
                            <?php if ($record['is_public']): ?>
                                <span class="badge badge-green">公开</span>
                            <?php else: ?>
                                <span class="badge badge-slate">私有</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>
