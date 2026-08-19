-- ============================================================
-- MCServerBackupPanel (MCSBP) Database Structure
-- Team: SZY创新工作室
-- Version: 1.0.0
-- Engine: InnoDB | Charset: utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Table: config  — 网站配置
-- ------------------------------------------------------------
CREATE TABLE `config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_name` varchar(255) NOT NULL DEFAULT 'MCServerBackupPanel',
  `site_logo` varchar(500) DEFAULT NULL,
  `icp_number` varchar(100) DEFAULT NULL,
  `police_number` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: admin  — 管理员账户
-- ------------------------------------------------------------
CREATE TABLE `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: servers  — Minecraft 服务器
-- ------------------------------------------------------------
CREATE TABLE `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `directory` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: backup_tasks  — 备份任务
-- ------------------------------------------------------------
CREATE TABLE `backup_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `backup_folder` varchar(500) NOT NULL,
  `backup_time` varchar(5) NOT NULL COMMENT 'HH:MM format',
  `backup_destination` varchar(500) NOT NULL,
  `backup_filename` varchar(255) NOT NULL,
  `backup_items` text DEFAULT NULL COMMENT 'JSON array of relative paths to backup, null=all files',
  `auto_delete` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=auto-delete old backups, keep only latest public',
  `encrypted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=auto-set download_password on new backups',
  `default_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'new backups default visibility: 1=public, 0=private',
  `encrypt_password` varchar(255) DEFAULT NULL COMMENT 'Download password (encrypted with APP_SECRET)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: backup_records  — 备份记录
-- ------------------------------------------------------------
CREATE TABLE `backup_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0 COMMENT 'bytes',
  `file_path` varchar(500) NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'default private: 1=public, 0=private',
  `download_password` varchar(255) DEFAULT NULL COMMENT 'hashed password for public download, NULL=no password',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_server_id` (`server_id`),
  KEY `idx_task_id` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backup job status tracking
CREATE TABLE IF NOT EXISTS `backup_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `filename` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `message` varchar(500) DEFAULT '',
  `log_text` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
