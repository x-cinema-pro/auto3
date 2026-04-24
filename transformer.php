<?php
// Read CDN domain from xconnect.conf if available
$cdn_domain = '';
$conf_file = '/root/xconnect.conf';
if (file_exists($conf_file)) {
    foreach (@file($conf_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, 'CDN_DOMAIN=') === 0) {
            $cdn_domain = trim(explode('=', $line, 2)[1]);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VLESS Key Transformer</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:    #050508;
  --bg2:   #0a0a10;
  --bg3:   #0f0f18;
  --bd:    #1a1a28;
  --bd2:   #232338;
  --g:     #00ff88;
  --g2:    rgba(0,255,136,.08);
  --g3:    rgba(0,255,136,.15);
  --cy:    #00d4ff;
  --rd:    #ff4466;
  --tx:    #8892a4;
  --txb:   #e2e8f4;
  --txd:   #3a4050;
}

body {
  font-family: 'JetBrains Mono', monospace;
  background: var(--bg);
  color: var(--tx);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  position: relative;
  overflow-x: hidden;
}

/* Grid background */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(0,255,136,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,255,136,.03) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
  z-index: 0;
}

/* Glow orb */
body::after {
  content: '';
  position: fixed;
  top: -200px;
  left: 50%;
  transform: translateX(-50%);
  width: 600px;
  height: 400px;
  background: radial-gradient(ellipse, rgba(0,255,136,.06) 0%, transparent 70%);
  pointer-events: none;
  z-index: 0;
}

.wrap {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 680px;
}

/* Header */
.hd {
  text-align: center;
  margin-bottom: 40px;
}

.logo {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}

.logo-icon {
  width: 36px; height: 36px;
  background: var(--g2);
  border: 1px solid rgba(0,255,136,.3);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  position: relative;
}

.logo-icon::after {
  content: '';
  position: absolute;
  inset: -1px;
  border-radius: 10px;
  background: linear-gradient(135deg, rgba(0,255,136,.2), transparent 60%);
  pointer-events: none;
}

.logo-text {
  font-family: 'Syne', sans-serif;
  font-size: 18px;
  font-weight: 800;
  color: var(--txb);
  letter-spacing: -.3px;
}

.logo-text span { color: var(--g); }

.subtitle {
  font-size: 11px;
  color: var(--txd);
  letter-spacing: .15em;
  text-transform: uppercase;
}

/* Card */
.card {
  background: var(--bg2);
  border: 1px solid var(--bd);
  border-radius: 16px;
  padding: 32px;
  box-shadow:
    0 0 0 1px rgba(255,255,255,.02),
    0 20px 60px rgba(0,0,0,.5);
  position: relative;
  overflow: hidden;
}

.card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(0,255,136,.3), transparent);
}

/* Field */
.field { margin-bottom: 20px; }

.field-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 20px;
}

label {
  display: block;
  font-size: 10px;
  font-weight: 600;
  color: var(--txd);
  text-transform: uppercase;
  letter-spacing: .12em;
  margin-bottom: 8px;
}

label span {
  color: var(--g);
  margin-left: 4px;
  font-size: 9px;
}

textarea, input[type="text"] {
  width: 100%;
  background: var(--bg3);
  border: 1px solid var(--bd);
  border-radius: 8px;
  color: var(--txb);
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
  resize: none;
}

textarea {
  padding: 14px;
  min-height: 100px;
  line-height: 1.7;
}

input[type="text"] {
  padding: 11px 14px;
  font-size: 12px;
}

textarea:focus, input[type="text"]:focus {
  border-color: rgba(0,255,136,.4);
  box-shadow: 0 0 0 3px rgba(0,255,136,.06);
}

textarea::placeholder, input::placeholder {
  color: var(--txd);
  font-size: 10px;
}

/* Divider */
.div-label {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 24px 0;
  font-size: 10px;
  color: var(--txd);
  text-transform: uppercase;
  letter-spacing: .12em;
}

.div-label::before, .div-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--bd);
}

/* Transform button */
.btn-wrap { margin: 8px 0 24px; }

.btn {
  width: 100%;
  padding: 14px;
  background: var(--g);
  color: #030508;
  border: none;
  border-radius: 8px;
  font-family: 'Syne', sans-serif;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: .05em;
  cursor: pointer;
  transition: all .15s;
  text-transform: uppercase;
  position: relative;
  overflow: hidden;
}

.btn::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,.15), transparent);
  opacity: 0;
  transition: opacity .15s;
}

.btn:hover::before { opacity: 1; }
.btn:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(0,255,136,.25); }
.btn:active { transform: translateY(0); filter: brightness(.97); }
.btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }

/* Output */
.out-wrap { position: relative; }

.out-box {
  width: 100%;
  min-height: 100px;
  background: var(--bg);
  border: 1px solid var(--bd2);
  border-radius: 8px;
  color: var(--cy);
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  padding: 14px 46px 14px 14px;
  line-height: 1.7;
  word-break: break-all;
  white-space: pre-wrap;
  min-height: 100px;
  cursor: text;
  transition: border-color .2s;
}

.out-box.has-val {
  border-color: rgba(0,255,136,.2);
  box-shadow: 0 0 20px rgba(0,255,136,.04);
}

.out-box.empty {
  color: var(--txd);
  font-size: 10px;
  display: flex;
  align-items: center;
}

.copy-btn {
  position: absolute;
  top: 10px; right: 10px;
  background: var(--bg3);
  border: 1px solid var(--bd2);
  border-radius: 6px;
  color: var(--txd);
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  font-weight: 600;
  padding: 4px 10px;
  cursor: pointer;
  transition: all .15s;
  text-transform: uppercase;
  letter-spacing: .05em;
}

.copy-btn:hover { color: var(--txb); border-color: var(--bd2); background: var(--bg2); }
.copy-btn.ok { color: var(--g); border-color: rgba(0,255,136,.3); }

/* Error */
.error-box {
  background: rgba(255,68,102,.06);
  border: 1px solid rgba(255,68,102,.2);
  border-radius: 8px;
  padding: 11px 14px;
  color: var(--rd);
  font-size: 11px;
  margin-top: 12px;
  display: none;
}

/* Stats strip */
.stats {
  display: flex;
  gap: 6px;
  margin-top: 12px;
  flex-wrap: wrap;
}

.tag {
  background: var(--bg3);
  border: 1px solid var(--bd2);
  border-radius: 4px;
  padding: 3px 9px;
  font-size: 10px;
  color: var(--txd);
}

.tag.ok { color: var(--g); border-color: rgba(0,255,136,.2); background: rgba(0,255,136,.05); }
.tag.cy { color: var(--cy); border-color: rgba(0,212,255,.2); background: rgba(0,212,255,.05); }

/* Footer */
.foot {
  text-align: center;
  margin-top: 20px;
  font-size: 10px;
  color: var(--txd);
  letter-spacing: .08em;
}

@media(max-width: 500px) {
  .card { padding: 20px; }
  .field-row { grid-template-columns: 1fr; gap: 12px; }
}
</style>
</head>
<body>
<div class="wrap">

  <div class="hd">
    <div class="logo">
      <div class="logo-icon">⚡</div>
      <div class="logo-text">X<span>-</span>Connect</div>
    </div>
    <div class="subtitle">VLESS Key Transformer</div>
  </div>

  <div class="card">

    <!-- Input -->
    <div class="field">
      <label>Raw VLESS Key <span>paste original key</span></label>
      <textarea id="raw" placeholder="vless://UUID@domain:8080?type=ws&security=none&path=%2F#label"></textarea>
    </div>

    <!-- CDN Domain + Path -->
    <div class="field-row">
      <div>
        <label>CDN Domain <span>proxy ON, port 443</span></label>
        <input type="text" id="cdn" value="<?= htmlspecialchars($cdn_domain) ?>"
          placeholder="th1.yourdomain.com">
      </div>
      <div>
        <label>WS Path <span>optional</span></label>
        <input type="text" id="wspath" value="/" placeholder="/">
      </div>
    </div>

    <!-- Button -->
    <div class="btn-wrap">
      <button class="btn" onclick="transform()">⚡ Transform Key</button>
    </div>

    <div class="div-label">Output</div>

    <!-- Output -->
    <div class="out-wrap">
      <div class="out-box empty" id="out">Transformed key will appear here...</div>
      <button class="copy-btn" id="copybtn" onclick="copyOut()" style="display:none">COPY</button>
    </div>

    <div id="tags" class="stats" style="display:none"></div>
    <div class="error-box" id="err"></div>

  </div>

  <div class="foot">X-Connect · Key Transform Tool</div>
</div>

<script>
function parseVless(key) {
  try {
    // vless://UUID@host:port?params#fragment
    const m = key.match(/^vless:\/\/([^@]+)@([^:]+):(\d+)\?([^#]*)(?:#(.*))?$/);
    if (!m) return null;
    return {
      uuid:     m[1].trim(),
      host:     m[2].trim(),
      port:     m[3].trim(),
      params:   Object.fromEntries(new URLSearchParams(m[4])),
      fragment: m[5] ? decodeURIComponent(m[5]) : ''
    };
  } catch(e) { return null; }
}

function transform() {
  const raw    = document.getElementById('raw').value.trim();
  const cdn    = document.getElementById('cdn').value.trim();
  const wspath = document.getElementById('wspath').value.trim() || '/';
  const errDiv = document.getElementById('err');
  const outDiv = document.getElementById('out');
  const tags   = document.getElementById('tags');
  const copyBtn= document.getElementById('copybtn');

  errDiv.style.display = 'none';
  tags.style.display   = 'none';
  copyBtn.style.display= 'none';
  outDiv.className     = 'out-box empty';
  outDiv.textContent   = 'Transforming...';

  if (!raw) { showErr('Paste a VLESS key first.'); return; }
  if (!cdn) { showErr('Enter your CDN domain.'); return; }

  const p = parseVless(raw);
  if (!p) { showErr('Invalid VLESS key format. Make sure it starts with vless://'); return; }

  // Encode path
  const encodedPath = encodeURIComponent(wspath.startsWith('/') ? wspath : '/' + wspath);

  // Build transformed params
  const newParams = new URLSearchParams({
    path:       encodedPath,
    security:   'tls',
    alpn:       'http/1.1',
    encryption: 'none',
    host:       cdn,
    fp:         'chrome',
    type:       'ws',
    sni:        cdn
  });

  // Keep original fragment if exists, else use original
  const frag = p.fragment || p.host;

  const result = `vless://${p.uuid}@${cdn}:443?${newParams.toString()}#${encodeURIComponent(frag)}`;

  outDiv.className   = 'out-box has-val';
  outDiv.textContent = result;
  copyBtn.style.display = 'block';

  // Stats tags
  tags.style.display = 'flex';
  tags.innerHTML = `
    <span class="tag ok">✓ TLS</span>
    <span class="tag ok">✓ Port 443</span>
    <span class="tag cy">CDN: ${cdn}</span>
    <span class="tag">WS Path: ${wspath}</span>
    <span class="tag">UUID: ${p.uuid.substring(0,8)}…</span>
  `;
}

function showErr(msg) {
  const e = document.getElementById('err');
  e.textContent = '✗  ' + msg;
  e.style.display = 'block';
  document.getElementById('out').textContent = 'Transformed key will appear here...';
  document.getElementById('out').className = 'out-box empty';
}

async function copyOut() {
  const text = document.getElementById('out').textContent;
  const btn  = document.getElementById('copybtn');
  if (!text || text.includes('appear here')) return;
  try {
    await navigator.clipboard.writeText(text);
  } catch(e) {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta);
    ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
  }
  btn.textContent = 'COPIED!'; btn.classList.add('ok');
  setTimeout(() => { btn.textContent = 'COPY'; btn.classList.remove('ok'); }, 2500);
}

// Enter shortcut
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') transform();
});
</script>
</body>
</html>
