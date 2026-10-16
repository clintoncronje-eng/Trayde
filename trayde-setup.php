<?php
/* TRAYDE SETUP — open https://trayde.co.za/trayde-setup.php
   Sets the publish password and tests publishing. DELETE THIS FILE when done. */
@ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');

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

/* Server-side self-tests: run publish.php & enquiry.php in a subrequest and show the raw reply.
   Catches "empty reply / HTTP 500" hosting problems right here, with the reason. */
function trayde_selftest($file, $body) {
  if (!is_file(__DIR__ . '/' . $file)) return '<b class="bad">missing — upload ' . htmlspecialchars($file) . ' next to index.html</b>';
  $ctx = stream_context_create(array('http' => array(
    'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body,
    'ignore_errors' => true, 'timeout' => 15)));
  $resp = @file_get_contents((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
           . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . $file, false, $ctx);
  $hdrs = $http_response_header ?? array();
  $code = isset($hdrs[0]) ? (int)preg_replace('/\D+(\d+).*/', '$1', $hdrs[0]) : 0;
  $txt = trim(preg_replace('/\s+/', ' ', strip_tags((string)$resp)));
  if ($resp === false || $resp === '') return '<b class="bad">FAILED — HTTP ' . $code . ' with an EMPTY reply. PHP crashed before producing output. Check error_log below.</b>';
  $j = json_decode($resp, true);
  if (is_array($j) && isset($j['ok'])) return ($j['ok'] ? '<b class="ok">' : '<b class="bad">') . 'HTTP ' . $code . ' · JSON reply: ' . htmlspecialchars(json_encode($j)) . '</b>';
  return '<b class="bad">HTTP ' . $code . ' · reply was NOT JSON: ' . htmlspecialchars(substr($txt, 0, 200)) . '</b>';
}
$tPub  = trayde_selftest('publish.php', '{"action":"ping","auth":""}');       // expect 401 Wrong publish password
$tEnq  = trayde_selftest('enquiry.php', '{"first":"Self","phone":"0","email":"x","msg":"test","website":""}'); // expect 422 or 200
$tPing = is_file(__DIR__ . '/ping.php') ? @file_get_contents((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/ping.php') : false;
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
  <div>ping.php → <?php echo $tPing ? htmlspecialchars(substr((string)$tPing, 0, 200)) : '<b class="bad">no reply (missing or crashed)</b>'; ?></div>
  <div>publish.php → <?php echo $tPub; ?></div>
  <div>enquiry.php → <?php echo $tEnq; ?></div>
  <p style="color:#9c9c9c;font-size:13px;margin-top:8px">publish.php should answer <code>HTTP 401 · Wrong publish password</code> here (the test sends an empty password). If any line says EMPTY reply / NOT JSON, that is the exact file crashing — the reason is in error_log (see bottom of this page).</p>
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
.then(function(r){return r.text().then(function(t){o.textContent='HTTP '+r.status+'\n'+(t||'(empty reply)')+(r.status===200?'\n\n✔ WORKING — go to Dashboard → Publish and use this password.':'')})})
.catch(function(e){o.textContent='Network error: '+e.message})};
</script>
<p class="bad"><b>Delete trayde-setup.php from the server when publishing works.</b></p>
</body></html>
