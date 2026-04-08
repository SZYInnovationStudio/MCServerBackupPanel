-- database.sql
CREATE DATABASE IF NOT EXISTS mcsbp DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mcsbp;

-- 网站设置表
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_name VARCHAR(255) NOT NULL DEFAULT 'MCServerBackupPanel',
    site_logo VARCHAR(500) NOT NULL DEFAULT '/assets/default-logo.png',
    footer_icp VARCHAR(255) DEFAULT '',
    footer_ps VARCHAR(255) DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 管理员表
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 服务器表
CREATE TABLE IF NOT EXISTS servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 备份任务表
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    source_path VARCHAR(500) NOT NULL,
    backup_path VARCHAR(500) NOT NULL,
    backup_name VARCHAR(255) NOT NULL,
    schedule_time TIME DEFAULT NULL,
    enabled TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- 备份记录表
CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    task_id INT,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    size BIGINT DEFAULT 0,
    is_public TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
);

-- 默认插入一条设置记录
INSERT INTO settings (id, site_name, site_logo) VALUES (1, 'MCServerBackupPanel', '/assets/default-logo.png') ON DUPLICATE KEY UPDATE id=id;