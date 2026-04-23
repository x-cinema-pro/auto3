#!/bin/bash

# ══════════════════════════════════════════════════════════════
#   X-Connect Admin Panel Installer
#   Installs PHP, deploys panel, creates systemd service
# ══════════════════════════════════════════════════════════════

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

# ══════════════════════════════════════════════════════════════
#  EDIT THIS → your GitHub raw base URL
#  e.g. https://raw.githubusercontent.com/YOUR_USER/YOUR_REPO/main
# ══════════════════════════════════════════════════════════════
REPO_RAW="https://raw.githubusercontent.com/YOUR_USER/YOUR_REPO/main"

ok()   { echo -e "  ${GREEN}✓${NC}  $1"; }
err()  { echo -e "  ${RED}✗${NC}  $1"; }
info() { echo -e "  ${YELLOW}→${NC}  $1"; }
ask()  { echo -ne "  ${CYAN}?${NC}  $1"; }

CONF="/root/xconnect.conf"
AUTH="/root/xconnect_panel.auth"
PANEL_DIR="/opt/xconnect"
PANEL_PORT="8888"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Banner ─────────────────────────────────────────────────────
clear
echo -e "${CYAN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════════╗"
echo "  ║         X-Connect Admin Panel Installer             ║"
echo "  ╚══════════════════════════════════════════════════════╝"
echo -e "${NC}"

# ── Root check ─────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    err "Must run as root. Run: sudo su"
    exit 1
fi
ok "Running as root"

# ── Check xconnect.conf ────────────────────────────────────────
if [[ ! -f "$CONF" ]]; then
    err "xconnect.conf not found at $CONF"
    echo -e "     Run the main setup script (xconnect_setup.sh) first."
    exit 1
fi
ok "xconnect.conf found"

# Source config
source "$CONF"

# ── Check PANEL_PASS ───────────────────────────────────────────
echo ""
if [[ -z "$PANEL_PASS" ]]; then
    echo -e "  ${YELLOW}3x-ui panel password not found in config.${NC}"
    ask "Enter your 3x-ui panel password: "
    read -s PANEL_PASS
    echo ""
    if [[ -z "$PANEL_PASS" ]]; then
        err "Password cannot be empty."
        exit 1
    fi
    echo "" >> "$CONF"
    echo "PANEL_PASS=$PANEL_PASS" >> "$CONF"
    ok "Panel password saved to config"
else
    ok "Panel password found in config"
fi

# ── Download panel PHP from GitHub ────────────────────────────
echo ""
info "Downloading panel from GitHub..."
PANEL_SRC="/tmp/xconnect_panel.php"
curl -Ls "$REPO_RAW/xconnect_panel.php" -o "$PANEL_SRC"
if [[ ! -s "$PANEL_SRC" ]]; then
    err "Failed to download xconnect_panel.php from:"
    echo -e "     $REPO_RAW/xconnect_panel.php"
    echo -e "     Check your REPO_RAW variable at the top of this script."
    exit 1
fi
ok "Panel file downloaded"

# ── Install PHP ────────────────────────────────────────────────
echo ""
info "Checking PHP..."
if command -v php &>/dev/null; then
    ok "PHP already installed: $(php -r 'echo PHP_VERSION;' 2>/dev/null)"
else
    info "Installing PHP..."
    apt-get update -qq 2>/dev/null
    apt-get install -y php-cli php-curl -qq 2>/dev/null
    if command -v php &>/dev/null; then
        ok "PHP installed: $(php -r 'echo PHP_VERSION;' 2>/dev/null)"
    else
        err "PHP installation failed. Try: apt-get install -y php-cli php-curl"
        exit 1
    fi
fi

# Check curl extension
php -m 2>/dev/null | grep -q curl
if [[ $? -ne 0 ]]; then
    info "Installing php-curl..."
    apt-get install -y php-curl -qq 2>/dev/null
    ok "php-curl installed"
else
    ok "php-curl extension available"
fi

# ── Generate admin credentials ─────────────────────────────────
echo ""
info "Generating admin credentials..."

ADMIN_USER="xc-admin"
ADMIN_PASS=$(tr -dc 'A-Za-z0-9!@#%^' </dev/urandom 2>/dev/null | head -c 18)
# Fallback if urandom not enough
[[ -z "$ADMIN_PASS" ]] && ADMIN_PASS=$(date +%s | sha256sum | base64 | head -c 18)

cat > "$AUTH" <<EOF
# X-Connect Panel Admin Auth
# Generated: $(date)
ADMIN_USER=$ADMIN_USER
ADMIN_PASS=$ADMIN_PASS
EOF
chmod 600 "$AUTH"
ok "Admin credentials generated"

# ── Deploy panel ───────────────────────────────────────────────
info "Deploying panel to $PANEL_DIR..."
mkdir -p "$PANEL_DIR"
cp "$PANEL_SRC" "$PANEL_DIR/index.php"
chmod 644 "$PANEL_DIR/index.php"
ok "Panel deployed to $PANEL_DIR/index.php"

# ── Create PHP session directory ───────────────────────────────
SESSION_DIR="/tmp/xconnect_sessions"
mkdir -p "$SESSION_DIR"
chmod 700 "$SESSION_DIR"
ok "Session directory: $SESSION_DIR"

# Inject session config into PHP invocation via .htaccess equivalent
# We'll pass it as ini settings in the systemd service

# ── Create systemd service ─────────────────────────────────────
info "Creating systemd service..."

cat > /etc/systemd/system/xconnect-panel.service <<EOF
[Unit]
Description=X-Connect VPN Admin Panel
After=network.target x-ui.service
Wants=x-ui.service

[Service]
Type=simple
User=root
WorkingDirectory=$PANEL_DIR
ExecStart=/usr/bin/php \
  -d session.save_path=$SESSION_DIR \
  -d session.gc_maxlifetime=86400 \
  -S 0.0.0.0:$PANEL_PORT \
  $PANEL_DIR/index.php
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable xconnect-panel --quiet 2>/dev/null

# Stop if already running
systemctl stop xconnect-panel 2>/dev/null
sleep 1
systemctl start xconnect-panel

sleep 2

if systemctl is-active --quiet xconnect-panel; then
    ok "Panel service running on port $PANEL_PORT"
else
    err "Panel service failed to start."
    echo -e "  Check: ${YELLOW}journalctl -u xconnect-panel -n 20${NC}"
    echo ""
    echo -e "  ${DIM}Attempting manual start for debug:${NC}"
    php -d session.save_path=$SESSION_DIR -S 0.0.0.0:$PANEL_PORT $PANEL_DIR/index.php &
    sleep 2
    if kill -0 $! 2>/dev/null; then
        ok "Manual start succeeded. Continuing..."
        kill $! 2>/dev/null
    fi
fi

# ── Open firewall ──────────────────────────────────────────────
echo ""
info "Opening firewall port $PANEL_PORT..."
if command -v ufw &>/dev/null; then
    ufw allow "$PANEL_PORT/tcp" > /dev/null 2>&1
    ok "ufw: port $PANEL_PORT opened"
elif command -v firewall-cmd &>/dev/null; then
    firewall-cmd --permanent --add-port="$PANEL_PORT/tcp" > /dev/null 2>&1
    firewall-cmd --reload > /dev/null 2>&1
    ok "firewalld: port $PANEL_PORT opened"
else
    iptables -I INPUT -p tcp --dport "$PANEL_PORT" -j ACCEPT 2>/dev/null
    ok "iptables: port $PANEL_PORT opened"
fi

# ── Verify 3x-ui is reachable ──────────────────────────────────
echo ""
info "Verifying 3x-ui connectivity..."
XUIRESP=$(curl -s --max-time 5 "http://127.0.0.1:${PANEL_PORT:-2053}/login" 2>/dev/null)
if [[ -n "$XUIRESP" ]]; then
    ok "3x-ui panel is reachable"
else
    echo -e "  ${YELLOW}⚠${NC}  3x-ui may not be running. Check: systemctl status x-ui"
fi

# ── Done ───────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════════╗"
echo "  ║              Admin Panel Ready ✓                    ║"
echo "  ╚══════════════════════════════════════════════════════╝"
echo -e "${NC}"

echo -e "  ${BOLD}Panel URL:${NC}   http://${VPS_IP}:${PANEL_PORT}"
echo ""
echo -e "  ${BOLD}Username:${NC}    ${CYAN}${BOLD}$ADMIN_USER${NC}"
echo -e "  ${BOLD}Password:${NC}    ${CYAN}${BOLD}$ADMIN_PASS${NC}"
echo ""
echo -e "  ${DIM}Credentials saved to: $AUTH${NC}"
echo ""
echo -e "  ${YELLOW}${BOLD}⚠  Save these credentials now. You won't see them again.${NC}"
echo ""
echo -e "  ${DIM}Service commands:${NC}"
echo -e "  ${DIM}  systemctl status xconnect-panel${NC}"
echo -e "  ${DIM}  systemctl restart xconnect-panel${NC}"
echo -e "  ${DIM}  journalctl -u xconnect-panel -f${NC}"
echo ""
