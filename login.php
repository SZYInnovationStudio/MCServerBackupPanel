<?php
/**
 * MCServerBackupPanel — Admin Login
 *
 * Centered card layout with logo, username/password fields.
 * Redirects to console.php on success.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/config.php';

// Redirect if not yet installed (install.lock missing but install.php exists)
if (!file_exists(__DIR__ . '/install.lock') && file_exists(__DIR__ . '/install.php')) {
    header('Location: ' . SITE_URL . '/install.php');
    exit;
}

// Redirect if already logged in
if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . SITE_URL . '/console.php');
    exit;
}

$error = '';

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码。';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, username, password FROM admin WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                header('Location: ' . SITE_URL . '/console.php');
                exit;
            } else {
                $error = '用户名或密码错误。';
            }
        } catch (Exception $e) {
            $error = '系统错误，请稍后再试。';
        }
    }
}

$siteName = getConfig('site_name') ?: 'MCServerBackupPanel';
$siteLogo = getConfig('site_logo') ?: '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 — <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="common.css">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <?php if ($siteLogo): ?>
                <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="Logo">
            <?php else: ?>
                <div style="width:56px;height:56px;border-radius:8px;background:var(--accent);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:24px;font-weight:600;margin-bottom:12px;">M</div>
            <?php endif; ?>
            <h1><?php echo htmlspecialchars($siteName); ?></h1>
            <p>服务器备份管理面板</p>
        </div>

        <div class="login-error<?php echo $error ? '' : ' hidden'; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo htmlspecialchars($error); ?>
        </div>

        <form method="post" action="login.php" novalidate>
            <div class="form-group">
                <label class="form-label" for="username">用户名</label>
                <input type="text" id="username" name="username" class="form-input" placeholder="请输入管理员用户名" required autocomplete="username" autofocus>
            </div>
            <div class="form-group">
                <label class="form-label" for="password">密码</label>
                <input type="password" id="password" name="password" class="form-input" placeholder="请输入密码" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary w-full mt-12">登录</button>
        </form>
    </div>
</body>
</html>
