<?php
/* TRAYDE SETUP — open https://trayde.co.za/trayde-setup.php
   Sets the publish password and tests publishing. DELETE THIS FILE when done.
   NOTE: this file deliberately does NOT depend on inc/config.php or any other
   include — if those are broken, this page must still load (no 404 / blank page).
   If you get a 404 here, trayde-setup.php was never uploaded to public_html, or it
   was uploaded into a sub folder (it must sit NEXT TO index.html). */
@ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');

/* Front-controller guard: if an Apache rewrite hands another file's code to this
   script, say so instead of running mismatched endpoint logic. */
if (PHP_SAPI !== 'cli') {
  $treq = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
  if ($treq !== '' && $treq !== 'trayde-setup.php' && $treq !== 'index.php') {
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Server routing problem</h1><p>trayde-setup.php was executed under the name "' . htmlspecialchars($treq) . '" — an Apache rewrite / front controller is intercepting PHP requests. Add <code>RewriteCond %{REQUEST_URI} !\\.php$</code> before any RewriteRule in .htaccess, or ask your host.</p>';
    exit;
  }
}

$dir    = __DIR__;
$incDir = $dir . '/inc';
$cfg    = $incDir . '/config.php';
$msg    = '';

function is_placeholder($f) {
  if (!is_file($f)) return true;
  $s = (string)file_get_contents($f);
  return strpos($s, 'CHANGE-ME-to-a-long-secret') !== false || strpos($s, 'PUBLISH_PASSWORD') === false;
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newpw'])) {
  $pw = trim((string)$_POST['newpw']);
  $em = trim(isset($_POST['notify']) ? (string)$_POST['notify'] : '');
  $force = isset($_POST['oldpw']);
  $allowed = is_placeholder($cfg);
  if (!$allowed && $force) {
    // allow change if the current password is supplied correctly
    $cur = (string)file_get_contents($cfg);
    if (preg_match("/define\\(\\s*'PUBLISH_PASSWORD'\\s*,\\s*'([^']*)'/", $cur, $m) && $m[1] === trim((string)$_POST['oldpw'])) $allowed = true;
    else $msg = 'Current password is wrong.';
  }
  if ($allowed) {
    if (strlen($pw) < 8) $msg = 'Password must be at least 8 characters.';
    elseif (!preg_match('/^[A-Za-z0-9!@#%^*()_+\-=.,:?]+$/', $pw)) $msg = 'Use only letters, numbers and ! @ # % ^ * ( ) _ + - = . , : ?';
    elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) $msg = 'Notification email is not valid.';
    else {
      if (!is_dir($incDir)) @mkdir($incDir, 0755, true);
      $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'trayde.co.za';
      $host = preg_replace('/:\d+$/', '', preg_replace('/^www\./i', '', $host));
      $php = "<?php\n/* TRAYDE — SITE CONFIG (written by trayde-setup.php " . date('Y-m-d H:i') . ") */\n"
           . "define('PUBLISH_PASSWORD', " . var_export($pw, true) . ");\n"
           . "define('NOTIFY_EMAIL', " . var_export($em, true) . ");\n"
           . "define('MAIL_FROM', " . var_export('website@' . $host, true) . ");\n";
      if (@file_put_contents($cfg, $php, LOCK_EX) !== false) {
        if (function_exists('opcache_invalidate')) @opcache_invalidate($cfg, true);
        header('Location: trayde-setup.php?saved=' . time()); exit;
      }
      $msg = 'Could not write ' . $cfg . ' — set the inc folder permissions to 755.';
    }
  }
}
if (isset($_GET['saved'])) $msg = 'SAVED ✔ Publish password is set. Now press "Test publish" below with the same password.';

$ph = is_placeholder($cfg);
$pubSrc = is_file($dir . '/publish.php') ? (string)file_get_contents($dir . '/publish.php') : '';
$pubV3 = strpos($pubSrc, 'TRAYDE-V3') !== false;

/* Self-test strategy: NEVER make an HTTP subrequest (loopback requests are blocked by
   many hosts, which produced the misleading "empty reply" failures). Instead run each
   endpoint directly in a child PHP process using the same binary that serves this page. */
function trayde_http_host() {
  $h = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0.9._\-:]/', '', (string)$_SERVER['HTTP_HOST']) : '';
  return $h !== '' ? $h : 'trayde.co.za';
}
function trayde_php_bin() {
  if (defined('PHP_BINARY') && PHP_BINARY !== '' && strpos(PHP_BINARY, 'php') !== false) return PHP_BINARY;
  $c = @shell_exec('command -v php 2>/dev/null');
  $c = is_string($c) ? trim($c) : '';
  return $c !== '' ? $c : 'php';
}
function trayde_sh($cmd) {
  if (function_exists('exec')) { $out = array(); $rc = -1; @exec($cmd, $out, $rc); return array('rc' => $rc, 'out' => implode("\n", $out)); }
  $o = @shell_exec($cmd . ' 2>&1');
  return $o === null ? null : array('rc' => -1, 'out' => (string)$o);
}
function trayde_run_endpoint($file, $body) {
  if (!is_file(__DIR__ . '/' . $file)) return '<b class="bad">MISSING on server — upload ' . htmlspecialchars($file) . ' into public_html, next to index.html</b>';
  $tmp = @tempnam(sys_get_temp_dir(), 'traydetest');
  if ($tmp === false) return '<b class="bad">could not create a temp test file (/tmp not writable?)</b>';
  @file_put_contents($tmp, $body);
  $host = trayde_http_host();
  $origin = 'https://' . preg_replace('/^www\./i', '', $host);
  $cmd = 'REQUEST_METHOD=POST CONTENT_TYPE=application/json HTTP_HOST=' . escapeshellarg($host)
       . ' HTTP_ORIGIN=' . escapeshellarg($origin)
       . ' REMOTE_ADDR=127.0.0.1 SCRIPT_NAME=' . escapeshellarg('/' . $file)
       . ' ' . escapeshellarg(trayde_php_bin()) . ' -d display_errors=stderr -d error_reporting=E_ALL ' . escapeshellarg(__DIR__ . '/' . $file)
       . ' < ' . escapeshellarg($tmp) . ' 2>&1';
  $r = trayde_sh($cmd);
  @unlink($tmp);
  if ($r === null || ($r['rc'] === 127 && $r['out'] === '')) return '<b class="bad">blocked — this host disabled exec/shell_exec for PHP, so the automatic test cannot run here. Use the manual "Test publish" button below instead.</b>';
  $out = $r['out'];
  /* stdout JSON reply first, then any PHP warnings/fatals (sent to stderr, merged with 2>&1) */
  $pos = strpos($out, '{');
  $jsonPart = $pos === false ? '' : substr($out, $pos);
  $headPart = $pos === false ? '' : substr($out, 0, $pos);
  $dec = json_decode($jsonPart, true);
  $html = '';
  if (is_array($dec) && array_key_exists('ok', $dec)) {
    $html .= ($dec['ok'] ? '<b class="ok">' : '<b class="bad">') . 'Reply: ' . htmlspecialchars(json_encode($dec)) . '</b>';
  } else {
    $html .= '<b class="bad">No valid JSON reply (exit code ' . (int)$r['rc'] . '). Raw output: ' . htmlspecialchars(trim(substr($out, 0, 300))) . '</b>';
  }
  $headTrim = trim($headPart);
  if ($headTrim !== '') $html .= '<pre style="color:#ff6161;font-size:12px">' . htmlspecialchars(substr($headTrim, 0, 600)) . '</pre>';
  return $html;
}
$tCfg  = is_file($cfg) ? '<b class="ok">inc/config.php exists (' . strlen((string)file_get_contents($cfg)) . ' bytes)</b>' : '<b class="bad">inc/config.php MISSING — the inc folder was never uploaded (or it went into a sub folder). publish.php cannot run without it.</b>';
$tPub  = trayde_run_endpoint('publish.php', '{"action":"ping","auth":""}');       // expect: Wrong publish password
$tEnq  = trayde_run_endpoint('enquiry.php', '{"first":"Self","phone":"0811234567","email":"self@test.co.za","msg":"server self-test","website":""}'); // expect validation reply
$tPing = is_file(__DIR__ . '/ping.php') ? (string)@file_get_contents('file://' . __DIR__ . '/ping.php') : false;
?><!doctype html><html><head><meta charset="utf-8"><title>Trayde setup</title>
<style>body{background:#000;color:#f2f2f2;font:15px/1.6 system-ui,sans-serif;padding:30px;max-width:860px;margin:auto}h1{color:#ffc400;font-size:22px;margin:26px 0 10px}.box{background:#101010;border:1px solid #242424;border-radius:10px;padding:18px;margin:14px 0}.y{border-color:#ffc400}.ok{color:#31d483}.bad{color:#ff6161}input{padding:10px;background:#000;border:1px solid #333;color:#fff;border-radius:6px;margin:4px 6px 4px 0;width:260px}button{padding:10px 18px;background:#ffc400;border:0;font-weight:700;border-radius:6px;cursor:pointer}code{color:#ffc400;word-break:break-all}pre{background:#0a0a0a;border:1px solid #242424;padding:14px;white-space:pre-wrap;color:#ffc400}</style></head><body>
<h1>Trayde setup</h1>
<div class="box">
  <div>This page runs from: <code><?php echo htmlspecialchars($dir); ?></code></div>
  <div>Config file: <code><?php echo htmlspecialchars($cfg); ?></code> — <?php echo is_file($cfg) ? 'exists, changed ' . date('d M Y H:i', filemtime($cfg)) : '<span class="bad">missing</span>'; ?></div>
  <div>Publish password: <?php echo $ph ? '<b class="bad">NOT SET (placeholder)</b>' : '<b class="ok">SET ✔</b>'; ?></div>
  <div>publish.php: <?php echo $pubSrc === '' ? '<b class="bad">missing in this folder</b>' : ($pubV3 ? '<b class="ok">latest (V3) ✔</b>' : '<b class="bad">OLD version — re-upload publish.php</b>'); ?></div>
  <div>PHP: <?php echo PHP_VERSION; ?></div>
</div>

<h1>0 · Automatic endpoint test</h1>
<div class="box">
  <div>inc/config.php → <?php echo $tCfg; ?></div>
  <div>ping.php file → <?php echo $tPing ? htmlspecialchars(substr((string)$tPing, 0, 200)) : '<b class="bad">missing (not critical — publish/enquiry do not need it)</b>'; ?></div>
  <div>publish.php → <?php echo $tPub; ?></div>
  <div>enquiry.php → <?php echo $tEnq; ?></div>
  <p style="color:#9c9c9c;font-size:13px;margin-top:8px">These tests run each PHP file directly on the server (no web request), so they work even when the host blocks loopback requests. publish.php should answer <code>"Wrong publish password"</code> here (the test sends an empty password). Anything else — "No valid JSON reply", a red error block, or "MISSING" — names the exact broken file and line.</p>
</div>
<?php if ($msg): ?><div class="box y"><b style="color:#ffc400"><?php echo htmlspecialchars($msg); ?></b></div><?php endif; ?>

<h1>1 · Set publish password</h1>
<form method="post" class="box y">
  <?php if (!$ph): ?><input name="oldpw" type="password" placeholder="Current password (to change it)"><br><?php endif; ?>
  <input name="newpw" type="text" placeholder="New publish password (8+ chars)">
  <input name="notify" type="email" value="adrian@trayde.co.za"><br>
  <button>Save password</button>
</form>

<h1>2 · Test publish</h1>
<div class="box">
  <input id="pw" type="password" placeholder="Publish password"> <button id="go" type="button">Test publish</button>
  <pre id="out">Result appears here.</pre>
</div>
<script>
document.getElementById('go').onclick=function(){var o=document.getElementById('out');o.textContent='Sending…';
fetch('publish.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'ping',auth:document.getElementById('pw').value.trim()})})
.then(function(r){return r.text().then(function(t){if(r.status===404){o.textContent='HTTP 404 — publish.php is NOT in public_html on this server (that also explains any 404 problems). Upload publish.php, enquiry.php, check.php, inc/, data/ and uploads/ into public_html, next to index.html.';return}o.textContent='HTTP '+r.status+'\n'+(t||'(empty reply)')+(r.status===200?'\n\n✔ WORKING — go to Dashboard → Publish and use this password.':(r.status===401?'\n\n✔ PHP IS FINE — 401 means the typed password does not match inc/config.php. Set it in section 1 above.':''))})})
.catch(function(e){o.textContent='Network error: '+e.message})};
</script>
<p class="bad"><b>Delete trayde-setup.php from the server when publishing works.</b></p>
</body></html>
