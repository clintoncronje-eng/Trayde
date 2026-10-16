<?php
/* TRAYDE-V3 · enquiry.php · self-contained (PHP 5.6 – 8.3). Only needs inc/config.php */
function json_out_early($m){ while (ob_get_level() > 0) @ob_end_clean(); http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(array('ok'=>false,'message'=>$m)); exit; }
/* ---------- shared helpers ---------- */
/* Shared helpers — compatible with PHP 5.6 through 8.3 */
@ini_set('display_errors', '0');

// Any fatal error (incl. syntax errors in config.php) → readable JSON, not an HTML page
if (!function_exists('trayde_fatal_to_json')) {
  function trayde_fatal_to_json() {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
      while (ob_get_level() > 0) @ob_end_clean();
      if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
      echo json_encode(array('ok' => false, 'message' => 'PHP error: ' . $e['message'] . ' in ' . basename($e['file']) . ' line ' . $e['line']));
    }
  }
  register_shutdown_function('trayde_fatal_to_json');
}
ob_start();

if (!is_file(__DIR__ . '/inc/config.php')) json_out_early('inc/config.php not found');
require_once __DIR__ . '/inc/config.php';

if (!defined('DATA_DIR'))    define('DATA_DIR',    __DIR__ . '/data');
if (!defined('UPLOAD_DIR'))  define('UPLOAD_DIR',  __DIR__ . '/uploads');
if (!defined('PUBLIC_JSON')) define('PUBLIC_JSON', __DIR__ . '/site-data.json');
if (!defined('MAX_ENQUIRIES_PER_HOUR')) define('MAX_ENQUIRIES_PER_HOUR', 5);
if (!defined('MAX_LOGIN_FAILS')) define('MAX_LOGIN_FAILS', 8);

function g($arr, $key, $default = null) { return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default; }

function json_out($code, $arr) {
  while (ob_get_level() > 0) @ob_end_clean();   // drop stray warnings / BOMs
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  $flags = 0;
  if (defined('JSON_UNESCAPED_UNICODE')) $flags |= JSON_UNESCAPED_UNICODE;
  if (defined('JSON_UNESCAPED_SLASHES')) $flags |= JSON_UNESCAPED_SLASHES;
  if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
  echo json_encode($arr, $flags);
  exit;
}

function check_origin() {
  $o = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
  if ($o === '') return;
  $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
  $okList = array('https://' . $host, 'http://' . $host);
  if (defined('ALLOWED_ORIGINS')) { $a = constant('ALLOWED_ORIGINS'); if (is_array($a)) $okList = array_merge($okList, $a); }
  // also accept www / non-www of the same domain
  $bare = preg_replace('/^www\./i', '', $host);
  $okList[] = 'https://' . $bare; $okList[] = 'https://www.' . $bare;
  if (!in_array($o, $okList, true)) json_out(403, array('ok' => false, 'message' => 'Origin not allowed: ' . $o));
}

function require_post() {
  $m = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
  if ($m !== 'POST') json_out(405, array('ok' => false, 'message' => 'POST only (got ' . $m . ')'));
}

function read_json_body($maxBytes) {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') json_out(400, array('ok' => false, 'message' => 'Empty request — the server may have rejected the upload size (check post_max_size in check.php)'));
  if (strlen($raw) > $maxBytes) json_out(413, array('ok' => false, 'message' => 'Request too large'));
  $j = json_decode($raw, true);
  if (!is_array($j)) json_out(400, array('ok' => false, 'message' => 'Invalid JSON received'));
  return $j;
}

function ensure_dir($d) {
  if (!is_dir($d)) @mkdir($d, 0755, true);
  if (!is_dir($d) || !is_writable($d)) json_out(500, array('ok' => false, 'message' => 'Folder not writable: ' . basename($d) . ' — set permissions to 755 in File Manager'));
}

function load_json_file($path, $default) {
  if (!is_file($path)) return $default;
  $j = json_decode((string)@file_get_contents($path), true);
  return is_array($j) ? $j : $default;
}

function rand_hex($n) {
  if (function_exists('random_bytes')) return bin2hex(random_bytes($n));
  if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes($n));
  return substr(sha1(uniqid('', true) . mt_rand()), 0, $n * 2);
}

function enc_json($data) {
  $flags = 0;
  if (defined('JSON_UNESCAPED_UNICODE')) $flags |= JSON_UNESCAPED_UNICODE;
  if (defined('JSON_UNESCAPED_SLASHES')) $flags |= JSON_UNESCAPED_SLASHES;
  return json_encode($data, $flags);
}

/* Atomic write: temp file then rename */
function save_json_file($path, $data) {
  $json = enc_json($data);
  if ($json === false) return false;
  $tmp = $path . '.tmp' . rand_hex(4);
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  @chmod($tmp, 0644);
  if (!@rename($tmp, $path)) { @unlink($path); if (!@rename($tmp, $path)) { @unlink($tmp); return false; } }
  return true;
}

function client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0'; }

function rate_read($bucket) { return load_json_file(DATA_DIR . '/rate-' . $bucket . '.json', array()); }

/* File-based rate limiter. Returns true if allowed (and records the hit). */
function rate_ok($bucket, $limit, $windowSec) {
  ensure_dir(DATA_DIR);
  $f = DATA_DIR . '/rate-' . $bucket . '.json';
  $fh = @fopen($f, 'c+');
  if (!$fh) return true;
  flock($fh, LOCK_EX);
  $all = json_decode(stream_get_contents($fh), true);
  if (!is_array($all)) $all = array();
  $now = time(); $ip = hash('sha256', client_ip());
  foreach ($all as $k => $times) {
    $keep = array();
    foreach ((array)$times as $t) if ($t > $now - $windowSec) $keep[] = $t;
    if ($keep) $all[$k] = $keep; else unset($all[$k]);
  }
  $mine = isset($all[$ip]) ? $all[$ip] : array();
  $ok = count($mine) < $limit;
  if ($ok) { $mine[] = $now; $all[$ip] = $mine; }
  ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all));
  flock($fh, LOCK_UN); fclose($fh);
  return $ok;
}

function rate_peek($bucket, $limit, $windowSec) {
  $all = rate_read($bucket); $ip = hash('sha256', client_ip()); $now = time(); $n = 0;
  if (isset($all[$ip])) foreach ((array)$all[$ip] as $t) if ($t > $now - $windowSec) $n++;
  return $n < $limit;
}

function safe_equals($a, $b) {
  if (function_exists('hash_equals')) return hash_equals($a, $b);
  if (strlen($a) !== strlen($b)) return false;
  $r = 0; for ($i = 0; $i < strlen($a); $i++) $r |= ord($a[$i]) ^ ord($b[$i]);
  return $r === 0;
}

function check_password($given) {
  if (!defined('PUBLISH_PASSWORD')) json_out(500, array('ok' => false, 'message' => 'PUBLISH_PASSWORD missing in inc/config.php'));
  if (PUBLISH_PASSWORD === 'CHANGE-ME-to-a-long-secret') json_out(500, array('ok' => false, 'message' => 'Set PUBLISH_PASSWORD in inc/config.php first'));
  if (!rate_peek('login', MAX_LOGIN_FAILS, 900)) json_out(429, array('ok' => false, 'message' => 'Too many wrong attempts. Try again in 15 minutes.'));
  $given = is_string($given) ? trim($given) : '';
  if (!safe_equals(trim((string)PUBLISH_PASSWORD), $given)) {
    rate_ok('login', 1000000, 900);
    usleep(600000);
    json_out(401, array('ok' => false, 'message' => 'Wrong publish password'));
  }
}

function clean_str($v, $max) {
  $v = is_string($v) ? trim($v) : (is_numeric($v) ? (string)$v : '');
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
  if (function_exists('mb_substr')) return mb_substr($v, 0, $max, 'UTF-8');
  return substr($v, 0, $max);
}

/* ---------- enquiry.php ---------- */
/* Public "Get in touch" form handler (PHP 5.6 – 8.3).
   Saves the enquiry privately in /data/enquiries.json and emails the owner. */

require_post();
check_origin();
$in = read_json_body(20000);

if (g($in, 'website', '') !== '') json_out(200, array('ok' => true, 'id' => time() * 1000)); // honeypot

$first = clean_str(g($in, 'first', ''), 80);
$last  = clean_str(g($in, 'last',  ''), 80);
$biz   = clean_str(g($in, 'biz',   ''), 120);
$phone = clean_str(g($in, 'phone', ''), 40);
$email = clean_str(g($in, 'email', ''), 160);
$msg   = clean_str(g($in, 'msg',   ''), 4000);

if ($first === '' || $phone === '' || $msg === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_out(422, array('ok' => false, 'message' => 'Please fill in all required fields'));
}
if (!rate_ok('enquiry', MAX_ENQUIRIES_PER_HOUR, 3600)) {
  json_out(429, array('ok' => false, 'message' => 'Too many enquiries from this connection'));
}

ensure_dir(DATA_DIR);
$id  = (int)round(microtime(true) * 1000);
$rec = array('id' => $id, 'date' => gmdate('c'), 'first' => $first, 'last' => $last, 'biz' => $biz,
             'phone' => $phone, 'email' => $email, 'msg' => $msg, 'status' => 'new');

$file = DATA_DIR . '/enquiries.json';
$fh = @fopen($file, 'c+');
if (!$fh) json_out(500, array('ok' => false, 'message' => 'Could not save enquiry'));
flock($fh, LOCK_EX);
$list = json_decode(stream_get_contents($fh), true);
if (!is_array($list)) $list = array();
array_unshift($list, $rec);
$list = array_slice($list, 0, 2000);
ftruncate($fh, 0); rewind($fh);
fwrite($fh, enc_json($list));
flock($fh, LOCK_UN); fclose($fh);

// Email (best effort — enquiry is already saved)
$to   = defined('NOTIFY_EMAIL') ? NOTIFY_EMAIL : '';
$from = defined('MAIL_FROM') ? MAIL_FROM : '';
if ($to !== '') {
  $name = trim("$first $last");
  $subject = 'New website enquiry - ' . $name . ($biz ? " ($biz)" : '');
  $body = "New enquiry from the Trayde website\n\n"
        . "Name:     $name\nBusiness: " . ($biz ? $biz : '-') . "\nPhone:    $phone\nEmail:    $email\n\n"
        . "Message:\n$msg\n\nReceived: " . date('d M Y H:i') . "\n";
  $headers = array('Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . preg_replace('/[\r\n]/', '', $email));
  if ($from !== '') array_unshift($headers, 'From: Trayde Website <' . $from . '>');
  $params = $from !== '' ? '-f' . $from : '';
  @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), $params);
}

json_out(200, array('ok' => true, 'id' => $id));
