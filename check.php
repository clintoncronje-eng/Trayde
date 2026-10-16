<?php
/* TRAYDE SERVER CHECK — open https://trayde.co.za/check.php in a browser.
   Shows what's wrong (if anything). DELETE THIS FILE once everything is green. */
@ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$cfgFileTop = __DIR__ . '/inc/config.php';
$setupMsg = '';
function cfg_is_placeholder($f) { if (!is_file($f)) return true; $s = file_get_contents($f); return strpos($s, 'CHANGE-ME-to-a-long-secret') !== false || strpos($s, 'PUBLISH_PASSWORD') === false; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['newpw'])) {
  if (!cfg_is_placeholder($cfgFileTop)) { $setupMsg = 'A password is already set. To change it, edit inc/config.php in File Manager.'; }
  else {
    $pw = trim((string)$_POST['newpw']); $em = trim((string)($_POST['notify'] ?? 'adrian@trayde.co.za'));
    if (strlen($pw) < 8) $setupMsg = 'Password must be at least 8 characters.';
    elseif (!preg_match('/^[A-Za-z0-9!@#%^*()_+\-=.,:?]+$/', $pw)) $setupMsg = 'Use only letters, numbers and ! @ # % ^ * ( ) _ + - = . , : ?';
    elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) $setupMsg = 'Notification email is not valid.';
    else {
      if (!is_dir(__DIR__ . '/inc')) @mkdir(__DIR__ . '/inc', 0755, true);
      $host = preg_replace('/:\d+$/', '', preg_replace('/^www\./i', '', $_SERVER['HTTP_HOST'] ?? 'trayde.co.za'));
      $php = "<?php\n/* TRAYDE — SITE CONFIG (written by check.php setup " . date('Y-m-d H:i') . ") */\n"
           . "define('PUBLISH_PASSWORD', " . var_export($pw, true) . ");\n"
           . "define('NOTIFY_EMAIL', " . var_export($em, true) . ");\n"
           . "define('MAIL_FROM', " . var_export('website@' . $host, true) . ");\n";
      $ok = @file_put_contents($cfgFileTop, $php, LOCK_EX) !== false;
      if ($ok && function_exists('opcache_invalidate')) @opcache_invalidate($cfgFileTop, true);
      $setupMsg = $ok ? 'SAVED ✔ — publish password is now set. Use it in Dashboard → Publish.' : 'Could not write inc/config.php — set the inc folder to 755.';
      if ($ok) { header('Location: check.php?saved=1'); exit; }
    }
  }
}
if (isset($_GET['saved'])) $setupMsg = 'SAVED ✔ — publish password is now set. Use it in Dashboard → Publish.';
$rows = array();
function row(&$rows, $label, $ok, $detail) { $rows[] = array($label, $ok, $detail); }

row($rows, 'CHECK VERSION', true, 'V4 · this page runs from: ' . __DIR__);
row($rows, 'PHP version', version_compare(PHP_VERSION, '5.6', '>='), PHP_VERSION . ' (8.1+ recommended — cPanel → MultiPHP Manager)');
row($rows, 'JSON extension', function_exists('json_encode'), function_exists('json_encode') ? 'available' : 'MISSING — enable "json" in cPanel → Select PHP Version → Extensions');
row($rows, 'mbstring extension', function_exists('mb_substr'), function_exists('mb_substr') ? 'available' : 'missing (optional — fallback used)');
row($rows, 'GD / image check', function_exists('getimagesizefromstring'), function_exists('getimagesizefromstring') ? 'available' : 'missing (optional)');

// config.php
$cfgFile = __DIR__ . '/inc/config.php';
$cfgOk = false; $cfgMsg = '';
if (!is_file($cfgFile)) { $cfgMsg = 'inc/config.php NOT FOUND — upload the inc folder'; }
else {
  $src = file_get_contents($cfgFile);
  if (substr($src, 0, 3) === "\xEF\xBB\xBF") $cfgMsg .= 'WARNING: file starts with a BOM. ';
  if (strpos(ltrim($src, "\xEF\xBB\xBF"), '<?php') !== 0) $cfgMsg .= 'WARNING: file must start with <?php on line 1. ';
  ob_start(); $r = @include $cfgFile; $junk = ob_get_clean();
  if ($junk !== '') $cfgMsg .= 'WARNING: config.php prints output (' . strlen($junk) . ' bytes) — remove blank lines/text outside <?php. ';
  if (!defined('PUBLISH_PASSWORD')) $cfgMsg .= 'PUBLISH_PASSWORD not defined (syntax error?). Check quotes: define(\'PUBLISH_PASSWORD\', \'YourPassword\');';
  elseif (PUBLISH_PASSWORD === 'CHANGE-ME-to-a-long-secret') $cfgMsg .= 'Password still the placeholder — change it.';
  else { $cfgOk = ($cfgMsg === ''); $cfgMsg .= 'Password set (' . strlen(PUBLISH_PASSWORD) . ' characters, starts "' . substr(PUBLISH_PASSWORD, 0, 2) . '…")'; }
}
if (is_file($cfgFile)) $cfgMsg .= ' · file: ' . $cfgFile . ' · last changed ' . date('d M Y H:i', filemtime($cfgFile));
row($rows, 'inc/config.php', $cfgOk, $cfgMsg);

// lib.php syntax
$pubSrc = is_file(__DIR__ . '/publish.php') ? file_get_contents(__DIR__ . '/publish.php') : '';
$v3 = strpos($pubSrc, 'TRAYDE-V3') !== false;
row($rows, 'publish.php is latest version (V3)', $v3, $v3 ? 'yes' : 'NO — an older publish.php is on the server. Re-upload publish.php and enquiry.php from the newest download.');
$cfgSrc = is_file($cfgFile) ? file_get_contents($cfgFile) : '';
$curly = preg_match('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', $cfgSrc);
row($rows, 'config.php quotes', !$curly, $curly ? 'Curly quotes found (‘ ’ “ ”) — retype them as straight quotes \' ' : 'OK');

foreach (array('publish.php', 'enquiry.php') as $f) row($rows, $f . ' present', is_file(__DIR__ . '/' . $f), is_file(__DIR__ . '/' . $f) ? 'found' : 'NOT FOUND — upload it next to index.html');

// writable folders
foreach (array('data', 'uploads') as $d) {
  $p = __DIR__ . '/' . $d;
  if (!is_dir($p)) @mkdir($p, 0755, true);
  $w = is_dir($p) && is_writable($p);
  if ($w) { $t = $p . '/.write-test'; $w = @file_put_contents($t, 'ok') !== false; @unlink($t); }
  row($rows, $d . '/ writable', $w, $w ? 'OK' : 'NOT writable — File Manager → right-click folder → Change Permissions → 755');
}
$rootW = is_writable(__DIR__);
row($rows, 'public_html writable (for site-data.json)', $rootW, $rootW ? 'OK' : 'NOT writable — set public_html to 755');

// limits
function to_bytes($v) { $v = trim($v); $n = (float)$v; $u = strtolower(substr($v, -1)); if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024; return $n; }
$pm = ini_get('post_max_size'); $ml = ini_get('memory_limit');
row($rows, 'post_max_size', to_bytes($pm) >= 32 * 1048576, $pm . ' (need 32M+ for photo uploads; set in .user.ini or cPanel → Select PHP Version → Options)');
row($rows, 'memory_limit', $ml === '-1' || to_bytes($ml) >= 128 * 1048576, $ml . ' (need 128M+)');

$sj = __DIR__ . '/site-data.json';
row($rows, 'site-data.json', true, is_file($sj) ? ('exists, last published ' . date('d M Y H:i', filemtime($sj))) : 'not created yet (normal until first successful publish)');

// error_log tails
$logs = array();
foreach (array(__DIR__ . '/error_log', __DIR__ . '/inc/error_log', __DIR__ . '/php_errorlog') as $lf) {
  if (is_file($lf)) { $lines = @file($lf); if ($lines) $logs[basename(dirname($lf)) . '/' . basename($lf)] = implode('', array_slice($lines, -8)); }
}
// privacy check hint
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'yourdomain';
?><!doctype html><html><head><meta charset="utf-8"><title>Trayde server check</title>
<style>body{background:#000;color:#f2f2f2;font:15px/1.6 system-ui,sans-serif;padding:30px;max-width:900px;margin:auto}h1{color:#ffc400;font-size:22px}table{width:100%;border-collapse:collapse}td{border-bottom:1px solid #242424;padding:10px 8px;vertical-align:top}.ok{color:#31d483}.bad{color:#ff6161}code{color:#ffc400}</style></head><body>
<h1>Trayde server check</h1>
<?php if ($setupMsg): ?><p style="padding:12px 14px;border:1px solid #ffc400;border-radius:8px;color:#ffc400"><b><?php echo htmlspecialchars($setupMsg); ?></b></p><?php endif; ?>
<?php if (cfg_is_placeholder($cfgFileTop)): ?>
<form method="post" style="background:#101010;border:1px solid #ffc400;border-radius:10px;padding:18px;margin:16px 0">
<b style="color:#ffc400">First-time setup — set your publish password</b>
<p style="margin:6px 0 12px;color:#9c9c9c;font-size:13px">This writes inc/config.php for you. It only works while the password is still the placeholder.</p>
<input name="newpw" type="text" placeholder="New publish password (8+ chars)" style="padding:10px;background:#000;border:1px solid #333;color:#fff;border-radius:6px;width:280px">
<input name="notify" type="email" value="adrian@trayde.co.za" style="padding:10px;background:#000;border:1px solid #333;color:#fff;border-radius:6px;width:240px">
<button style="padding:10px 18px;background:#ffc400;border:0;font-weight:700;border-radius:6px;cursor:pointer">Save password</button>
</form>
<?php endif; ?>
<table><?php foreach ($rows as $r): ?><tr><td><b><?php echo htmlspecialchars($r[0]); ?></b></td><td class="<?php echo $r[1] ? 'ok' : 'bad'; ?>"><?php echo $r[1] ? '✔' : '✘'; ?></td><td><?php echo htmlspecialchars($r[2]); ?></td></tr><?php endforeach; ?></table>
<h1 style="margin-top:30px">Live publish test</h1>
<p>Type your publish password and press Test. This sends the same tiny request the dashboard sends and shows the server's raw reply.</p>
<p><input id="pw" type="password" placeholder="Publish password" style="padding:10px;background:#111;border:1px solid #333;color:#fff;border-radius:6px;width:280px"> <button id="go" style="padding:10px 18px;background:#ffc400;border:0;font-weight:700;border-radius:6px;cursor:pointer">Test</button></p>
<pre id="out" style="background:#0a0a0a;border:1px solid #242424;padding:14px;white-space:pre-wrap;color:#ffc400;min-height:40px"></pre>
<script>
document.getElementById('go').onclick=function(){var o=document.getElementById('out');o.textContent='Sending…';fetch('publish.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'ping',auth:document.getElementById('pw').value.trim()})}).then(function(r){return r.text().then(function(t){o.textContent='HTTP '+r.status+'\n\n'+(t||'(empty reply — PHP crashed before any output. See error_log below.)')})}).catch(function(e){o.textContent='Network error: '+e.message})};
</script>
<?php if ($logs): foreach ($logs as $n => $t): ?><h1 style="margin-top:30px">Last lines of <?php echo htmlspecialchars($n); ?></h1><pre style="background:#0a0a0a;border:1px solid #242424;padding:14px;white-space:pre-wrap;color:#ff6161;font-size:12px"><?php echo htmlspecialchars($t); ?></pre><?php endforeach; else: ?><p class="ok">No error_log file found.</p><?php endif; ?>
<p>Also open <code>https://<?php echo htmlspecialchars($host); ?>/data/enquiries.json</code> — it must say <b>403 Forbidden</b>.</p>
<p class="bad"><b>Delete check.php when everything is green.</b></p>
</body></html>
