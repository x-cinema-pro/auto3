# X-Connect Setup — VLESS + Cloudflare CDN Automated Installer

One-command deployment of a VLESS proxy server with WebSocket transport, routed through Cloudflare CDN for censorship-resistant connectivity.

```bash
sudo bash <(curl -Ls https://raw.githubusercontent.com/x-cinema-pro/auto3/main/xconnect_setup.sh)
```

## What it does

Automates the full setup of a VLESS+WebSocket proxy infrastructure behind Cloudflare CDN:

- **3x-ui Panel** — installs and configures the Xray management panel
- **VLESS + WebSocket** — creates inbound on port 8080 with WebSocket transport
- **Cloudflare CDN Proxy** — routes traffic through Cloudflare on port 443 for obfuscation
- **Key Transformer** — PHP tool (`transformer.php`) that converts raw VLESS keys to CDN-proxied keys

Traffic flows: `Client → Cloudflare CDN (443) → Your VPS (8080) → Internet`, making the VPN traffic appear as normal HTTPS web traffic.

## Requirements

- Fresh **Debian 10+** or **Ubuntu 20.04+** VPS
- Root access (`sudo`)
- A domain pointed to Cloudflare (DNS proxied, orange cloud enabled)
- Cloudflare SSL mode set to **Flexible**

## Installation

```bash
sudo bash <(curl -Ls https://raw.githubusercontent.com/x-cinema-pro/auto3/main/xconnect_setup.sh)
```

The installer will prompt for your domain and Cloudflare configuration, then handle:

1. 3x-ui panel installation and setup
2. VLESS inbound creation with WebSocket transport on port 8080
3. Firewall configuration
4. Transformer tool deployment for key conversion

## Cloudflare Configuration

Before running the installer, set up Cloudflare:

1. **Add your domain** to Cloudflare and point DNS to your VPS IP
2. **Enable proxy** (orange cloud) on the DNS A record
3. **SSL/TLS → set to Flexible** — this resolves connection issues between Cloudflare and your origin
4. Traffic now routes: `Client → Cloudflare (443, HTTPS) → VPS (8080, HTTP/WS)`

## Key Transformer

The `transformer.php` tool converts raw VLESS connection strings into CDN-proxied versions:

- Input: raw VLESS key pointing directly to VPS IP
- Output: VLESS key routed through your Cloudflare-proxied domain on port 443

This means end users connect via your clean domain instead of the raw VPS IP.

## Architecture

```
xconnect_setup.sh
├── Installs 3x-ui (Xray panel)
├── Configures VLESS inbound
│   ├── Protocol: VLESS
│   ├── Transport: WebSocket
│   └── Port: 8080
├── Sets up firewall rules
└── Deploys transformer.php
    └── Converts raw VLESS keys → CDN-proxied keys

Traffic flow:
Client ──HTTPS──▶ Cloudflare CDN (443)
                      │
                      ▼
              Your VPS (8080, WebSocket)
                      │
                      ▼
                  Internet
```

## Tech Stack

- **Xray / VLESS** — proxy protocol with WebSocket transport
- **3x-ui** — web panel for Xray server management
- **Cloudflare CDN** — traffic obfuscation and SSL termination
- **PHP** — key transformer tool
- **Bash** — installer automation

## Troubleshooting

| Issue | Fix |
|---|---|
| Connection refused on 443 | Ensure Cloudflare proxy (orange cloud) is ON for your DNS record |
| SSL errors | Set Cloudflare SSL mode to **Flexible**, not Full or Strict |
| 3x-ui panel not accessible | Check firewall allows the panel port: `ufw status` |
| Transformed keys don't connect | Verify domain resolves to Cloudflare IPs, not your VPS directly |

## License

MIT
