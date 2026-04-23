<?php
// ══════════════════════════════════════════════════════════════
//  X-Connect VPN Admin Panel v1.0
//  Manages VLESS keys via 3x-ui API
//  Auto-transforms all keys → CDN:443 format
// ══════════════════════════════════════════════════════════════

session_start();

define('CONF', '/root/xconnect.conf');
define('AUTH', '/root/xconnect_panel.auth');

// ─── Load config files ────────────────────────────────────────
function rconf($f) {
    $c = [];
    if (!file_exists($f)) return $c;
    foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (!$l || $l[0] === '#') continue;
        $p = explode('=', $l, 2);
        if (count($p) === 2) $c[trim($p[0])] = trim($p[1]);
    }
    return $c;
}

$C = rconf(CONF);
$A = rconf(AUTH);
if (empty($A['ADMIN_USER'])) $A = ['ADMIN_USER' => 'admin', 'ADMIN_PASS' => 'xconnect'];

// ─── 3x-ui API ────────────────────────────────────────────────
function xui_login() {
    global $C;
    $ch = curl_init('http://127.0.0.1:' . ($C['PANEL_PORT'] ?? 2053) . '/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'username' => $C['PANEL_USER'] ?? 'admin',
            'password' => $C['PANEL_PASS'] ?? ''
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $r  = curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', substr($r, 0, $hs), $m);
    $ck = implode('; ', $m[1] ?? []);
    $_SESSION['xui_ck'] = $ck;
    return $ck;
}

function xui($path, $method = 'GET', $body = null) {
    global $C;
    if (empty($_SESSION['xui_ck'])) xui_login();
    $ch = curl_init('http://127.0.0.1:' . ($C['PANEL_PORT'] ?? 2053) . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE         => $_SESSION['xui_ck'] ?? '',
        CURLOPT_TIMEOUT        => 12,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body ?? (object)[]);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode($r ?: '{}', true) ?? [];
}

// ─── Transform key → CDN:443 ──────────────────────────────────
function mk($uuid, $label) {
    global $C;
    $h = $C['CDN_DOMAIN'] ?? '';
    $p = rawurlencode($C['WS_PATH'] ?? '/');
    $t = rawurlencode($label);
    return "vless://{$uuid}@{$h}:443?type=ws&path={$p}&security=tls&sni={$h}&host={$h}&fp=chrome&alpn=http%2F1.1#{$t}";
}

// ─── AJAX handler ─────────────────────────────────────────────
if (!empty($_POST['_x'])) {
    header('Content-Type: application/json');
    if (empty($_SESSION['xc'])) { echo '{"ok":false,"msg":"Auth required"}'; exit; }
    $act = $_POST['act'] ?? '';

    // GET ALL KEYS
    if ($act === 'keys') {
        $iid = $C['INBOUND_ID'] ?? 1;
        $r   = xui('/xui/API/inbounds/get/' . $iid);
        if (empty($r['obj'])) {
            // Cookie may have expired — re-login and retry once
            xui_login();
            $r = xui('/xui/API/inbounds/get/' . $iid);
        }
        if (empty($r['obj'])) {
            echo json_encode(['ok'=>false,'msg'=>'Cannot reach 3x-ui. Check panel port & credentials.']);
            exit;
        }
        $clients = json_decode($r['obj']['settings'] ?? '{}', true)['clients'] ?? [];
        $out = [];
        foreach ($clients as $c) {
            $e  = $c['email'] ?? '';
            $st = $e ? (xui('/xui/API/inbounds/getClientTraffics/' . urlencode($e))['obj'] ?? []) : [];
            $out[] = [
                'uuid'   => $c['id'],
                'name'   => $e,
                'expiry' => $c['expiryTime'] ?? 0,
                'limit'  => $c['totalGB'] ?? 0,
                'up'     => $st['up'] ?? 0,
                'down'   => $st['down'] ?? 0,
                'enable' => $c['enable'] ?? true,
                'key'    => mk($c['id'], $e)
            ];
        }
        echo json_encode(['ok'=>true,'clients'=>$out,'cdn'=>($C['CDN_DOMAIN']??'')]);
        exit;
    }

    // CREATE KEY
    if ($act === 'create') {
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($_POST['name'] ?? 'user'));
        $days = max(0, (int)($_POST['days'] ?? 30));
        $gb   = max(0, (int)($_POST['gb'] ?? 0));
        $iid  = (int)($C['INBOUND_ID'] ?? 1);

        // Generate UUID
        $uuid = trim(shell_exec('uuidgen 2>/dev/null') ?: '');
        if (!$uuid) {
            $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff), mt_rand(0,0xffff),
                mt_rand(0,0xffff),
                mt_rand(0,0x0fff)|0x4000,
                mt_rand(0,0x3fff)|0x8000,
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
        }
        $uuid = trim($uuid);

        $payload = json_encode([
            'id' => $iid,
            'settings' => json_encode(['clients' => [[
                'id'         => $uuid,
                'flow'       => '',
                'email'      => $name,
                'limitIp'    => 0,
                'totalGB'    => $gb > 0 ? $gb * 1073741824 : 0,
                'expiryTime' => $days > 0 ? (time() + $days * 86400) * 1000 : 0,
                'enable'     => true,
                'tgId'       => '',
                'subId'      => ''
            ]]])
        ]);

        $r = xui('/xui/API/inbounds/addClient', 'POST', $payload);
        if ($r['success'] ?? false) {
            echo json_encode(['ok'=>true, 'key'=>mk($uuid,$name), 'uuid'=>$uuid, 'name'=>$name]);
        } else {
            echo json_encode(['ok'=>false, 'msg'=>$r['msg'] ?? 'Failed to add client. Check inbound ID.']);
        }
        exit;
    }

    // DELETE KEY
    if ($act === 'del') {
        $iid  = (int)($C['INBOUND_ID'] ?? 1);
        $uuid = $_POST['uuid'] ?? '';
        if (!$uuid) { echo json_encode(['ok'=>false,'msg'=>'Missing UUID']); exit; }
        $r = xui('/xui/API/inbounds/' . $iid . '/delClient/' . $uuid, 'POST');
        echo json_encode(['ok'=> ($r['success'] ?? false), 'msg'=> ($r['msg'] ?? '')]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

// ─── Login / Logout ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_login'])) {
    if ($_POST['u'] === $A['ADMIN_USER'] && $_POST['p'] === $A['ADMIN_PASS']) {
        $_SESSION['xc'] = true;
        header('Location: /'); exit;
    }
    $err = 'Access denied.';
}
if (isset($_GET['out'])) { session_destroy(); header('Location: /'); exit; }

$authed = !empty($_SESSION['xc']);
$cdn    = $C['CDN_DOMAIN'] ?? 'Not configured';
$iid    = $C['INBOUND_ID'] ?? '1';
$pver   = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>X-Connect Panel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
:root {
  --bg:     #07070c;
  --bg2:    #0c0c14;
  --bg3:    #111118;
  --bg4:    #161620;
  --bd:     #1c1c2a;
  --bd2:    #242434;
  --g:      #3bff94;
  --g2:     rgba(59,255,148,.1);
  --g3:     rgba(59,255,148,.18);
  --cy:     #00ccff;
  --cy2:    rgba(0,204,255,.1);
  --rd:     #ff3d5a;
  --rd2:    rgba(255,61,90,.1);
  --yw:     #ffb020;
  --yw2:    rgba(255,176,32,.1);
  --tx:     #aab4c8;
  --txb:    #dde2f0;
  --txd:    #50586a;
  --txm:    #7a8494;
  --r:      6px;
  --r2:     10px;
  --r3:     14px;
  --sh:     0 4px 24px rgba(0,0,0,.4);
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: 'Outfit', sans-serif;
  background: var(--bg);
  color: var(--tx);
  min-height: 100vh;
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
}
a { text-decoration: none; }

/* ════════════ SCROLLBAR ════════════ */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--bd2); border-radius: 3px; }

/* ════════════ LOGIN ════════════ */
.lw {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background:
    radial-gradient(ellipse 700px 500px at 50% -10%, rgba(59,255,148,.06) 0%, transparent 65%),
    radial-gradient(ellipse 400px 300px at 80% 90%, rgba(0,204,255,.04) 0%, transparent 60%),
    var(--bg);
}
.lb {
  width: 100%;
  max-width: 400px;
  background: var(--bg2);
  border: 1px solid var(--bd2);
  border-radius: var(--r3);
  padding: 44px 40px;
  box-shadow: var(--sh), 0 0 60px rgba(59,255,148,.04);
  animation: fadeUp .4s ease both;
}
@keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

.ll { text-align: center; margin-bottom: 36px; }
.lmark {
  display: inline-flex;
  width: 56px; height: 56px;
  align-items: center;
  justify-content: center;
  background: var(--g2);
  border: 1px solid rgba(59,255,148,.35);
  border-radius: 15px;
  font-size: 24px;
  margin-bottom: 16px;
  position: relative;
}
.lmark::after {
  content: '';
  position: absolute;
  inset: -1px;
  border-radius: 15px;
  background: linear-gradient(135deg, rgba(59,255,148,.2), transparent);
  pointer-events: none;
}
.ll h1 { font-size: 22px; font-weight: 700; color: var(--txb); letter-spacing: -.4px; }
.ll p  { font-size: 12px; color: var(--txd); margin-top: 5px; font-family: 'DM Mono', monospace; }

.fld { margin-bottom: 18px; }
.fld label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: var(--txm);
  text-transform: uppercase;
  letter-spacing: .1em;
  margin-bottom: 7px;
}
.fld input {
  width: 100%;
  background: var(--bg3);
  border: 1px solid var(--bd);
  border-radius: var(--r);
  color: var(--txb);
  padding: 11px 14px;
  font-size: 14px;
  font-family: 'Outfit', sans-serif;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.fld input:focus { border-color: rgba(59,255,148,.5); box-shadow: 0 0 0 3px rgba(59,255,148,.08); }
.fld input::placeholder { color: var(--txd); }

.lbtn {
  width: 100%;
  padding: 13px;
  background: var(--g);
  color: #040810;
  border: none;
  border-radius: var(--r);
  font-size: 14px;
  font-weight: 700;
  font-family: 'Outfit', sans-serif;
  cursor: pointer;
  transition: filter .15s, transform .1s;
  letter-spacing: .02em;
}
.lbtn:hover { filter: brightness(1.08); }
.lbtn:active { transform: scale(.99); }

.lerr {
  background: var(--rd2);
  border: 1px solid rgba(255,61,90,.25);
  color: var(--rd);
  border-radius: var(--r);
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 18px;
  text-align: center;
}

/* ════════════ LAYOUT ════════════ */
.hd {
  background: var(--bg2);
  border-bottom: 1px solid var(--bd);
  height: 58px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 28px;
  position: sticky;
  top: 0;
  z-index: 100;
  backdrop-filter: blur(8px);
}
.hl {
  display: flex;
  align-items: center;
  gap: 10px;
}
.hm {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px; height: 32px;
  background: var(--g2);
  border: 1px solid rgba(59,255,148,.3);
  border-radius: 9px;
  font-size: 16px;
}
.hn { font-size: 15px; font-weight: 700; color: var(--txb); letter-spacing: -.2px; }
.nv { font-size: 11px; color: var(--txd); font-family: 'DM Mono', monospace; margin-left: 2px; }

.hr { display: flex; align-items: center; gap: 10px; }
.cbadge {
  display: flex;
  align-items: center;
  gap: 7px;
  background: var(--bg3);
  border: 1px solid var(--bd2);
  border-radius: 20px;
  padding: 5px 13px 5px 9px;
  font-size: 11px;
  font-family: 'DM Mono', monospace;
  color: var(--txm);
}
.dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--g);
  box-shadow: 0 0 8px var(--g);
  flex-shrink: 0;
  animation: blink 2.4s ease infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

.main { padding: 28px; max-width: 1160px; margin: 0 auto; }

/* ════════════ STATS ════════════ */
.stats {
  display: grid;
  grid-template-columns: repeat(4,1fr);
  gap: 14px;
  margin-bottom: 24px;
}
@media(max-width:800px) { .stats { grid-template-columns: 1fr 1fr; } }

.sc {
  background: var(--bg2);
  border: 1px solid var(--bd);
  border-radius: var(--r2);
  padding: 20px 22px;
  position: relative;
  overflow: hidden;
  transition: border-color .2s;
}
.sc:hover { border-color: var(--bd2); }
.sc::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  border-radius: 2px 2px 0 0;
}
.sc.cg::before { background: linear-gradient(90deg,var(--g),transparent); }
.sc.cc::before { background: linear-gradient(90deg,var(--cy),transparent); }
.sc.cy::before { background: linear-gradient(90deg,var(--yw),transparent); }
.sc.cr::before { background: linear-gradient(90deg,var(--rd),transparent); }

.slb {
  font-size: 10px;
  font-weight: 600;
  color: var(--txd);
  text-transform: uppercase;
  letter-spacing: .1em;
  margin-bottom: 10px;
}
.sv {
  font-size: 32px;
  font-weight: 800;
  color: var(--txb);
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.ss { font-size: 11px; color: var(--txd); margin-top: 5px; font-family: 'DM Mono', monospace; }

/* ════════════ BUTTONS ════════════ */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 9px 16px;
  border-radius: var(--r);
  font-size: 12px;
  font-weight: 600;
  font-family: 'Outfit', sans-serif;
  cursor: pointer;
  border: none;
  transition: all .15s;
  letter-spacing: .02em;
  white-space: nowrap;
}
.btn:active { transform: scale(.97); }
.btn:disabled { opacity: .5; cursor: not-allowed; transform: none !important; }

.bg  { background: var(--g);  color: #050910; }
.bg:hover { filter: brightness(1.1); }
.bgr { background: transparent; color: var(--tx); border: 1px solid var(--bd2); }
.bgr:hover { background: var(--bg3); color: var(--txb); }
.bcy { background: var(--cy2); color: var(--cy); border: 1px solid rgba(0,204,255,.22); }
.bcy:hover { background: rgba(0,204,255,.18); }
.brd { background: var(--rd2); color: var(--rd); border: 1px solid rgba(255,61,90,.22); }
.brd:hover { background: rgba(255,61,90,.18); }
.byw { background: var(--yw2); color: var(--yw); border: 1px solid rgba(255,176,32,.22); }
.byw:hover { background: rgba(255,176,32,.18); }
.bsm { padding: 6px 11px; font-size: 11px; }

/* ════════════ PANEL ════════════ */
.panel {
  background: var(--bg2);
  border: 1px solid var(--bd);
  border-radius: var(--r2);
  overflow: hidden;
}
.ph {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1px solid var(--bd);
  flex-wrap: wrap;
  gap: 12px;
}
.pt { font-size: 14px; font-weight: 700; color: var(--txb); }
.ps { font-size: 11px; color: var(--txd); margin-top: 3px; font-family: 'DM Mono', monospace; }

/* ════════════ TABLE ════════════ */
.tw { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 11px 18px;
  font-size: 10px;
  font-weight: 600;
  color: var(--txd);
  text-transform: uppercase;
  letter-spacing: .1em;
  text-align: left;
  border-bottom: 1px solid var(--bd);
  white-space: nowrap;
  background: var(--bg3);
}
tbody tr { border-bottom: 1px solid var(--bd); transition: background .1s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: rgba(255,255,255,.02); }
tbody td { padding: 13px 18px; vertical-align: middle; }

.tname { font-weight: 600; color: var(--txb); font-size: 13px; font-family: 'DM Mono', monospace; }
.tuuid { font-size: 10px; color: var(--txd); margin-top: 3px; font-family: 'DM Mono', monospace; }

.bdg {
  display: inline-block;
  padding: 3px 9px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .06em;
  border: 1px solid;
}
.bg-g  { background: var(--g2);  color: var(--g);  border-color: rgba(59,255,148,.2); }
.bg-r  { background: var(--rd2); color: var(--rd);  border-color: rgba(255,61,90,.2); }
.bg-y  { background: var(--yw2); color: var(--yw);  border-color: rgba(255,176,32,.2); }
.bg-d  { background: var(--bg3); color: var(--txm);  border-color: var(--bd2); }

.pw { display: flex; align-items: center; gap: 8px; min-width: 120px; }
.pb {
  flex: 1;
  height: 3px;
  background: var(--bg4);
  border-radius: 2px;
  overflow: hidden;
}
.pf {
  height: 100%;
  border-radius: 2px;
  background: var(--g);
  transition: width .4s;
}
.pf.pw-y { background: var(--yw); }
.pf.pw-r { background: var(--rd); }
.pt2 { font-size: 10px; font-family: 'DM Mono', monospace; color: var(--txd); white-space: nowrap; }

.acts { display: flex; gap: 5px; align-items: center; }

/* ════════════ EMPTY / LOADING ════════════ */
.empty {
  padding: 64px 20px;
  text-align: center;
  color: var(--txd);
}
.empty .ei { font-size: 40px; margin-bottom: 14px; opacity: .6; }
.empty h3  { font-size: 15px; color: var(--txm); margin-bottom: 6px; }
.empty p   { font-size: 12px; }

.loading {
  padding: 44px;
  text-align: center;
  color: var(--txd);
  font-family: 'DM Mono', monospace;
  font-size: 12px;
}
.sp {
  display: inline-block;
  width: 14px; height: 14px;
  border: 2px solid var(--bd2);
  border-top-color: var(--g);
  border-radius: 50%;
  animation: spin .55s linear infinite;
  vertical-align: middle;
  margin-right: 8px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ════════════ MODAL ════════════ */
.mbg {
  position: fixed; inset: 0;
  background: rgba(5,5,12,.8);
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 900;
  padding: 20px;
  opacity: 0;
  pointer-events: none;
  transition: opacity .2s;
}
.mbg.open { opacity: 1; pointer-events: all; }
.md {
  background: var(--bg2);
  border: 1px solid var(--bd2);
  border-radius: var(--r3);
  width: 100%;
  max-width: 460px;
  box-shadow: 0 20px 60px rgba(0,0,0,.6);
  transform: translateY(10px) scale(.99);
  transition: transform .2s;
}
.mbg.open .md { transform: translateY(0) scale(1); }
.mh {
  padding: 22px 26px 18px;
  border-bottom: 1px solid var(--bd);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.mh h2 { font-size: 15px; font-weight: 700; color: var(--txb); }
.mx {
  width: 28px; height: 28px;
  border-radius: 7px;
  background: var(--bg3);
  border: 1px solid var(--bd);
  color: var(--txm);
  font-size: 17px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all .1s;
  line-height: 1;
  padding-bottom: 1px;
}
.mx:hover { color: var(--txb); border-color: var(--bd2); }
.mb  { padding: 22px 26px; }
.mf  { padding: 16px 26px; border-top: 1px solid var(--bd); display: flex; gap: 8px; justify-content: flex-end; }

.fr { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.mfld { margin-bottom: 16px; }
.mfld label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: var(--txm);
  text-transform: uppercase;
  letter-spacing: .1em;
  margin-bottom: 7px;
}
.mfld input {
  width: 100%;
  background: var(--bg3);
  border: 1px solid var(--bd);
  border-radius: var(--r);
  color: var(--txb);
  padding: 10px 14px;
  font-size: 14px;
  font-family: 'Outfit', sans-serif;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.mfld input:focus { border-color: rgba(59,255,148,.45); box-shadow: 0 0 0 3px rgba(59,255,148,.07); }
.mfld .hint { font-size: 10px; color: var(--txd); margin-top: 5px; font-family: 'DM Mono', monospace; }

/* ════════════ KEY BOX ════════════ */
.kb {
  background: var(--bg);
  border: 1px solid var(--bd2);
  border-radius: var(--r);
  padding: 14px 46px 14px 14px;
  font-family: 'DM Mono', monospace;
  font-size: 10.5px;
  color: var(--cy);
  word-break: break-all;
  line-height: 1.65;
  margin: 14px 0;
  position: relative;
}
.kcbtn {
  position: absolute;
  top: 10px; right: 10px;
  background: var(--bg3);
  border: 1px solid var(--bd2);
  border-radius: 5px;
  color: var(--txd);
  font-size: 10px;
  padding: 3px 8px;
  cursor: pointer;
  font-family: 'Outfit', sans-serif;
  font-weight: 600;
  transition: all .15s;
}
.kcbtn:hover { color: var(--txb); }
.kcbtn.ok { color: var(--g); border-color: rgba(59,255,148,.3); }

#qrc { display: flex; justify-content: center; padding: 6px 0 2px; }
#qrc canvas, #qrc img { border-radius: 8px; border: 4px solid #fff; }

/* ════════════ TOAST ════════════ */
.toast {
  position: fixed;
  bottom: 24px; right: 24px;
  background: var(--bg3);
  border: 1px solid var(--bd2);
  border-radius: 9px;
  padding: 12px 20px;
  font-size: 13px;
  color: var(--txb);
  display: flex;
  align-items: center;
  gap: 9px;
  z-index: 9999;
  transform: translateY(10px);
  opacity: 0;
  transition: all .22s;
  pointer-events: none;
  max-width: 320px;
  box-shadow: var(--sh);
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.tok  { border-left: 3px solid var(--g); }
.toast.terr { border-left: 3px solid var(--rd); }

.merr {
  background: var(--rd2);
  border: 1px solid rgba(255,61,90,.25);
  color: var(--rd);
  border-radius: var(--r);
  padding: 10px 14px;
  font-size: 12px;
  margin-top: 4px;
  display: none;
}

/* ════════════ RESPONSIVE ════════════ */
@media(max-width:600px) {
  .main { padding: 16px; }
  .ph { padding: 14px 16px; }
  tbody td { padding: 11px 12px; }
  thead th { padding: 9px 12px; }
  .hd { padding: 0 16px; }
}
</style>
</head>
<body>

<?php if (!$authed): /* ═══ LOGIN PAGE ═══ */ ?>

<div class="lw">
  <div class="lb">
    <div class="ll">
      <div class="lmark">⚡</div>
      <h1>X-Connect</h1>
      <p>VPN Admin Panel v<?= $pver ?></p>
    </div>
    <?php if (!empty($err)): ?>
      <div class="lerr"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="_login" value="1">
      <div class="fld">
        <label>Username</label>
        <input type="text" name="u" autocomplete="username" autofocus placeholder="Enter username">
      </div>
      <div class="fld">
        <label>Password</label>
        <input type="password" name="p" autocomplete="current-password" placeholder="Enter password">
      </div>
      <button type="submit" class="lbtn">Sign In →</button>
    </form>
  </div>
</div>

<?php else: /* ═══ DASHBOARD ═══ */ ?>

<header class="hd">
  <div class="hl">
    <div class="hm">⚡</div>
    <div>
      <span class="hn">X-Connect Panel</span>
      <span class="nv">v<?= $pver ?></span>
    </div>
  </div>
  <div class="hr">
    <div class="cbadge">
      <span class="dot"></span>
      <?= htmlspecialchars($cdn) ?>
    </div>
    <a href="/?out=1" class="btn bgr bsm">Sign Out</a>
  </div>
</header>

<main class="main">

  <!-- Stats row -->
  <div class="stats">
    <div class="sc cg">
      <div class="slb">Total Keys</div>
      <div class="sv" id="s0">–</div>
      <div class="ss">inbound <?= htmlspecialchars($iid) ?></div>
    </div>
    <div class="sc cg">
      <div class="slb">Active</div>
      <div class="sv" id="s1">–</div>
      <div class="ss">not expired</div>
    </div>
    <div class="sc cy">
      <div class="slb">Expiring Soon</div>
      <div class="sv" id="s2">–</div>
      <div class="ss">within 7 days</div>
    </div>
    <div class="sc cr">
      <div class="slb">Expired</div>
      <div class="sv" id="s3">–</div>
      <div class="ss">need renewal</div>
    </div>
  </div>

  <!-- Keys panel -->
  <div class="panel">
    <div class="ph">
      <div>
        <div class="pt">Access Keys</div>
        <div class="ps">CDN → <?= htmlspecialchars($cdn) ?>:443 · ws · tls</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn bgr bsm" onclick="loadKeys()">↻ Refresh</button>
        <button class="btn bg" onclick="openCreate()">+ New Key</button>
      </div>
    </div>
    <div id="kw">
      <div class="loading"><span class="sp"></span>Loading keys...</div>
    </div>
  </div>

</main>

<!-- ═══ Create Key Modal ═══ -->
<div class="mbg" id="mc">
  <div class="md">
    <div class="mh">
      <h2>Create New Key</h2>
      <button class="mx" onclick="closeM('mc')">×</button>
    </div>
    <div class="mb">
      <div class="mfld">
        <label>Client Label</label>
        <input type="text" id="fn" placeholder="e.g. John_30d or Company_VIP">
      </div>
      <div class="fr">
        <div class="mfld">
          <label>Expiry (days)</label>
          <input type="number" id="fd" value="30" min="0">
          <div class="hint">0 = never expires</div>
        </div>
        <div class="mfld">
          <label>Data Limit (GB)</label>
          <input type="number" id="fg" value="0" min="0">
          <div class="hint">0 = unlimited</div>
        </div>
      </div>
      <div class="merr" id="cerr"></div>
    </div>
    <div class="mf">
      <button class="btn bgr" onclick="closeM('mc')">Cancel</button>
      <button class="btn bg" id="bcr" onclick="doCreate()" style="min-width:130px">Generate Key</button>
    </div>
  </div>
</div>

<!-- ═══ Key Result Modal ═══ -->
<div class="mbg" id="mr">
  <div class="md">
    <div class="mh">
      <h2 id="mrt">✓ Key Created</h2>
      <button class="mx" onclick="closeM('mr')">×</button>
    </div>
    <div class="mb">
      <div style="font-size:12px;color:var(--txd);margin-bottom:2px">
        Client: <strong id="rn" style="color:var(--txb);font-family:'DM Mono',monospace"></strong>
      </div>
      <div class="kb">
        <button class="kcbtn" id="kc" onclick="copyRes()">copy</button>
        <span id="rk"></span>
      </div>
      <div id="qrc"></div>
    </div>
    <div class="mf">
      <button class="btn bgr" onclick="closeM('mr')">Close</button>
      <button class="btn bcy" onclick="copyRes()">Copy Key</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="t"></div>

<script>
// ─── API helper ───────────────────────────────────────────────
const X = data => fetch('/', {
  method: 'POST',
  headers: {'Content-Type':'application/x-www-form-urlencoded'},
  body: new URLSearchParams({_x:'1',...data}).toString()
}).then(r=>r.json()).catch(()=>({ok:false,msg:'Network error'}));

// ─── Toast ────────────────────────────────────────────────────
let tTimer;
function toast(msg, ok=true) {
  const t = document.getElementById('t');
  t.textContent = msg;
  t.className = 'toast show ' + (ok?'tok':'terr');
  clearTimeout(tTimer);
  tTimer = setTimeout(()=>t.classList.remove('show'), 3000);
}

// ─── Formatters ───────────────────────────────────────────────
function hb(b) {
  if (!b || b<0) return '0 B';
  const u=['B','KB','MB','GB','TB']; let i=0;
  while(b>=1024&&i<u.length-1){b/=1024;i++;}
  return b.toFixed(1)+' '+u[i];
}

function fexp(ms) {
  if (!ms || ms===0)
    return '<span class="bdg bg-d">No Expiry</span>';
  const diff = ms - Date.now();
  const days = Math.floor(diff/86400000);
  if (diff < 0)
    return '<span class="bdg bg-r">Expired</span>';
  if (days < 3)
    return '<span class="bdg bg-r">'+days+'d left</span>';
  if (days < 7)
    return '<span class="bdg bg-y">'+days+'d left</span>';
  const d = new Date(ms);
  return '<span style="font-size:11.5px;font-family:\'DM Mono\',monospace;color:var(--tx)">'+
    d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+'</span>';
}

function fdata(up,dn,lim) {
  const used=up+dn;
  if (!lim) return '<span style="font-size:11px;font-family:\'DM Mono\',monospace;color:var(--txd)">'+hb(used)+'</span>';
  const pct=Math.min(100,(used/lim)*100);
  const cls=pct>90?'pw-r':pct>70?'pw-y':'';
  return '<div class="pw">'+
    '<div class="pb"><div class="pf '+cls+'" style="width:'+pct.toFixed(1)+'%"></div></div>'+
    '<span class="pt2">'+hb(used)+'/'+hb(lim)+'</span></div>';
}

function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c])}
function escJ(s){return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"')}

// ─── Load keys ────────────────────────────────────────────────
async function loadKeys() {
  const w = document.getElementById('kw');
  w.innerHTML = '<div class="loading"><span class="sp"></span>Loading keys...</div>';

  const r = await X({act:'keys'});

  if (!r.ok) {
    w.innerHTML = '<div class="empty"><div class="ei">⚠️</div><h3>Cannot connect to 3x-ui</h3><p>'+esc(r.msg||'')+'</p></div>';
    ['s0','s1','s2','s3'].forEach(i=>document.getElementById(i).textContent='–');
    return;
  }

  const cls = r.clients || [];
  updateStats(cls);

  if (!cls.length) {
    w.innerHTML = '<div class="empty"><div class="ei">🔑</div><h3>No keys yet</h3><p>Click "New Key" to create your first access key.</p></div>';
    return;
  }

  let rows = '';
  cls.forEach(c => {
    const now=Date.now();
    const exp= c.expiry>0 && c.expiry<now;
    const soon= c.expiry>0 && !exp && (c.expiry-now)<7*86400000;
    const st = exp ? '<span class="bdg bg-r">Expired</span>'
               : !c.enable ? '<span class="bdg bg-d">Disabled</span>'
               : soon ? '<span class="bdg bg-y">Active</span>'
               : '<span class="bdg bg-g">Active</span>';
    rows += `<tr>
      <td>
        <div class="tname">${esc(c.name||'–')}</div>
        <div class="tuuid">${c.uuid.substring(0,20)}…</div>
      </td>
      <td>${fexp(c.expiry)}</td>
      <td>${fdata(c.up,c.down,c.limit)}</td>
      <td>${st}</td>
      <td class="acts">
        <button class="btn bcy bsm" onclick="showQR('${escJ(c.key)}','${escJ(c.name)}')">QR</button>
        <button class="btn bgr bsm" onclick="cpKey('${escJ(c.key)}',this)">Copy</button>
        <button class="btn brd bsm" onclick="delKey('${escJ(c.uuid)}','${escJ(c.name)}',this)">Del</button>
      </td>
    </tr>`;
  });

  w.innerHTML = '<div class="tw"><table>'+
    '<thead><tr>'+
    '<th>Client</th><th>Expiry</th><th>Data Usage</th><th>Status</th><th>Actions</th>'+
    '</tr></thead><tbody>'+rows+'</tbody></table></div>';
}

function updateStats(cls) {
  const now=Date.now();
  let act=0,soon=0,exp=0;
  cls.forEach(c=>{
    if(!c.expiry||c.expiry===0){act++;return;}
    if(c.expiry<now) exp++;
    else { act++; if((c.expiry-now)<7*86400000) soon++; }
  });
  document.getElementById('s0').textContent = cls.length;
  document.getElementById('s1').textContent = act;
  document.getElementById('s2').textContent = soon;
  document.getElementById('s3').textContent = exp;
}

// ─── Copy key ─────────────────────────────────────────────────
async function cpKey(key, btn) {
  try { await navigator.clipboard.writeText(key); } catch(e) {
    const ta=document.createElement('textarea'); ta.value=key;
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
  }
  const o=btn.textContent; btn.textContent='Copied!'; btn.style.color='var(--g)';
  setTimeout(()=>{btn.textContent=o; btn.style.color='';},2200);
}

// ─── Show QR for existing key ─────────────────────────────────
function showQR(key, name) {
  document.getElementById('mrt').textContent = '🔑 '+name;
  document.getElementById('rn').textContent = name;
  document.getElementById('rk').textContent = key;
  document.getElementById('kc').dataset.key = key;
  const qd=document.getElementById('qrc'); qd.innerHTML='';
  new QRCode(qd,{text:key,width:200,height:200,colorDark:'#000',colorLight:'#fff',correctLevel:QRCode.CorrectLevel.M});
  openM('mr');
}

// ─── Delete key ───────────────────────────────────────────────
async function delKey(uuid, name, btn) {
  if(!confirm('Delete key for "'+name+'"?\nThis cannot be undone.')) return;
  const o=btn.textContent; btn.textContent='…'; btn.disabled=true;
  const r=await X({act:'del',uuid});
  if(r.ok) { toast('Key deleted — '+name); loadKeys(); }
  else { toast(r.msg||'Delete failed',false); btn.textContent=o; btn.disabled=false; }
}

// ─── Modal helpers ────────────────────────────────────────────
function openM(id) { document.getElementById(id).classList.add('open'); }
function closeM(id) { document.getElementById(id).classList.remove('open'); }

function openCreate() {
  document.getElementById('fn').value='';
  document.getElementById('fd').value='30';
  document.getElementById('fg').value='0';
  document.getElementById('cerr').style.display='none';
  document.getElementById('bcr').textContent='Generate Key';
  document.getElementById('bcr').disabled=false;
  openM('mc');
  setTimeout(()=>document.getElementById('fn').focus(),120);
}

// ─── Create key ───────────────────────────────────────────────
async function doCreate() {
  const name=document.getElementById('fn').value.trim();
  const days=document.getElementById('fd').value;
  const gb=document.getElementById('fg').value;
  const errDiv=document.getElementById('cerr');
  const btn=document.getElementById('bcr');

  if (!name) { errDiv.textContent='Enter a client label.'; errDiv.style.display='block'; return; }

  errDiv.style.display='none';
  btn.textContent='Creating…'; btn.disabled=true;

  const r = await X({act:'create',name,days,gb});
  btn.textContent='Generate Key'; btn.disabled=false;

  if (r.ok) {
    closeM('mc');
    loadKeys();
    toast('Key created for '+r.name+'!');
    // Show result
    document.getElementById('mrt').textContent = '✓ Key Created';
    document.getElementById('rn').textContent = r.name;
    document.getElementById('rk').textContent = r.key;
    const qd=document.getElementById('qrc'); qd.innerHTML='';
    new QRCode(qd,{text:r.key,width:200,height:200,colorDark:'#000',colorLight:'#fff',correctLevel:QRCode.CorrectLevel.M});
    openM('mr');
  } else {
    errDiv.textContent = r.msg || 'Failed to create key.';
    errDiv.style.display = 'block';
  }
}

// ─── Copy result key ──────────────────────────────────────────
async function copyRes() {
  const key=document.getElementById('rk').textContent;
  const btn=document.getElementById('kc');
  try { await navigator.clipboard.writeText(key); } catch(e) {
    const ta=document.createElement('textarea'); ta.value=key;
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
  }
  btn.textContent='copied!'; btn.classList.add('ok');
  toast('Key copied to clipboard!');
  setTimeout(()=>{btn.textContent='copy'; btn.classList.remove('ok');},2200);
}

// ─── Close modal on bg click ──────────────────────────────────
['mc','mr'].forEach(id=>{
  document.getElementById(id).addEventListener('click',function(e){
    if(e.target===this) this.classList.remove('open');
  });
});

// ─── Enter key submit ─────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key==='Escape') ['mc','mr'].forEach(id=>closeM(id));
});

// ─── Init ─────────────────────────────────────────────────────
loadKeys();
setInterval(loadKeys, 30000);
</script>

<?php endif; ?>
</body>
</html>
