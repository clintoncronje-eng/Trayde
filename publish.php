<?php
/* TRAYDE-V3 · publish.php · self-contained (PHP 5.6 – 8.3). Only needs inc/config.php */
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

/* ---------- publish.php ---------- */
/* Owner dashboard endpoint (PHP 5.6 – 8.3)
   action "publish": writes public site-data.json (+ moves uploaded images to /uploads/)
                     and stores private supplier data / enquiry status in /data/.
   action "pull":    returns server enquiries + private data to the dashboard.
   action "ping":    password check only. */
@ini_set('memory_limit', '512M');
@set_time_limit(120);
$GLOBALS['trayde_saved'] = 0;

require_post();
check_origin();
$in = read_json_body(25 * 1024 * 1024);
check_password(g($in, 'auth'));

$action = g($in, 'action', 'publish');
ensure_dir(DATA_DIR);

if ($action === 'ping') json_out(200, array('ok' => true, 'message' => 'Password OK'));

if ($action === 'pull') {
  json_out(200, array(
    'ok' => true,
    'enquiries' => load_json_file(DATA_DIR . '/enquiries.json', array()),
    'private'   => load_json_file(DATA_DIR . '/private.json', array('listings' => array(), 'enquiries' => array())),
  ));
}

/* --- Single photo upload (dashboard sends photos one at a time) --- */
if ($action === 'image') {
  ensure_dir(UPLOAD_DIR);
  $img = g($in, 'image', '');
  $before = $img;
  trayde_walk($img);
  if (!is_string($img) || $img === '' || $img === $before) json_out(422, array('ok' => false, 'message' => 'Photo rejected — use JPG, PNG or WEBP under 15 MB'));
  json_out(200, array('ok' => true, 'url' => $img));
}

if ($action !== 'publish') json_out(400, array('ok' => false, 'message' => 'Unknown action'));

$data = g($in, 'data');
if (!is_array($data) || !is_array(g($data, 'fullContent'))) json_out(400, array('ok' => false, 'message' => 'Missing content'));

/* --- Convert any remaining embedded base64 images to real files in /uploads --- */
ensure_dir(UPLOAD_DIR);
trayde_walk($data);

function trayde_walk(&$node) {
  static $extMap = array('image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
  if (is_array($node)) { foreach ($node as $k => $v) { trayde_walk($node[$k]); } return; }
  if (!is_string($node) || strncmp($node, 'data:image/', 11) !== 0) return;
  $comma = strpos($node, ',');
  $head = substr($node, 5, $comma - 5);            // e.g. image/jpeg;base64
  $mime = strtolower(strtok($head, ';'));
  if ($comma === false || !isset($extMap[$mime]) || strpos($head, 'base64') === false) { $node = ''; return; }
  $bin = base64_decode(substr($node, $comma + 1));
  if ($bin === false || strlen($bin) < 20 || strlen($bin) > 20 * 1024 * 1024) { $node = ''; return; }
  if (function_exists('getimagesizefromstring') && !@getimagesizefromstring($bin) && $mime !== 'image/webp') { $node = ''; return; }
  $name = substr(sha1($bin), 0, 20) . '.' . $extMap[$mime];
  $path = UPLOAD_DIR . '/' . $name;
  if (!is_file($path)) { @file_put_contents($path, $bin, LOCK_EX); @chmod($path, 0644); $GLOBALS['trayde_saved']++; }
  $node = 'uploads/' . $name;
}

/* --- Strip anything private that slipped through --- */
$privFields = array('purchasedFrom','purchasedContact','purchasedLocation','purchasedPhone','purchasedEmail','purchasePrice');
$data['fullContent']['enquiries'] = array();
if (isset($data['fullContent']['listings']) && is_array($data['fullContent']['listings'])) {
  foreach ($data['fullContent']['listings'] as $i => $l) {
    if (is_array($l)) foreach ($privFields as $f) $data['fullContent']['listings'][$i][$f] = '';
  }
}
$data['publishedAt'] = gmdate('c');

/* --- Backup previous public file (keep last 10) --- */
if (is_file(PUBLIC_JSON)) {
  @copy(PUBLIC_JSON, DATA_DIR . '/backup-' . date('Ymd-His') . '.json');
  $bks = glob(DATA_DIR . '/backup-*.json');
  if (is_array($bks)) { sort($bks); while (count($bks) > 10) @unlink(array_shift($bks)); }
}

if (!save_json_file(PUBLIC_JSON, $data)) json_out(500, array('ok' => false, 'message' => 'Could not write site-data.json — make public_html writable (755)'));

/* --- Private data --- */
$priv = g($in, 'private', array());
$privOut = array('listings' => array(), 'enquiries' => array(), 'updatedAt' => gmdate('c'));
if (is_array(g($priv, 'listings'))) {
  foreach ($priv['listings'] as $pl) {
    if (!is_array($pl)) continue;
    $o = array('name' => clean_str(g($pl, 'name', ''), 200));
    foreach ($privFields as $f) $o[$f] = clean_str(g($pl, $f, ''), 300);
    $privOut['listings'][] = $o;
  }
}
if (is_array(g($priv, 'enquiries'))) {
  foreach ($priv['enquiries'] as $e) {
    if (is_array($e) && isset($e['id'])) $privOut['enquiries'][] = array('id' => $e['id'], 'status' => (g($e, 'status') === 'done') ? 'done' : 'new');
  }
}
save_json_file(DATA_DIR . '/private.json', $privOut);

json_out(200, array('ok' => true, 'message' => 'Published', 'imagesSaved' => $GLOBALS['trayde_saved'], 'publishedAt' => $data['publishedAt']));
