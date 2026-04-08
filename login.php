<?php
// login.php - 管理员登录页面
session_start();

// 检查是否已安装
$isInstalled = file_exists(__DIR__ . '/config.php');

// 如果未安装，跳转到安装页面
if (!$isInstalled) {
    header('Location: install.php');
    exit;
}

// 如果已经登录，直接跳转后台
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: console.php');
    exit;
}

require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                header('Location: console.php');
                exit;
            } else {
                $error = '用户名或密码错误';
            }
        } catch (PDOException $e) {
            $error = '系统错误，请稍后重试';
        }
    }
}

$siteConfig = getSiteConfig();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?php echo htmlspecialchars($siteConfig['site_name']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            max-width: 450px;
            width: 100%;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .login-body {
            padding: 40px 30px;
        }
        .form-group {
            margin-bottom: 25px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .error {
            background: #fed7d7;
            color: #c53030;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            background: #f7fafc;
            font-size: 12px;
            color: #718096;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><?php echo htmlspecialchars($siteConfig['site_name']); ?></h1>
            <p>服务器备份管理系统</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn">登录管理后台</button>
            </form>
        </div>
        
        <div class="footer">
            Powered by <a href="https://github.com/szyinnovationstudio/MCServerBackupPanel" target="_blank">MCServerBackupPanel</a><br>
            SZY创新工作室 版权所有
        </div>
    </div>
</body>
</html>