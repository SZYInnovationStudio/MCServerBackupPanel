<?php
/**
 * MCServerBackupPanel — Site Settings
 *
 * Edit site name, logo, ICP/Police numbers.
 *
 * @package MCSBP
 * @version 1.0.0
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/admin_layout.php';

$db = getDB();

// Fetch current settings
$settings = $db->query("SELECT * FROM config LIMIT 1")->fetch();

// Handle form submission
$saveMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF()) {
        $saveMessage = '<div class="toast toast-error" style="position:static;margin-bottom:16px;"><div class="toast-body"><div class="toast-title">安全验证失败</div><div class="toast-msg">CSRF Token 无效，请刷新页面后重试。</div></div></div>';
    } else {
        $siteName     = trim($_POST['site_name'] ?? '');
        $siteLogo     = trim($_POST['site_logo'] ?? '');
        $icpNumber    = trim($_POST['icp_number'] ?? '');
        $policeNumber = trim($_POST['police_number'] ?? '');

        if ($siteName === '') {
            $saveMessage = '<div class="toast toast-error" style="position:static;margin-bottom:16px;"><div class="toast-body"><div class="toast-title">保存失败</div><div class="toast-msg">网站名称不能为空。</div></div></div>';
        } else {
            $stmt = $db->prepare(
                "UPDATE config SET site_name = ?, site_logo = ?, icp_number = ?, police_number = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([
                $siteName,
                $siteLogo ?: null,
                $icpNumber ?: null,
                $policeNumber ?: null,
                $settings['id']
            ]);
            refreshConfig();
            $settings = $db->query("SELECT * FROM config LIMIT 1")->fetch();
            $saveMessage = '<div class="toast toast-success" style="position:static;margin-bottom:16px;"><div class="toast-body"><div class="toast-title">保存成功</div><div class="toast-msg">网站设置已更新。</div></div></div>';
        }
    }
}

$csrfToken = generateCSRF();

adminHeader('网站设置', 'setting');
?>

<div class="page-content">
    <?php echo $saveMessage; ?>

    <div class="card" style="max-width:640px;width:100%;">
        <div class="card-header">
            <h2 class="card-title">基本设置</h2>
        </div>
        <form method="post" action="setting.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="form-group">
                <label class="form-label" for="site_name">网站名称</label>
                <input type="text" id="site_name" name="site_name" class="form-input"
                       value="<?php echo htmlspecialchars($settings['site_name'] ?? 'MCServerBackupPanel'); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="site_logo">网站头像 URL <span class="optional">(选填)</span></label>
                <div class="flex items-center gap-16">
                    <div class="avatar-preview" id="logo-preview">
                        <?php if (!empty($settings['site_logo'])): ?>
                            <img src="<?php echo htmlspecialchars($settings['site_logo']); ?>" alt="Logo Preview">
                        <?php else: ?>
                            <span style="font-size:24px;font-weight:600;color:var(--text-muted);">M</span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;">
                        <input type="url" id="site_logo" name="site_logo" class="form-input"
                               value="<?php echo htmlspecialchars($settings['site_logo'] ?? ''); ?>"
                               placeholder="https://example.com/logo.png"
                               oninput="updateLogoPreview(this.value)">
                        <div class="form-hint">输入图片URL，将在侧边栏和登录页显示。</div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="icp_number">ICP备案号 <span class="optional">(选填)</span></label>
                <input type="text" id="icp_number" name="icp_number" class="form-input"
                       value="<?php echo htmlspecialchars($settings['icp_number'] ?? ''); ?>"
                       placeholder="京ICP备XXXXXXXX号">
            </div>

            <div class="form-group">
                <label class="form-label" for="police_number">公网安备号 <span class="optional">(选填)</span></label>
                <input type="text" id="police_number" name="police_number" class="form-input"
                       value="<?php echo htmlspecialchars($settings['police_number'] ?? ''); ?>"
                       placeholder="京公网安备 XXXXXXXXXX号">
            </div>

            <button type="submit" class="btn btn-primary mt-16">保存设置</button>
        </form>
    </div>
</div>

<script>
function updateLogoPreview(url) {
    var preview = document.getElementById('logo-preview');
    if (url) {
        preview.innerHTML = '<img src="' + url.replace(/"/g, '&quot;') + '" alt="Logo Preview" onerror="this.parentElement.innerHTML=\'<span style=font-size:24px;font-weight:600;color:var(--text-muted)>M</span>\'">';
    } else {
        preview.innerHTML = '<span style="font-size:24px;font-weight:600;color:var(--text-muted);">M</span>';
    }
}
</script>

<?php adminFooter(); ?>
