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
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid job ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $db->prepare("DELETE FROM backup_jobs WHERE id = ? AND status IN ('running', 'pending')");
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        // Compact IDs: reset AUTO_INCREMENT to the lowest available gap
        compactJobIds($db);
        echo json_encode(['success' => true, 'message' => 'Job deleted'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => 'Job not found or not in running/pending state'], JSON_UNESCAPED_UNICODE);
    }
    exit;

} elseif ($action === 'clear') {
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
