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

// Current directory from query string (relative to root)
$requestedRelDir = isset($_GET['dir']) ? (string)$_GET['dir'] : '';
$requestedRelDir = str_replace('\\', '/', $requestedRelDir);
$requestedRelDir = trim($requestedRelDir);
$requestedRelDir = trim($requestedRelDir, '/');

if (!isSafeRelativePath($requestedRelDir)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "非法路径";
  exit;
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
    $directories[] = [
      'name' => $entry,
      'rel' => $requestedRelDir === '' ? $entry : ($requestedRelDir . '/' . $entry),
      'mtime' => @filemtime($fullPath) ?: 0,
      'count' => $childCount,
    ];
  } elseif (is_file($fullPath)) {
    $files[] = [
      'name' => $entry,
      'rel' => $requestedRelDir === '' ? $entry : ($requestedRelDir . '/' . $entry),
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
    <link rel="stylesheet" href="assets/style.css" />
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
            $crumbs[] = '<a class="crumb" href="?">根目录</a>';
            $parts = explode('/', $requestedRelDir);
            $pathAcc = [];
            foreach ($parts as $idx => $part) {
              $pathAcc[] = $part;
              $rel = implode('/', $pathAcc);
              $crumbs[] = '<a class="crumb" href="?dir=' . rawurlencode($rel) . '">' . h($part) . '</a>';
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
          <a class="btn" href="<?php echo $parent === '' ? '?' : ('?dir=' . rawurlencode($parent)); ?>">返回上一级</a>
          <a class="btn outline" href="?">回到根目录</a>
        </div>
      <?php endif; ?>

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
                    <a class="file-link icon-folder" href="?dir=<?php echo rawurlencode($dir['rel']); ?>" title="打开文件夹">
                      <?php echo h($dir['name']); ?>
                    </a>
                    <span class="muted count">(<?php echo (int)$dir['count']; ?> 项)</span>
                  </td>
                  <td class="muted">文件夹</td>
                  <td class="muted">-</td>
                  <td class="muted"><?php echo h(formatDate((int)$dir['mtime'])); ?></td>
                  <td>
                    <a class="btn small" href="?dir=<?php echo rawurlencode($dir['rel']); ?>">打开</a>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php foreach ($files as $file): ?>
                <?php $extClass = $file['ext'] !== '' ? (' ext-' . preg_replace('/[^a-z0-9_-]/i', '-', $file['ext'])) : ''; ?>
                <tr>
                  <td>
                    <a class="file-link icon-file<?php echo h($extClass); ?>" href="download.php?p=<?php echo rawurlencode($file['rel']); ?>" title="下载文件">
                      <?php echo h($file['name']); ?>
                    </a>
                  </td>
                  <td class="muted">文件</td>
                  <td class="muted"><?php echo h(humanFileSize((int)$file['size'])); ?></td>
                  <td class="muted"><?php echo h(formatDate((int)$file['mtime'])); ?></td>
                  <td>
                    <a class="btn small primary" href="download.php?p=<?php echo rawurlencode($file['rel']); ?>">下载</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>

    <footer class="site-footer">
      <div class="container">
        <span class="muted">© <?php echo date('Y'); ?> 文件浏览器</span>
      </div>
    </footer>
  </body>
  
</html>

