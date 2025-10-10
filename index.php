<?php
declare(strict_types=1);

// Ensure correct encoding for multibyte file names
mb_internal_encoding('UTF-8');

// Root of the website (current directory where this file lives)
$ROOT_DIR = __DIR__;

// Polyfill for PHP < 8.0
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return strpos($haystack, $needle) === 0;
  }
}

// Sessions for password-protected paths
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Helpers
function h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isSafeRelativePath(string $relativePath): bool {
  if ($relativePath === '') {
    return true;
  }
  $segments = explode('/', $relativePath);
  foreach ($segments as $segment) {
    if ($segment === '' || $segment === '.' || $segment === '..') {
      return false;
    }
    if (str_starts_with($segment, '.')) { // hide dot files/folders
      return false;
    }
  }
  return true;
}

function humanFileSize(int $bytes): string {
  if ($bytes < 1024) {
    return $bytes . ' B';
  }
  $units = ['KB','MB','GB','TB','PB'];
  $index = 0;
  $size = $bytes / 1024;
  while ($size >= 1024 && $index < count($units) - 1) {
    $size /= 1024;
    $index++;
  }
  return sprintf('%.2f %s', $size, $units[$index]);
}

function formatDate(int $timestamp): string {
  return date('Y-m-d H:i', $timestamp);
}

// Pretty URL helpers
function getBaseUriPrefix(): string {
  $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
  $scriptDir = str_replace('\\', '/', dirname($scriptName));
  if ($scriptDir === '/' || $scriptDir === '\\') {
    return '';
  }
  return rtrim($scriptDir, '/');
}
function decodePath(string $path): string {
  $path = str_replace('\\', '/', $path);
  $path = preg_replace('#/+#', '/', $path);
  $path = trim($path, '/');
  if ($path === '') { return ''; }
  $parts = explode('/', $path);
  $decoded = [];
  foreach ($parts as $p) { $decoded[] = rawurldecode($p); }
  return implode('/', $decoded);
}
function pathToHref(string $rel): string {
  $base = getBaseUriPrefix();
  $front = ($base === '' ? '' : $base) . '/index.php';
  if ($rel === '') { return $front . '/'; }
  $parts = explode('/', $rel);
  $enc = [];
  foreach ($parts as $p) { $enc[] = rawurlencode($p); }
  return $front . '/' . implode('/', $enc);
}
function downloadHref(string $rel): string {
  $base = getBaseUriPrefix();
  return ($base === '' ? '' : $base . '/') . 'download.php?p=' . rawurlencode($rel);
}
function assetHref(string $rel): string {
  $base = getBaseUriPrefix();
  $rel = str_replace('\\', '/', $rel);
  $parts = array_values(array_filter(explode('/', $rel), 'strlen'));
  $enc = [];
  foreach ($parts as $p) { $enc[] = rawurlencode($p); }
  return ($base === '' ? '' : $base . '/') . implode('/', $enc);
}

// Determine current directory from request URI
$basePrefix = getBaseUriPrefix();
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($basePrefix !== '' && str_starts_with($reqPath, $basePrefix . '/')) {
  $reqPath = substr($reqPath, strlen($basePrefix));
}
if ($reqPath === '' || $reqPath === '/') {
  $requestedRelDir = '';
} else {
  if (str_starts_with($reqPath, '/')) { $reqPath = substr($reqPath, 1); }
  if (str_starts_with($reqPath, 'index.php')) {
    $rest = substr($reqPath, strlen('index.php'));
    $reqPath = ltrim($rest, '/');
  }
  $requestedRelDir = decodePath($reqPath);
}

if (!isSafeRelativePath($requestedRelDir)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "非法路径";
  exit;
}

// Hidden/password rules helpers
function normalizeRel(string $rel): string {
  $rel = str_replace('\\', '/', $rel);
  $rel = trim($rel);
  $rel = trim($rel, '/');
  return $rel;
}
function readEnvLines(string $file): array {
  if (!is_file($file)) { return []; }
  $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) { return []; }
  $out = [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || $line[0] === ';') { continue; }
    $out[] = $line;
  }
  return $out;
}
function loadHiddenPaths(string $rootDir): array {
  $raw = readEnvLines($rootDir . DIRECTORY_SEPARATOR . 'hide.env');
  $hidden = [];
  foreach ($raw as $entry) {
    $entry = normalizeRel($entry);
    if ($entry !== '' && !in_array($entry, $hidden, true)) { $hidden[] = $entry; }
  }
  usort($hidden, function($a, $b) { return strlen($b) <=> strlen($a); });
  return $hidden;
}
function isHiddenPath(string $rel, array $hidden): bool {
  if ($rel === '') { return false; }
  foreach ($hidden as $h) {
    if ($rel === $h || str_starts_with($rel, $h . '/')) { return true; }
  }
  return false;
}
function loadPasswordRules(string $rootDir): array {
  $raw = readEnvLines($rootDir . DIRECTORY_SEPARATOR . 'password.env');
  $rules = [];
  foreach ($raw as $line) {
    $path = '';
    $pass = '';
    if (strpos($line, '=') !== false) {
      [$left, $right] = explode('=', $line, 2);
      $path = normalizeRel($left);
      $pass = trim($right);
    } else {
      $parts = preg_split('/\s+/', $line, 2);
      if ($parts !== false && count($parts) === 2) {
        $path = normalizeRel($parts[0]);
        $pass = trim($parts[1]);
      }
    }
    if ($path !== '' && $pass !== '') { $rules[] = ['path' => $path, 'password' => $pass]; }
  }
  usort($rules, function($a, $b) { return strlen($b['path']) <=> strlen($a['path']); });
  return $rules;
}
function findPasswordRuleFor(string $rel, array $rules): ?array {
  if ($rel === '') { return null; }
  foreach ($rules as $rule) {
    $prefix = $rule['path'];
    if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) { return $rule; }
  }
  return null;
}
function hasPasswordAccess(string $rel, array $rules): bool {
  $rule = findPasswordRuleFor($rel, $rules);
  if ($rule === null) { return true; }
  $allowed = isset($_SESSION['pw_ok']) && is_array($_SESSION['pw_ok']) ? $_SESSION['pw_ok'] : [];
  foreach ($allowed as $prefix) {
    if ($rule['path'] === $prefix && ($rel === $prefix || str_starts_with($rel, $prefix . '/'))) { return true; }
  }
  return false;
}
function grantPasswordAccess(string $prefix): void {
  if (!isset($_SESSION['pw_ok']) || !is_array($_SESSION['pw_ok'])) { $_SESSION['pw_ok'] = []; }
  if (!in_array($prefix, $_SESSION['pw_ok'], true)) { $_SESSION['pw_ok'][] = $prefix; }
}

$hiddenPaths = loadHiddenPaths($ROOT_DIR);
$passwordRules = loadPasswordRules($ROOT_DIR);

// If current path is hidden entirely, show 404
if (isHiddenPath($requestedRelDir, $hiddenPaths)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo '未找到资源';
  exit;
}

// Handle password submission
$authError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['password'])) {
  $rule = findPasswordRuleFor($requestedRelDir, $passwordRules);
  if ($rule !== null) {
    $input = (string)($_POST['password'] ?? '');
    if (hash_equals($rule['password'], $input)) {
      grantPasswordAccess($rule['path']);
      header('Location: ' . pathToHref($requestedRelDir));
      exit;
    } else {
      $authError = '密码错误，请重试。';
    }
  }
}

$absoluteDir = realpath($ROOT_DIR . DIRECTORY_SEPARATOR . ($requestedRelDir === '' ? '.' : $requestedRelDir));
if ($absoluteDir === false || strpos($absoluteDir, $ROOT_DIR) !== 0 || !is_dir($absoluteDir)) {
  // Fallback to root if anything invalid
  $absoluteDir = $ROOT_DIR;
  $requestedRelDir = '';
}

// Scan directory contents
$entries = @scandir($absoluteDir);
if ($entries === false) {
  $entries = [];
}

$directories = [];
$files = [];

foreach ($entries as $entry) {
  if ($entry === '.' || $entry === '..') {
    continue;
  }
  if ($entry === 'hide.env' || $entry === 'password.env') { continue; }
  if (str_starts_with($entry, '.')) { // hide dot files/folders
    continue;
  }

  $fullPath = $absoluteDir . DIRECTORY_SEPARATOR . $entry;
  if (is_dir($fullPath)) {
    $childCount = 0;
    $childEntries = @scandir($fullPath);
    if ($childEntries !== false) {
      foreach ($childEntries as $c) {
        if ($c === '.' || $c === '..' || str_starts_with($c, '.')) {
          continue;
        }
        $childCount++;
      }
    }
    $relPath = $requestedRelDir === '' ? $entry : ($requestedRelDir . '/' . $entry);
    if (isHiddenPath($relPath, $hiddenPaths)) { continue; }
    $directories[] = [
      'name' => $entry,
      'rel' => $relPath,
      'mtime' => @filemtime($fullPath) ?: 0,
      'count' => $childCount,
    ];
  } elseif (is_file($fullPath)) {
    $relPath = $requestedRelDir === '' ? $entry : ($requestedRelDir . '/' . $entry);
    if (isHiddenPath($relPath, $hiddenPaths)) { continue; }
    $files[] = [
      'name' => $entry,
      'rel' => $relPath,
      'mtime' => @filemtime($fullPath) ?: 0,
      'size' => @filesize($fullPath) ?: 0,
      'ext' => strtolower(pathinfo($entry, PATHINFO_EXTENSION)),
    ];
  }
}

// Sort by name (case-insensitive)
usort($directories, function(array $a, array $b) {
  return strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
});
usort($files, function(array $a, array $b) {
  return strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
});

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>文件浏览器</title>
    <link rel="stylesheet" href="<?php echo h(assetHref('assets/style.css')); ?>" />
  </head>
  <body>
    <header class="site-header">
      <div class="container">
        <h1 class="title">文件浏览器</h1>
        <p class="subtitle">浏览并下载当前网站目录下的文件</p>
      </div>
    </header>

    <main class="container page">
      <nav class="breadcrumbs">
        <span>当前位置：</span>
        <?php
          $crumbs = [];
          if ($requestedRelDir === '') {
            $crumbs[] = '<span class="crumb">根目录</span>';
          } else {
            $crumbs[] = '<a class="crumb" href="' . h(pathToHref('')) . '">根目录</a>';
            $parts = explode('/', $requestedRelDir);
            $pathAcc = [];
            foreach ($parts as $idx => $part) {
              $pathAcc[] = $part;
              $rel = implode('/', $pathAcc);
              $crumbs[] = '<a class="crumb" href="' . h(pathToHref($rel)) . '">' . h($part) . '</a>';
            }
          }
          echo implode('<span class="sep">/</span>', $crumbs);
        ?>
      </nav>

      <?php if ($requestedRelDir !== ''): ?>
        <?php
          $parent = dirname($requestedRelDir);
          if ($parent === '.' || $parent === DIRECTORY_SEPARATOR) {
            $parent = '';
          }
        ?>
        <div class="toolbar">
          <a class="btn" href="<?php echo h(pathToHref($parent)); ?>">返回上一级</a>
          <a class="btn outline" href="<?php echo h(pathToHref('')); ?>">回到根目录</a>
        </div>
      <?php endif; ?>

      <?php if (!hasPasswordAccess($requestedRelDir, $passwordRules)): ?>
        <section class="listing">
          <div class="card auth-card">
            <form method="post" action="<?php echo h(pathToHref($requestedRelDir)); ?>">
              <h2>该目录受密码保护</h2>
              <div class="form-group">
                <label for="password">请输入访问密码：</label>
                <input type="password" id="password" name="password" required />
              </div>
              <?php if (!empty($authError)): ?>
                <div class="error"><?php echo h($authError); ?></div>
              <?php endif; ?>
              <div class="form-actions">
                <button class="btn primary" type="submit">确认</button>
                <a class="btn outline" href="<?php echo h(pathToHref('')); ?>">返回首页</a>
              </div>
            </form>
          </div>
        </section>
      <?php else: ?>
        <section class="listing">
          <div class="card">
            <table class="file-table">
              <thead>
                <tr>
                  <th>名称</th>
                  <th class="type-col">类型</th>
                  <th class="size-col">大小</th>
                  <th class="date-col">修改时间</th>
                  <th class="actions-col">操作</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($directories) && empty($files)): ?>
                  <tr>
                    <td colspan="5" class="empty">空文件夹</td>
                  </tr>
                <?php endif; ?>

                <?php foreach ($directories as $dir): ?>
                  <tr>
                    <td>
                      <a class="file-link icon-folder" href="<?php echo h(pathToHref($dir['rel'])); ?>" title="打开文件夹">
                        <?php echo h($dir['name']); ?>
                      </a>
                      <span class="muted count">(<?php echo (int)$dir['count']; ?> 项)</span>
                    </td>
                    <td class="muted">文件夹</td>
                    <td class="muted">-</td>
                    <td class="muted"><?php echo h(formatDate((int)$dir['mtime'])); ?></td>
                    <td>
                      <a class="btn small" href="<?php echo h(pathToHref($dir['rel'])); ?>">打开</a>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php foreach ($files as $file): ?>
                  <?php $extClass = $file['ext'] !== '' ? (' ext-' . preg_replace('/[^a-z0-9_-]/i', '-', $file['ext'])) : ''; ?>
                  <tr>
                    <td>
                      <a class="file-link icon-file<?php echo h($extClass); ?>" href="<?php echo h(downloadHref($file['rel'])); ?>" title="下载文件">
                        <?php echo h($file['name']); ?>
                      </a>
                    </td>
                    <td class="muted">文件</td>
                    <td class="muted"><?php echo h(humanFileSize((int)$file['size'])); ?></td>
                    <td class="muted"><?php echo h(formatDate((int)$file['mtime'])); ?></td>
                    <td>
                      <a class="btn small primary" href="<?php echo h(downloadHref($file['rel'])); ?>">下载</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
    </main>

    <footer class="site-footer">
      <div class="container">
        <span class="muted">© <?php echo date('Y'); ?> 文件浏览器</span>
      </div>
    </footer>
  </body>
  
</html>

