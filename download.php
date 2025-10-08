<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');

$supports_str_starts_with = function_exists('str_starts_with');
if (!$supports_str_starts_with) {
  function str_starts_with(string $haystack, string $needle): bool {
    return strpos($haystack, $needle) === 0;
  }
}

$ROOT_DIR = __DIR__;

function badRequest(string $message = 'Bad Request'): void {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo $message;
  exit;
}

function notFound(): void {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo '文件不存在';
  exit;
}

function isSafeRelativePath(string $relativePath): bool {
  if ($relativePath === '') {
    return false; // require a file
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

$relative = isset($_GET['p']) ? (string)$_GET['p'] : '';
$relative = str_replace('\\', '/', $relative);
$relative = trim($relative, '/');

if (!isSafeRelativePath($relative)) {
  badRequest('非法路径');
}

$absolute = realpath($ROOT_DIR . DIRECTORY_SEPARATOR . $relative);
if ($absolute === false || strpos($absolute, $ROOT_DIR) !== 0 || !is_file($absolute)) {
  notFound();
}

// Determine filename and mime
$basename = basename($absolute);
$filesize = filesize($absolute);

// Basic mime detection
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  if ($finfo !== false) {
    $detected = finfo_file($finfo, $absolute);
    if (is_string($detected) && $detected !== '') {
      $mime = $detected;
    }
    finfo_close($finfo);
  }
}

// Send headers
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
// RFC 5987 compliant Content-Disposition with ASCII fallback
$asciiFallback = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '_', $basename);
$asciiFallback = str_replace(['\\', '"'], ['\\\\', '\\"'], $asciiFallback);
header('Content-Disposition: attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . rawurlencode($basename));
header('Content-Length: ' . (string)$filesize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');

// Clean buffers and stream file
while (ob_get_level() > 0) {
  ob_end_clean();
}

$chunkSize = 8192;
$handle = fopen($absolute, 'rb');
if ($handle === false) {
  notFound();
}
set_time_limit(0);
while (!feof($handle)) {
  $buffer = fread($handle, $chunkSize);
  if ($buffer === false) {
    break;
  }
  echo $buffer;
  flush();
}
fclose($handle);
exit;

