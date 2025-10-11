<?php
declare(strict_types=1);

// This file is included by index.php with context variables:
// - $ADMIN_MODE: 'setup' or 'admin'
// - $ADMIN_PATH: configured admin path
// - $ROOT_DIR: root directory
// - helper functions from index.php are available in scope

if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

// Paths
$adminEnvFile = $ROOT_DIR . DIRECTORY_SEPARATOR . 'admin.env';
$hideEnvFile = $ROOT_DIR . DIRECTORY_SEPARATOR . 'hide.env';
$passEnvFile = $ROOT_DIR . DIRECTORY_SEPARATOR . 'password.env';
$lockFile = $ROOT_DIR . DIRECTORY_SEPARATOR . 'admin.lock';
$logFile = $ROOT_DIR . DIRECTORY_SEPARATOR . 'access.log';

// Read current admin config
$adminConfig = function_exists('readAdminConfig') ? readAdminConfig($ROOT_DIR) : [
  'admin_path' => 'admin',
  'admin_user' => 'admin',
  'admin_pass_hash' => '',
  'title' => '文件浏览器',
];

function pathToHrefLocal(string $rel): string {
  return function_exists('pathToHref') ? pathToHref($rel) : '/index.php/' . rawurlencode($rel);
}
function assetHrefLocal(string $rel): string {
  return function_exists('assetHref') ? assetHref($rel) : '/' . ltrim($rel, '/');
}

// Utilities
function writeAdminEnv(array $cfg, string $file): bool {
  $lines = [];
  foreach ($cfg as $k => $v) {
    $lines[] = $k . '=' . $v;
  }
  $content = implode("\n", $lines) . "\n";
  return @file_put_contents($file, $content, LOCK_EX) !== false;
}

function ensureLock(string $file): void {
  if (!is_file($file)) {
    @file_put_contents($file, "ok\n", LOCK_EX);
  }
}

// Authentication for admin mode
$loggedIn = isset($_SESSION['__admin_login']) && $_SESSION['__admin_login'] === '1';
if ($ADMIN_MODE === 'admin') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $ok = false;
    if ($user !== '' && $pass !== '') {
      if ($user === ($adminConfig['admin_user'] ?? 'admin')) {
        $hash = (string)($adminConfig['admin_pass_hash'] ?? '');
        if ($hash !== '') {
          $ok = password_verify($pass, $hash);
        }
      }
    }
    if ($ok) {
      $_SESSION['__admin_login'] = '1';
      header('Location: ' . pathToHrefLocal($ADMIN_PATH));
      exit;
    } else {
      $loginError = '账号或密码错误';
    }
  }

  if (!$loggedIn) {
    // Show login form
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>后台登录</title>
    <link rel="stylesheet" href="<?php echo h(assetHrefLocal('assets/style.css')); ?>" />
  </head>
  <body>
    <main class="container page">
      <div class="card auth-card">
        <form method="post" action="<?php echo h(pathToHrefLocal($ADMIN_PATH)); ?>">
          <h2>后台登录</h2>
          <input type="hidden" name="action" value="login" />
          <div class="form-group">
            <label for="username">用户名</label>
            <input type="text" id="username" name="username" required />
          </div>
          <div class="form-group">
            <label for="password">密码</label>
            <input type="password" id="password" name="password" required />
          </div>
          <?php if (!empty($loginError)): ?><div class="error"><?php echo h($loginError); ?></div><?php endif; ?>
          <div class="form-actions">
            <button class="btn primary" type="submit">登录</button>
            <a class="btn outline" href="<?php echo h(pathToHrefLocal('')); ?>">返回首页</a>
          </div>
        </form>
      </div>
    </main>
  </body>
</html>
    <?php
    exit;
  }
}

// Handle setup post
if ($ADMIN_MODE === 'setup' && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
  $adminPath = trim((string)($_POST['admin_path'] ?? 'admin'));
  $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
  $adminPass = (string)($_POST['admin_pass'] ?? '');
  $siteTitle = trim((string)($_POST['title'] ?? '文件浏览器'));
  if ($adminPath === '' || $adminUser === '' || $adminPass === '') {
    $setupError = '请填写完整信息';
  } else {
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $cfg = [
      'admin_path' => $adminPath,
      'admin_user' => $adminUser,
      'admin_pass_hash' => $hash,
      'title' => $siteTitle,
    ];
    if (writeAdminEnv($cfg, $adminEnvFile)) {
      ensureLock($lockFile);
      header('Location: ' . pathToHrefLocal(''));
      exit;
    } else {
      $setupError = '保存失败，请检查文件写入权限';
    }
  }
}

// Save settings from admin
if ($ADMIN_MODE === 'admin' && $loggedIn && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save_settings') {
    $newTitle = trim((string)($_POST['title'] ?? '文件浏览器'));
    $newPath = trim((string)($_POST['admin_path'] ?? 'admin'));
    $newUser = trim((string)($_POST['admin_user'] ?? 'admin'));
    $newPass = (string)($_POST['admin_pass'] ?? '');
    $cfg = $adminConfig;
    $cfg['title'] = $newTitle !== '' ? $newTitle : $cfg['title'];
    $cfg['admin_path'] = $newPath !== '' ? $newPath : $cfg['admin_path'];
    $cfg['admin_user'] = $newUser !== '' ? $newUser : $cfg['admin_user'];
    if ($newPass !== '') { $cfg['admin_pass_hash'] = password_hash($newPass, PASSWORD_DEFAULT); }
    if (writeAdminEnv($cfg, $adminEnvFile)) {
      header('Location: ' . pathToHrefLocal($cfg['admin_path']));
      exit;
    } else {
      $adminError = '保存失败，请检查文件写入权限';
    }
  } elseif ($action === 'save_hide') {
    $content = (string)($_POST['hide_content'] ?? '');
    @file_put_contents($hideEnvFile, str_replace(["\r\n","\r"], "\n", $content));
  } elseif ($action === 'save_password') {
    $content = (string)($_POST['pass_content'] ?? '');
    @file_put_contents($passEnvFile, str_replace(["\r\n","\r"], "\n", $content));
  }
}

// Render
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo $ADMIN_MODE === 'setup' ? '初始化后台' : '后台管理'; ?></title>
    <link rel="stylesheet" href="<?php echo h(assetHrefLocal('assets/style.css')); ?>" />
  </head>
  <body>
    <main class="container page">
      <?php if ($ADMIN_MODE === 'setup'): ?>
        <div class="card auth-card">
          <form method="post" action="<?php echo h(pathToHrefLocal('setup')); ?>">
            <h2>初始化后台</h2>
            <div class="form-group">
              <label for="admin_path">后台路径（例如 admin）</label>
              <input type="text" id="admin_path" name="admin_path" value="<?php echo h($adminConfig['admin_path'] ?? 'admin'); ?>" required />
            </div>
            <div class="form-group">
              <label for="admin_user">后台账号</label>
              <input type="text" id="admin_user" name="admin_user" value="admin" required />
            </div>
            <div class="form-group">
              <label for="admin_pass">后台密码</label>
              <input type="password" id="admin_pass" name="admin_pass" required />
            </div>
            <div class="form-group">
              <label for="title">网站标题</label>
              <input type="text" id="title" name="title" value="文件浏览器" />
            </div>
            <?php if (!empty($setupError)): ?><div class="error"><?php echo h($setupError); ?></div><?php endif; ?>
            <div class="form-actions">
              <button class="btn primary" type="submit">保存并进入首页</button>
            </div>
          </form>
        </div>
      <?php else: ?>
        <div class="card auth-card">
          <h2>后台管理</h2>
          <?php if (!empty($adminError)): ?><div class="error"><?php echo h($adminError); ?></div><?php endif; ?>
          <form method="post" action="<?php echo h(pathToHrefLocal($ADMIN_PATH)); ?>" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="save_settings" />
            <div class="form-group">
              <label for="title">网站标题</label>
              <input type="text" id="title" name="title" value="<?php echo h($adminConfig['title'] ?? '文件浏览器'); ?>" />
            </div>
            <div class="form-group">
              <label for="admin_path">后台路径</label>
              <input type="text" id="admin_path" name="admin_path" value="<?php echo h($adminConfig['admin_path'] ?? 'admin'); ?>" />
            </div>
            <div class="form-group">
              <label for="admin_user">后台账号</label>
              <input type="text" id="admin_user" name="admin_user" value="<?php echo h($adminConfig['admin_user'] ?? 'admin'); ?>" />
            </div>
            <div class="form-group">
              <label for="admin_pass">后台密码（留空则不修改）</label>
              <input type="password" id="admin_pass" name="admin_pass" />
            </div>
            <div class="form-actions">
              <button class="btn primary" type="submit">保存设置</button>
            </div>
          </form>

          <form method="post" action="<?php echo h(pathToHrefLocal($ADMIN_PATH)); ?>" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="save_hide" />
            <div class="form-group">
              <label for="hide_content">隐藏路径（每行一条）</label>
              <textarea id="hide_content" name="hide_content" rows="8" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"><?php echo h(@file_get_contents($hideEnvFile) ?: ''); ?></textarea>
            </div>
            <div class="form-actions">
              <button class="btn" type="submit">保存隐藏规则</button>
            </div>
          </form>

          <form method="post" action="<?php echo h(pathToHrefLocal($ADMIN_PATH)); ?>">
            <input type="hidden" name="action" value="save_password" />
            <div class="form-group">
              <label for="pass_content">密码规则（每行 目录=密码 或 目录 空格 密码）</label>
              <textarea id="pass_content" name="pass_content" rows="8" style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;"><?php echo h(@file_get_contents($passEnvFile) ?: ''); ?></textarea>
            </div>
            <div class="form-actions">
              <button class="btn" type="submit">保存密码规则</button>
            </div>
          </form>
        </div>

        <div class="card" style="margin-top:16px;overflow:auto;">
          <table class="file-table" style="min-width:600px;">
            <thead>
              <tr>
                <th>访问记录</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
                if (!$lines) { $lines = []; }
                $lines = array_slice($lines, -500);
                if (empty($lines)) {
                  echo '<tr><td class="muted">暂无记录</td></tr>';
                } else {
                  foreach ($lines as $ln) {
                    echo '<tr><td class="muted">' . h($ln) . '</td></tr>';
                  }
                }
              ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </main>
  </body>
</html>

