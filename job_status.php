<?php
/**
 * MCServerBackupPanel — 任务状态
 *
 * View backup job status, logs, and progress.
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/admin_layout.php';

$db = getDB();

$jobs = $db->query(
    "SELECT j.*, s.name AS server_name, t.backup_folder AS task_info
     FROM backup_jobs j
     LEFT JOIN servers s ON j.server_id = s.id
     LEFT JOIN backup_tasks t ON j.task_id = t.id
     ORDER BY j.created_at DESC
     LIMIT 100"
)->fetchAll();

adminHeader('任务状态', 'job_status');
?>

<div class="page-content">

<div class="card">
    <div class="card-header">
        <h2 class="card-title">备份任务状态</h2>
        <div class="card-actions">
            <span id="auto-refresh-indicator" style="display:none;font-size:13px;color:var(--muted);white-space:nowrap;">
                <span class="pulse-dot"></span> 自动刷新中
            </span>
            <button class="btn btn-secondary btn-sm" onclick="loadJobs()">刷新</button>
            <button class="btn btn-sm btn-danger" onclick="clearJobs()">清空已完成</button>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($jobs)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            <p>暂无备份任务记录</p>
            <p>执行备份后这里会显示任务进度和日志</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table" id="jobs-table">
            <thead>
                <tr>
                    <th style="width:60px;">#ID</th>
                    <th>文件名</th>
                    <th style="width:120px;">服务器</th>
                    <th style="width:140px;" class="col-hide-mobile">源目录</th>
                    <th style="width:80px;">状态</th>
                    <th style="width:160px;">创建时间</th>
                    <th style="width:100px;" class="col-hide-mobile">消息</th>
                    <th style="width:60px;">操作</th>
                </tr>
            </thead>
            <tbody id="jobs-tbody">
                <?php foreach ($jobs as $job): ?>
                <tr class="job-row" onclick="showJobLog(<?php echo (int)$job['id']; ?>)">
                    <td data-label="编号">#<?php echo (int)$job['id']; ?></td>
                    <td data-label="文件名" class="text-mono" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($job['filename']); ?></td>
                    <td data-label="服务器"><?php echo htmlspecialchars($job['server_name'] ?? '-'); ?></td>
                    <td data-label="源目录" class="col-hide-mobile" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--muted);"><?php echo htmlspecialchars($job['task_info'] ?? '-'); ?></td>
                    <td data-label="状态"><?php renderStatusBadge($job['status']); ?></td>
                    <td data-label="创建时间"><?php echo htmlspecialchars($job['created_at']); ?></td>
                    <td data-label="消息" class="col-hide-mobile" style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--muted);"><?php echo htmlspecialchars($job['message'] ?? ''); ?></td>
                    <td data-label="操作"><?php if (in_array($job['status'], ['running','pending'])): ?><button class="btn btn-danger btn-sm" onclick="event.stopPropagation();cancelJob(<?php echo (int)$job['id']; ?>)" style="font-size:11px;padding:2px 8px;">取消</button><?php else: ?><span style="color:var(--muted);font-size:11px;">—</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Log detail modal -->
<div class="modal-overlay hidden" id="log-modal" style="display:none;">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3 id="log-modal-title">任务日志</h3>
            <button class="btn btn-ghost btn-sm" onclick="closeLogModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>状态</label>
                <div id="log-modal-status"></div>
            </div>
            <div class="form-group">
                <label>日志详情</label>
                <pre id="log-modal-content" style="background:var(--bg);padding:16px;border-radius:8px;font-size:13px;font-family:var(--font-mono);line-height:1.6;max-height:400px;overflow:auto;white-space:pre-wrap;word-break:break-all;"></pre>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeLogModal()">关闭</button>
        </div>
    </div>
</div>

</div><!-- .page-content -->

<?php adminFooter(); ?>

<?php
function renderStatusBadge(string $status): void
{
    $map = [
        'running'   => ['运行中', 'status-running', true],
        'success'   => ['成功',   'status-success', false],
        'failed'    => ['失败',   'status-failed',  false],
        'pending'   => ['等待中', 'status-pending', false],
    ];
    [$label, $cls, $pulse] = $map[$status] ?? [$status, '', false];
    $dotHtml = $pulse ? '<span class="pulse-dot"></span>' : '';
    echo '<span class="status-badge ' . $cls . '">' . $dotHtml . htmlspecialchars($label) . '</span>';
}
?>

<input type="hidden" id="global-csrf" value="<?php echo generateCSRF(); ?>">
<input type="hidden" id="global-csrf-jobs" value="<?php echo generateCSRF(); ?>">

<script>
(function(){
    var autoTimer = null;

    function checkRunning() {
        var running = document.querySelectorAll('.status-running,.status-pending');
        var indicator = document.getElementById('auto-refresh-indicator');
        if (running.length > 0) {
            indicator.style.display = 'inline';
            if (!autoTimer) autoTimer = setInterval(loadJobs, 5000);
        } else {
            indicator.style.display = 'none';
            if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
        }
    }

    function loadJobs() {
        fetch('jobs_handler.php')
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) return;
                var tbody = document.getElementById('jobs-tbody');
                if (!data.jobs || !data.jobs.length) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:48px;color:var(--muted);">暂无数据</td></tr>';
                } else {
                    tbody.innerHTML = data.jobs.map(renderJobHTML).join('');
                }
                checkRunning();
            })
            .catch(function(e){ console.error(e); });
    }

    function renderJobHTML(j) {
        var map = {running:['运行中','status-running'],success:['成功','status-success'],failed:['失败','status-failed'],pending:['等待中','status-pending']};
        var mb = map[j.status] || [j.status,''];
        var dot = j.status === 'running' ? '<span class="pulse-dot"></span>' : '';
        var canCancel = j.status === 'running' || j.status === 'pending';
        return '<tr class="job-row" onclick="showJobLog(' + j.id + ')">' +
            '<td data-label="编号">#' + j.id + '</td>' +
            '<td data-label="文件名" class="text-mono" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escHtml(j.filename) + '</td>' +
            '<td data-label="服务器">' + escHtml(j.server_name || '-') + '</td>' +
            '<td data-label="源目录" class="col-hide-mobile" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--muted);">' + escHtml(j.task_info || '-') + '</td>' +
            '<td data-label="状态"><span class="status-badge ' + mb[1] + '">' + dot + mb[0] + '</span></td>' +
            '<td data-label="创建时间">' + (j.created_at || '') + '</td>' +
            '<td data-label="消息" class="col-hide-mobile" style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--muted);">' + escHtml(j.message || '') + '</td>' +
            '<td data-label="操作">' + (canCancel ? '<button class="btn btn-danger btn-sm" onclick="event.stopPropagation();cancelJob(' + j.id + ')" style="font-size:11px;padding:4px 10px;">取消</button>' : '<span style="color:var(--muted);font-size:11px;">—</span>') + '</td>' +
            '</tr>';
    }

    window.showJobLog = function(id) {
        var modal = document.getElementById('log-modal');
        modal.classList.remove('hidden');
        modal.style.display = '';
        document.getElementById('log-modal-title').textContent = '任务日志 #' + id;
        document.getElementById('log-modal-content').textContent = '加载中...';
        document.getElementById('log-modal-status').innerHTML = '';

        fetch('jobs_handler.php?action=log&id=' + id)
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.success) { document.getElementById('log-modal-content').textContent = '加载失败'; return; }
                var map = {running:['运行中','status-running'],success:['成功','status-success'],failed:['失败','status-failed'],pending:['等待中','status-pending']};
                var mb = map[data.status] || [data.status,''];
                var dot = data.status === 'running' ? '<span class="pulse-dot"></span>' : '';
                document.getElementById('log-modal-status').innerHTML = '<span class="status-badge ' + mb[1] + '">' + dot + mb[0] + '</span>' +
                    (data.message ? ' <span style="font-size:13px;color:var(--muted);margin-left:8px;">' + escHtml(data.message) + '</span>' : '');
                document.getElementById('log-modal-content').textContent = data.log || '(无日志)';
            })
            .catch(function(e){ document.getElementById('log-modal-content').textContent = '加载失败: ' + e.message; });
    };

    window.closeLogModal = function() {
        var modal = document.getElementById('log-modal');
        modal.classList.add('hidden');
        modal.style.display = 'none';
    };

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    window.cancelJob = function(id) {
        if (!confirm('确定要取消任务 #' + id + ' 吗？')) return;
        var csrf = document.getElementById('global-csrf-jobs').value;
        fetch('jobs_handler.php?action=cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(csrf) + '&id=' + id
        })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.success) {
                    loadJobs();
                } else {
                    alert(data.message || '操作失败');
                }
            })
            .catch(function(e){ alert('请求失败: ' + e.message); });
    };

    window.clearJobs = function() {
        if (!confirm('确定清空所有已完成/失败的任务记录？运行中的不会被删除。')) return;
        var csrf = document.getElementById('global-csrf-jobs').value;
        fetch('jobs_handler.php?action=clear', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(csrf)
        })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.success) {
                    loadJobs();
                } else {
                    alert(data.message || '操作失败');
                }
            })
            .catch(function(e){ alert('请求失败: ' + e.message); });
    };

    window.loadJobs = loadJobs;

    checkRunning();
})();
</script>
