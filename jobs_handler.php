<?php
/**
 * MCServerBackupPanel — Jobs API Handler
 *
 * AJAX endpoints for job_status.php:
 *   GET  ?action=(none) → JSON list of latest jobs
 *   GET  ?action=log&id=N → JSON single job with full log
 */

require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

try {
$db = getDB();

$action = $_GET['action'] ?? '';

if ($action === 'cancel') {
    // Require POST for destructive actions (CSRF protection)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!verifyCSRF()) {
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid job ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 将运行中的任务标记为“请求取消”，由 createBackupZip 在压缩循环中检测并中止。
    $stmt = $db->prepare("UPDATE backup_jobs SET status = 'cancel_requested', message = '用户已请求取消' WHERE id = ? AND status IN ('running', 'pending')");
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => '已请求取消任务，正在停止压缩...'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => '任务不存在或已结束'], JSON_UNESCAPED_UNICODE);
    }
    exit;

} elseif ($action === 'clear') {
    // Require POST for destructive actions (CSRF protection)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!verifyCSRF()) {
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->exec("DELETE FROM backup_jobs WHERE status NOT IN ('running', 'pending')");
    compactJobIds($db);
    echo json_encode(['success' => true, 'message' => 'All non-running jobs cleared'], JSON_UNESCAPED_UNICODE);
    exit;

} elseif ($action === 'log') {
    // ── Single job detail ───────────────────────────────────
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid job ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $db->prepare("SELECT id, status, message, log_text, created_at FROM backup_jobs WHERE id = ?");
    $stmt->execute([$id]);
    $job = $stmt->fetch();

    if (!$job) {
        echo json_encode(['success' => false, 'message' => 'Job not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'id'      => (int)$job['id'],
        'status'  => $job['status'],
        'message' => $job['message'],
        'log'     => $job['log_text'] ?? '',
        'created_at' => $job['created_at'],
    ], JSON_UNESCAPED_UNICODE);

} else {
    // ── Job list ───────────────────────────────────────────
    $jobs = $db->query(
        "SELECT j.id, j.filename, j.status, j.message, j.created_at, s.name AS server_name, t.backup_folder AS task_info
         FROM backup_jobs j
         LEFT JOIN servers s ON j.server_id = s.id
         LEFT JOIN backup_tasks t ON j.task_id = t.id
         ORDER BY j.created_at DESC
         LIMIT 100"
    )->fetchAll();

    echo json_encode([
        'success' => true,
        'jobs'    => $jobs,
    ], JSON_UNESCAPED_UNICODE);
}
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
