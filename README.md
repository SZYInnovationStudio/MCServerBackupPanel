# MCServerBackupPanel (MCSBP)

Minecraft 服务器备份管理面板，由 **SZY创新工作室** 开发。

专业、简洁的服务器备份管理工具，支持多服务器定时备份、一键恢复、公开下载和密码保护。

---

## 功能列表

- **安装向导** — 可视化安装流程，自动创建数据库和配置文件
- **管理后台** — 响应式仪表盘，桌面端和移动端均可流畅使用
- **服务器管理** — 支持手动添加和 MCSManager Daemon 自动检测服务器实例
- **定时备份任务** — 每台服务器独立定时备份，支持选择特定目录/文件
- **立即备份** — 可从备份管理页直接发起一次性备份，支持自定义路径和文件名
- **备份记录管理** — 查看、下载、删除、重命名备份文件，设置公开/私有
- **一键恢复** — 将备份解压回服务器目录（含 ZIP Slip 防护），恢复前自动创建临时备份
- **公开下载页** — 无需登录即可下载公开备份，支持密码保护
- **下载密码保护** — 为备份设置 bcrypt 哈希密码，用户下载时需验证
- **灵活存储路径** — 支持相对路径（存于网站 backups/ 下）或服务器绝对路径
- **自动清理** — 可选自动删除旧备份，公开页始终只显示最新备份
- **任务状态与日志** — 实时查看备份进度和详细日志，支持取消和清空
- **安全机制** — CSRF 防护、bcrypt 密码哈希、AES-256-CBC 加密存储、PDO 预处理、PHP 代理下载、路径穿越防护

---

## 环境要求

### 服务器

| 组件 | 最低版本 | 说明 |
|------|---------|------|
| PHP | **7.3+** | 推荐 PHP 8.0+ |
| MySQL | **5.7+** | 或 MariaDB 10.2+ |
| Web 服务器 | Nginx / Apache | 带 URL 重写支持 |
| 操作系统 | Linux | 推荐 Ubuntu 20.04+ / Debian 11+ / CentOS 7+ |

### 必需的 PHP 扩展

| 扩展 | 用途 | 必装 |
|------|------|:--:|
| `pdo_mysql` | 数据库连接 | **是** |
| `json` | JSON 编解码 | **是** |
| `session` | 用户登录状态 | **是** |
| `zip` (ZipArchive) | ZIP 压缩/解压（Fallback 路径）| **是** |
| `openssl` | 密码加密存储 (AES-256-CBC) | **是** |
| `mbstring` | 多字节字符串处理 | **是** |
| `fileinfo` | MIME 类型检测 | 推荐 |
| `ctype` | 字符类型检测 | 推荐 |

**安装命令（Debian/Ubuntu）：**

```bash
sudo apt install php php-mysql php-zip php-mbstring php-xml php-json
```

**安装命令（CentOS/RHEL）：**

```bash
sudo yum install php php-mysqlnd php-zip php-mbstring php-xml php-json
```

### 必需的 PHP 函数

以下 PHP 函数**必须**在 `disable_functions` 之外可用：

| 函数 | 用途 | 如果被禁用的后果 |
|------|------|----------------|
| `exec` | 调用系统 zip 命令，速度提升 10-50 倍 | 回退到 PHP ZipArchive（慢），大目录可能超时 |
| `proc_open` | 备用进程启动 | 与 exec 互补 |
| `popen` | 备用进程启动 | 与 exec 互补 |
| `symlink` | 符号链接解析（非必须） | 仅影响 realpath 某些场景 |
| `disk_free_space` | 备份前检查磁盘剩余空间 | 跳过磁盘空间检查 |

> **重要提示**：即使 `exec`、`proc_open`、`popen` 全部被禁用，面板仍可工作（回退到纯 PHP ZipArchive），但备份速度会显著下降，大目录（10GB+）可能超时。**强烈建议至少保留 `exec` 函数。**

可以在 `php.ini` 中检查当前禁用函数：

```bash
php -r "echo ini_get('disable_functions');"
```

### 推荐的系统工具

| 工具 | 用途 | 安装命令 |
|------|------|---------|
| `zip` (Info-ZIP) | 高性能 ZIP 创建，原生 C 实现，比 PHP ZipArchive 快 10-50 倍 | `apt install zip` |

验证系统 zip 是否可用：

```bash
which zip && zip --version
```

---

## 快速部署

### 1. 上传文件

将整个项目目录上传至 Web 服务器（如 `/www/wwwroot/mcbackup.example.com/`）。

### 2. 配置 Web 服务器

#### Nginx 配置

```nginx
server {
    listen 80;
    server_name mcbackup.example.com;
    root /www/wwwroot/mcbackup.example.com;
    index index.php;

    # 主入口
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP 处理
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        # 备份大文件时需要较长超时
        fastcgi_read_timeout 3600s;
    }

    # 禁止直接访问 backups 目录（备份文件通过 PHP 代理下载）
    location ~ ^/backups/ {
        deny all;
    }

    # 禁止访问敏感文件
    location ~ ^/(config\.php|database\.sql)$ {
        deny all;
    }
}
```

#### Apache 配置

项目自带 `.htaccess`，确保 `mod_rewrite` 已启用：

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 3. 目录权限

```bash
cd /www/wwwroot/mcbackup.example.com

# backups 目录必须可写（Web 进程写入备份文件）
mkdir -p backups
chown -R www-data:www-data backups
chmod 755 backups

# config.php 将在安装时自动生成，确保父目录可写
chown www-data:www-data .
```

### 4. 运行安装向导

浏览器访问 `http://你的域名/install.php`，按照向导填写：

| 步骤 | 内容 |
|------|------|
| 站点信息 | 网站名称、Logo URL（可选）、ICP 备案号（可选）、公安备案号（可选）|
| 管理员账户 | 用户名和密码（至少 6 位）|
| 数据库连接 | MySQL 主机、端口、数据库名、用户名、密码 |

提交后系统自动完成：
1. 创建数据库和表结构（含所有迁移列）
2. 写入管理员账户（bcrypt 哈希）
3. 生成 `config.php` 配置文件
4. 创建 `backups/` 目录及安全防护文件（`.htaccess` + `index.php`）
5. **自动删除 `install.php`**（防止二次安装）

### 5. 登录后台

安装完成后自动跳转到登录页，使用设置的管理员账户登录。

### 6. 配置定时任务（Cron）

备份任务按设定时间自动执行，需要添加系统 cron：

```bash
crontab -e
```

添加以下行（每分钟检查一次）：

```
* * * * * /usr/bin/php /www/wwwroot/mcbackup.example.com/cron.php >> /var/log/mcsbp_cron.log 2>&1
```

> **注意**：将 `/usr/bin/php` 和项目路径替换为实际值。可通过 `which php` 确认 PHP 路径。
>
> cron.php 仅允许 CLI 执行，Web 直接访问会返回 403。

---

## 使用教程

### 1. 添加服务器

进入「服务器管理」页面，点击「添加服务器」：

- **自动检测**：输入 MCSManager Daemon 安装路径（默认 `/opt/mcsmanager/daemon`），系统自动扫描 `data/InstanceConfig/` 中的 JSON 配置文件，列出所有实例，勾选后批量添加。
- **手动输入**：填写「服务器名称」和「服务器目录」（绝对路径，如 `/szydata/mcsmanager/server/szydmc`）。

### 2. 创建备份任务

在「备份任务」页面选择服务器，点击「添加任务」：

| 字段 | 说明 |
|------|------|
| **备份文件夹** | 要备份的源目录绝对路径 |
| **备份时间** | 每天 HH:MM 自动执行（24 小时制）|
| **备份存放地址** | 相对路径（`backups/` 子目录）或绝对路径（服务器任意可写目录）|
| **文件名模板** | 支持 `{server_name}` `{date}` `{time}` `{datetime}` 变量 |
| **备份内容** | 可选备份特定目录/文件，留空 = 全部 |
| **自动删除旧备份** | 勾选后每次执行自动清理，公开页仅显示最新 |
| **设置下载密码** | 勾选后为该任务所有备份自动设置 bcrypt 下载密码 |

#### 路径模式说明

| 模式 | 示例 | 实际存储位置 |
|------|------|------------|
| 相对路径 | `SZYDMC-JAVA/` | `/www/wwwroot/mcbackup.example.com/backups/SZYDMC-JAVA/` |
| 绝对路径 | `/mnt/disk/backups/` | `/mnt/disk/backups/`（需 Web 进程写权限）|

> **推荐使用相对路径**：文件统一管理、权限简单、无需额外配置。

### 3. 手动备份（立即备份）

在「备份管理」页面顶部的「立即备份」区域：
1. 选择目标服务器
2. 勾选要备份的目录/文件（默认全选）
3. 可选自定义文件名和存放路径
4. 点击「立即备份」— 进度可在「任务状态」页查看

### 4. 管理备份记录

在「备份管理」页面查看所有备份记录：

| 操作 | 说明 |
|------|------|
| **下载** | 无密码直接下载；有密码需输入后下载（PHP 代理，不暴露真实路径）|
| **公开/私有** | 切换是否在首页公开下载页显示 |
| **设密码/改密码/取消密码** | 管理下载密码保护（bcrypt 哈希存储）|
| **重命名** | 修改备份文件名（同步重命名磁盘文件）|
| **恢复** | 解压回服务器目录，恢复前自动创建当前状态备份 |
| **删除** | 永久删除备份文件及数据库记录 |

### 5. 公开下载页

访问网站首页（无需登录）即可看到所有公开备份：
- 带密码的备份显示锁图标，点击后弹出密码输入框
- 密码验证通过后通过 PHP 代理下载，**不暴露实际文件路径**
- 移动端密码弹窗居中显示，与桌面端体验一致

### 6. 监控任务状态

「任务状态」页面提供：
- 所有备份任务的实时状态（等待中 / 运行中 / 成功 / 失败）
- 点击任务行查看详细日志
- 取消运行中的任务 / 清空已完成任务
- 有运行中任务时自动每 5 秒刷新

---

## 目录结构

```
MCServerBackupPanel/
├── .htaccess           # Apache 安全规则（禁止直接访问 config.php、database.sql、backups/）
├── admin_layout.php    # 管理后台通用布局（侧边栏 + 顶栏 + Toast 通知 + 响应式导航）
├── auth_check.php      # 登录状态验证中间件（未登录 → 重定向至 login.php）
├── backup.php          # 备份管理：记录列表、下载代理、恢复、密码、重命名、立即备份
├── common.css          # 全局样式表（CSS 变量、响应式布局、动效、移动端适配）
├── config.php          # 全局配置（安装后生成）：数据库连接、加密密钥、工具函数、自动迁移
├── console.php         # 仪表盘：统计卡片 + 待执行任务提醒 + 最近备份记录
├── cron.php            # 定时任务入口：仅 CLI，每分钟检查并执行到期备份任务
├── database.sql        # 数据库表结构（6 张表）
├── index.php           # 公开备份下载页（卡片式布局、密码弹窗、PHP 代理下载）
├── install.php         # 安装向导（安装成功后自动删除）
├── job_status.php      # 任务状态页：实时列表 + 日志详情弹窗 + 自动刷新
├── jobs_handler.php    # 任务状态 AJAX API：列表、日志、取消、清空
├── login.php           # 管理员登录页（session 再生、CSRF 防护）
├── logout.php          # 退出登录（销毁 session + 清除 cookie）
├── servers.php         # 服务器管理：CRUD + MCSManager Daemon 自动检测 + 目录浏览 API
├── setting.php         # 网站设置：名称、Logo URL、ICP 备案号、公安备案号
├── tasks.php           # 备份任务管理：创建、编辑、删除、立即执行 + 文件浏览器
├── backups/            # 备份文件存储目录（Web 禁止直接访问）
│   ├── .htaccess       # Apache: Deny from all
│   └── index.php       # 返回 403
└── README.md
```

---

## 数据库表结构

| 表名 | 用途 | 关键字段 |
|------|------|---------|
| `config` | 网站配置 | site_name, site_logo, icp_number, police_number |
| `admin` | 管理员账户 | username, password (bcrypt) |
| `servers` | MC 服务器信息 | name, directory (绝对路径) |
| `backup_tasks` | 备份任务 | backup_folder, backup_time, backup_destination, backup_path_type, backup_filename, backup_items (JSON), auto_delete, encrypted, encrypt_password (AES-256-CBC) |
| `backup_records` | 备份记录 | filename, file_size, file_path, is_public, download_password (bcrypt) |
| `backup_jobs` | 任务执行日志 | status, message, log_text (LONGTEXT) |

---

## 安全机制

| 防护措施 | 实现方式 |
|---------|---------|
| **SQL 注入** | 所有 SQL 查询使用 PDO 预处理 + 参数绑定，无字符串拼接 |
| **XSS 跨站脚本** | 所有用户输入/数据库输出通过 `htmlspecialchars()` 转义 |
| **CSRF 跨站请求** | 所有 POST 请求包含 CSRF Token，`hash_equals` 验证 |
| **密码存储** | 管理员密码 → `password_hash(BCRYPT)`；下载密码 → 同上；任务加密密码 → `openssl_encrypt(AES-256-CBC)` + 随机 IV |
| **文件下载保护** | PHP 代理下载，`realpath()` 路径验证，不暴露实际文件路径 |
| **路径穿越防护** | 备份项验证拒绝 `..` 和绝对路径；恢复验证 ZIP 内每个条目 |
| **目录访问保护** | `backups/` 有 `.htaccess` + `index.php` 双重防护；`config.php` / `database.sql` 被 `.htaccess` 禁止 |
| **会话安全** | 登录时 `session_regenerate_id(true)`；退出时销毁会话 + 清除 cookie |
| **安装安全** | `install.php` 安装成功后自动删除 |
| **CLI 限制** | `cron.php` 仅允许命令行执行（`php_sapi_name()` 检查），Web 访问返回 403 |
| **磁盘空间检查** | 备份前检查目标磁盘剩余空间（<100MB 拒绝，<1GB 警告）|

---

## 注意事项

### 权限要求

1. **`backups/` 目录**必须对 Web 进程（如 `www-data`）有读写权限
2. 使用**绝对路径**存放备份时，目标目录必须对 Web 进程有写入权限
3. 备份**源目录**（MC 服务器目录）必须对 Web 进程有读取权限（至少能读需要备份的文件）
4. 如果 MC 服务器目录权限受限，可仅对需要的子目录授权：`chmod o+r /path/to/world`

### 性能建议

1. **务必安装系统 `zip` 命令**：`apt install zip`，备份速度提升 10-50 倍，且不消耗 PHP 内存
2. 备份超大目录时建议在 `php.ini` 中放宽限制：
   ```ini
   memory_limit = -1
   max_execution_time = 0
   ```
3. Nginx 用户建议调大超时：
   ```nginx
   fastcgi_read_timeout 3600s;
   ```
4. 面板使用 `sendResponseAndContinue` 技术：备份开始后立即返回 HTTP 响应，后续在后台执行，避免浏览器/Nginx 超时

### 备份策略建议

1. 为每台 MC 服务器创建独立的备份任务
2. 设置合理的备份时间（如凌晨 3:00），避开玩家高峰期
3. **推荐使用相对路径**存放备份——文件统一管理，权限简单
4. 启用「自动清理」避免旧备份堆积占满磁盘
5. 重要备份建议额外设置下载密码
6. 文件名模板使用 `{server_name}_{date}_{time}.zip` 避免单日多次备份文件名重复

### 恢复备份注意事项

1. 恢复前系统会**自动创建当前服务器状态的临时备份**（存于 `backups/pre_restore/`）
2. 如果预恢复备份失败（权限不足、磁盘满），恢复仍会继续，但无回滚文件
3. 恢复使用 ZipArchive 扩展，含 **ZIP Slip 防护**——拒绝包含 `..` 或绝对路径的文件条目
4. `backups/pre_restore/` 目录不会自动清理，建议定期手动清理

---

## 故障排查

| 问题 | 可能原因 | 解决方案 |
|------|---------|---------|
| **PDOException: No such file or directory** | `localhost` 尝试 Unix socket 连接（socket 路径错误） | 将 `DB_HOST` 从 `localhost` 改为 `127.0.0.1` 强制 TCP 连接 |
| **备份卡在「正在创建 ZIP 归档」** | `exec()` 被禁用 + 大目录 ZipArchive 遍历 | 安装系统 `zip` 命令 + 启用 `exec()` 函数 |
| **备份日志只显示 "0"** | MySQL 中 `\|\|` 不是字符串拼接（被当作 OR） | 已改用 `CONCAT()`，不再有此问题 |
| **Permission denied** | Web 进程对源/目标目录无权限 | `chmod -R o+r 源目录` 或 `chown www-data 目标目录` |
| **No space left on device** | 磁盘空间不足 | 清理磁盘；面板已自动检查：不足 100MB 拒绝备份，不足 1GB 发出警告 |
| **备份后无下载密码** | 旧版本 INSERT 语句缺少 `download_password` 字段 | 已修复，确认升级到最新代码 |
| **路径类型切换时弹窗关闭** | overlay 点击事件误判（移动端下拉选择） | 已修复：改用 `!e.target.closest('.modal')` 判断 |
| **定时任务不支持绝对路径** | cron.php 未传递 `backup_path_type` 参数 | 已修复，确认升级到最新代码 |
| **PDO 连接失败** | MySQL 服务未运行或凭据错误 | `systemctl status mysql`；检查 `config.php` 凭据 |
| **安装失败** | 数据库权限不足 | 确保数据库用户有 CREATE DATABASE 和 CREATE TABLE 权限 |

### 日志位置

- cron 任务日志：`/var/log/mcsbp_cron.log`（在 crontab 中配置）
- PHP 错误日志：`/var/log/php*.log` 或 Web 服务器错误日志
- 备份任务日志：在「任务状态」页面的日志详情弹窗中查看
- 恢复预备份失败日志：PHP `error_log`

---

## 设计理念

- **配色**：深蓝灰侧边栏 (#1e293b) + 微暖灰背景 (#f9fafb) + 柔和专业蓝强调色 (#3b82f6)
- **字体**：系统字体栈，等宽字体用于路径和代码展示
- **动效**：0.2s 快速过渡，不干扰操作流
- **响应式**：移动端汉堡菜单、表格转卡片布局、触控区域 ≥ 44px
- **安静不打扰**：无弹窗骚扰、无强制通知、专业低调的设计语言

---

## 技术架构

- **后端**：原生 PHP（无框架），PDO 数据库抽象层
- **前端**：原生 HTML/CSS/JS（无构建工具），Fetch API AJAX
- **备份引擎**：双重策略——系统 zip（C 原生，优先）+ PHP ZipArchive（Fallback）
- **加密**：OpenSSL AES-256-CBC（可逆存储）+ bcrypt（不可逆哈希）
- **部署**：仅需 PHP + MySQL，无 Composer 依赖

---

## 开源协议

MIT License

Copyright (c) 2026 SZY Innovation Studio

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

---

**Powered by SZY Innovation Studio**
