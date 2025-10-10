<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');

$supports_str_starts_with = function_exists('str_starts_with');
if (!$supports_str_starts_with) {
  function str_starts_with(string $haystack, string $needle): bool {
    return strpos($haystack, $needle) === 0;
  }
}
if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    if ($needle === '') { return true; }
    $len = strlen($needle);
    return substr($haystack, -$len) === $needle;
  }
}

$session_status = function_exists('session_status') ? session_status() : PHP_SESSION_NONE;
if ($session_status !== PHP_SESSION_ACTIVE) {
  @session_start();
}

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

// Never allow downloading env files
if ($relative === 'hide.env' || $relative === 'password.env' || str_ends_with($relative, '/hide.env') || str_ends_with($relative, '/password.env')) {
  notFound();
}

$absolute = realpath($ROOT_DIR . DIRECTORY_SEPARATOR . $relative);
if ($absolute === false || strpos($absolute, $ROOT_DIR) !== 0 || !is_file($absolute)) {
  notFound();
}

// Enforce hide and password rules (reuse simple loaders)
function normalizeRel(string $rel): string {
  $rel = str_replace('\\', '/', $rel);
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
function requiresPassword(string $rel, array $rules): ?string {
  foreach ($rules as $rule) {
    $prefix = $rule['path'];
    if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) { return $prefix; }
  }
  return null;
}

$hiddenPaths = loadHiddenPaths($ROOT_DIR);
if (isHiddenPath($relative, $hiddenPaths)) {
  notFound();
}
$passwordRules = loadPasswordRules($ROOT_DIR);
$requiredPrefix = requiresPassword($relative, $passwordRules);
if ($requiredPrefix !== null) {
  $ok = isset($_SESSION['pw_ok']) && is_array($_SESSION['pw_ok']) ? $_SESSION['pw_ok'] : [];
  $allowed = false;
  foreach ($ok as $prefix) {
    if ($prefix === $requiredPrefix && ($relative === $prefix || str_starts_with($relative, $prefix . '/'))) { $allowed = true; break; }
  }
  if (!$allowed) {
    notFound();
  }
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

