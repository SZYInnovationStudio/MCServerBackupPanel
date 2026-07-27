<?php
/**
 * MCServerBackupPanel — Cron Job Runner
 *
 * Intended to be called every minute by system cron.
 * Checks for backup tasks scheduled at the current time and executes them.
 *
 * Usage (crontab):
 *   * * * * * php /path/to/MCServerBackupPanel/cron.php
 *
 * @package MCSBP
 * @version 1.0.0
 */

// Only allow CLI execution for security
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

@ini_set('memory_limit', '-1');

require_once __DIR__ . '/config.php';

$db = getDB();
$now = date('H:i');

// Find tasks scheduled for this minute
$stmt = $db->prepare(
    "SELECT t.*, s.name AS server_name, s.directory AS server_dir
     FROM backup_tasks t
     JOIN servers s ON t.server_id = s.id
     WHERE t.backup_time = ?"
);
$stmt->execute([$now]);
$tasks = $stmt->fetchAll();

if (empty($tasks)) {
    exit(0);
}

foreach ($tasks as $task) {
    echo "[" . date('Y-m-d H:i:s') . "] Executing backup task #{$task['id']} for server: {$task['server_name']}\n";

    $sourceDir = $task['backup_folder'];
    $isAbsolute = null;
    if (($task['backup_path_type'] ?? '') === 'absolute') {
        $isAbsolute = true;
    }
    $destDir   = normalizeBackupPath($task['backup_destination'], $isAbsolute);

    if (!is_dir($sourceDir)) {
        echo "  ERROR: Source directory not found: {$sourceDir}\n";
        continue;
    }

    if (!ensureDir($destDir)) {
        echo "  ERROR: Cannot create destination directory: {$destDir}\n";
        continue;
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

    // Parse selected backup items from task config (null or empty = entire source dir)
    $selectedItems = [];
    if (!empty($task['backup_items'])) {
        $selectedItems = json_decode($task['backup_items'], true) ?: [];
    }

    [$zipOk, $zipResult] = createBackupZip($sourceDir, $filePath, $selectedItems);

    if (!$zipOk) {
        @unlink($filePath);
        echo "  ERROR: {$zipResult}\n";
        continue;
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
        echo "  ERROR: ZIP file is 0 bytes.\n";
        continue;
    }

    // Insert record
        $dlPassword = null;
        if (!empty($task['encrypted']) && !empty($task['encrypt_password'])) {
            $encPassword = decryptValue($task['encrypt_password']);
            $dlPassword = password_hash($encPassword, PASSWORD_BCRYPT);
        }

        $insertStmt = $db->prepare(
            "INSERT INTO backup_records (server_id, task_id, filename, file_size, file_path, is_public, download_password, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, NOW())"
        );
        $insertStmt->execute([$task['server_id'], $task['id'], $filename, (int)$fileSize, $filePath, $dlPassword]);

        // Auto-delete old backups
        if (!empty($task['auto_delete'])) {
            $recordId = $db->lastInsertId();
            $stmtDel = $db->prepare(
                "SELECT id, file_path FROM backup_records WHERE task_id = ? AND id != ? ORDER BY created_at DESC"
            );
            $stmtDel->execute([$task['id'], $recordId]);
            $oldRecords = $stmtDel->fetchAll();
            foreach ($oldRecords as $old) {
                @unlink($old['file_path']);
                $db->prepare("DELETE FROM backup_records WHERE id = ?")->execute([$old['id']]);
            }
        }

        echo "  SUCCESS: {$filename} (" . formatSize($fileSize) . ")\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Cron job completed. " . count($tasks) . " tasks processed.\n";
