#!/bin/bash

# ═══════════════════════════════════════════════════════════════
#   X-Connect VPN Infrastructure Setup
#   Method: VLESS + WebSocket + Cloudflare CDN
#   Supports: 3x-ui panel auto-install & Cloudflare automation
# ═══════════════════════════════════════════════════════════════

# ─── Colors ───────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

# ─── Print Helpers ─────────────────────────────────────────────
print_banner() {
    clear
    echo -e "${CYAN}${BOLD}"
    echo "  ╔══════════════════════════════════════════════════════╗"
    echo "  ║         X-Connect VPN Infrastructure Setup          ║"
    echo "  ║        VLESS + WebSocket + Cloudflare CDN           ║"
    echo "  ╚══════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

print_step() {
    echo ""
    echo -e "${BLUE}${BOLD}  ┌─ STEP $1 ─ $2 $( printf '─%.0s' $(seq 1 $((45 - ${#2}))) )┐${NC}"
}

print_end_step() {
    echo -e "${BLUE}${BOLD}  └$( printf '─%.0s' $(seq 1 52) )┘${NC}"
    echo ""
}

ok()   { echo -e "  ${GREEN}✓${NC}  $1"; }
err()  { echo -e "  ${RED}✗${NC}  $1"; }
info() { echo -e "  ${YELLOW}→${NC}  $1"; }
ask()  { echo -e "  ${CYAN}?${NC}  $1"; }

divider() { echo -e "  ${DIM}──────────────────────────────────────────────────────${NC}"; }


# ═══════════════════════════════════════════════════════════════
# STEP 1: Root Check
# ═══════════════════════════════════════════════════════════════
check_root() {
    print_step "1" "System Check"

    if [[ $EUID -ne 0 ]]; then
        err "This script must be run as root."
        echo -e "     Run: ${YELLOW}sudo su${NC} first, then re-run the script."
        exit 1
    fi
    ok "Running as root"

    # OS Check
    if [[ -f /etc/os-release ]]; then
        source /etc/os-release
        ok "OS: $PRETTY_NAME"
    fi

    print_end_step
}


# ═══════════════════════════════════════════════════════════════
# STEP 2: Install Dependencies
# ═══════════════════════════════════════════════════════════════
install_dependencies() {
    print_step "2" "Installing Dependencies"

    info "Updating package list..."
    apt-get update -qq 2>/dev/null

    DEPS=("curl" "jq" "qrencode" "uuidgen")
    PKGS=("curl" "jq" "qrencode" "uuid-runtime")

    for i in "${!DEPS[@]}"; do
        CMD="${DEPS[$i]}"
        PKG="${PKGS[$i]}"
        if command -v "$CMD" &>/dev/null; then
            ok "$CMD already installed"
        else
            info "Installing $PKG..."
            apt-get install -y "$PKG" -qq 2>/dev/null
            if command -v "$CMD" &>/dev/null; then
                ok "$PKG installed"
            else
                err "Failed to install $PKG — continuing anyway"
            fi
        fi
    done

    print_end_step
}


# ═══════════════════════════════════════════════════════════════
# STEP 3: Check / Install 3x-ui
# ═══════════════════════════════════════════════════════════════
setup_3xui() {
    print_step "3" "3x-ui Panel Check"

    if systemctl is-active --quiet x-ui 2>/dev/null; then
        ok "3x-ui is already installed and running"
        print_end_step
        return 0
    fi

    if command -v x-ui &>/dev/null; then
        info "3x-ui found but not running. Starting..."
        x-ui start 2>/dev/null || systemctl start x-ui 2>/dev/null
        sleep 2
        if systemctl is-active --quiet x-ui 2>/dev/null; then
            ok "3x-ui started successfully"
        else
            err "Could not start x-ui. Please check manually."
        fi
        print_end_step
        return 0
    fi

    info "3x-ui not found. Starting installation..."
    echo ""
    echo -e "  ${YELLOW}${BOLD}NOTE: The 3x-ui installer is interactive.${NC}"
    echo -e "  ${YELLOW}It will ask you to set port, username, and password.${NC}"
    echo -e "  ${YELLOW}Remember these — you will need them in the next step.${NC}"
    echo ""
    read -p "  Press ENTER to begin 3x-ui installation..." _

    bash <(curl -Ls https://raw.githubusercontent.com/mhsanaei/3x-ui/master/install.sh)

    sleep 3

    if systemctl is-active --quiet x-ui 2>/dev/null; then
        ok "3x-ui installed and running"
    else
        err "3x-ui installation may have failed."
        echo -e "  Please check with: ${YELLOW}systemctl status x-ui${NC}"
        read -p "  Continue anyway? (y/n): " cont
        [[ "$cont" != "y" ]] && exit 1
    fi

    print_end_step
}


# ═══════════════════════════════════════════════════════════════
# STEP 4: Collect User Inputs
# ═══════════════════════════════════════════════════════════════
collect_inputs() {
    print_step "4" "Configuration"

    # Auto-detect VPS IP
    info "Detecting VPS public IP..."
    VPS_IP=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || \
             curl -s --max-time 5 icanhazip.com 2>/dev/null || \
             curl -s --max-time 5 api.ipify.org 2>/dev/null)

    if [[ -n "$VPS_IP" ]]; then
        ok "VPS IP detected: ${BOLD}$VPS_IP${NC}"
        read -p "  Press Enter to use this IP or type a different one: " custom_ip
        [[ -n "$custom_ip" ]] && VPS_IP="$custom_ip"
    else
        err "Could not auto-detect IP"
        ask "Enter your VPS public IP: "
        read VPS_IP
    fi

    divider

    # 3x-ui panel details
    echo ""
    echo -e "  ${BOLD}3x-ui Panel Details${NC}"
    ask "Panel Port [default: 2053]: "
    read PANEL_PORT
    PANEL_PORT=${PANEL_PORT:-2053}

    ask "Panel Username [default: admin]: "
    read PANEL_USER
    PANEL_USER=${PANEL_USER:-admin}

    ask "Panel Password: "
    read -s PANEL_PASS
    echo ""

    divider

    # Cloudflare details
    echo ""
    echo -e "  ${BOLD}Cloudflare Details${NC}"
    ask "Cloudflare API Token: "
    read -s CF_API_TOKEN
    echo ""

    ask "Cloudflare Zone ID: "
    read CF_ZONE_ID

    ask "Your Domain (e.g. mydomain.com): "
    read CF_DOMAIN

    divider

    # Subdomain names
    echo ""
    echo -e "  ${BOLD}Subdomain Setup${NC}"
    ask "Panel subdomain name (e.g. 'admin' → admin.$CF_DOMAIN, proxy OFF): "
    read PANEL_SUB
    # Strip full domain if user typed it by mistake e.g. admin.domain.com → admin
    PANEL_SUB=$(echo "$PANEL_SUB" | sed "s/\.${CF_DOMAIN}$//" | awk -F'.' '{print $1}')

    ask "CDN subdomain name for keys (e.g. 'th1' → th1.$CF_DOMAIN, proxy ON): "
    read CDN_SUB
    # Strip full domain if user typed it by mistake
    CDN_SUB=$(echo "$CDN_SUB" | sed "s/\.${CF_DOMAIN}$//" | awk -F'.' '{print $1}')

    # Build full domains
    PANEL_DOMAIN="${PANEL_SUB}.${CF_DOMAIN}"
    CDN_DOMAIN="${CDN_SUB}.${CF_DOMAIN}"

    # WS path
    ask "WebSocket Path [default: /]: "
    read WS_PATH
    WS_PATH=${WS_PATH:-/}
    # Ensure path starts with /
    [[ "${WS_PATH:0:1}" != "/" ]] && WS_PATH="/$WS_PATH"

    divider
    echo ""
    echo -e "  ${BOLD}${CYAN}Review Configuration:${NC}"
    echo ""
    echo -e "  VPS IP         →  ${BOLD}$VPS_IP${NC}"
    echo -e "  Panel URL      →  ${BOLD}http://$PANEL_DOMAIN:$PANEL_PORT${NC}  (proxy OFF)"
    echo -e "  CDN Domain     →  ${BOLD}$CDN_DOMAIN${NC}  (proxy ON)"
    echo -e "  WS Port        →  ${BOLD}8080${NC} (CF forwards 443 → 8080)"
    echo -e "  WS Path        →  ${BOLD}$WS_PATH${NC}"
    echo ""

    read -p "  Looks good? Continue (y/n): " confirm
    [[ "$confirm" != "y" ]] && echo "  Aborted." && exit 0

    print_end_step
}


# ═══════════════════════════════════════════════════════════════
# STEP 5: Cloudflare DNS
# ═══════════════════════════════════════════════════════════════
setup_cloudflare_dns() {
    print_step "5" "Cloudflare DNS Records"

    CF_API="https://api.cloudflare.com/client/v4"

    cf_headers() {
        echo -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json"
    }

    # Delete existing A records with same name (avoid conflicts)
    delete_existing_dns() {
        local NAME="$1"
        local EXISTING=$(curl -s -X GET \
            -H "Authorization: Bearer $CF_API_TOKEN" \
            -H "Content-Type: application/json" \
            "$CF_API/zones/$CF_ZONE_ID/dns_records?type=A&name=$NAME.$CF_DOMAIN")
        
        local RECORD_ID=$(echo "$EXISTING" | jq -r '.result[0].id // empty')
        if [[ -n "$RECORD_ID" ]]; then
            curl -s -X DELETE \
                -H "Authorization: Bearer $CF_API_TOKEN" \
                "$CF_API/zones/$CF_ZONE_ID/dns_records/$RECORD_ID" > /dev/null
            info "Removed existing DNS record for $NAME.$CF_DOMAIN"
        fi
    }

    # Panel subdomain — proxy OFF
    info "Creating $PANEL_DOMAIN → $VPS_IP (proxy OFF)..."
    delete_existing_dns "$PANEL_SUB"
    RESP=$(curl -s -X POST \
        -H "Authorization: Bearer $CF_API_TOKEN" \
        -H "Content-Type: application/json" \
        "$CF_API/zones/$CF_ZONE_ID/dns_records" \
        -d "{\"type\":\"A\",\"name\":\"$PANEL_SUB\",\"content\":\"$VPS_IP\",\"ttl\":1,\"proxied\":false}")

    if echo "$RESP" | jq -e '.success' 2>/dev/null | grep -q true; then
        ok "$PANEL_DOMAIN → $VPS_IP  [proxy: OFF]"
    else
        ERR=$(echo "$RESP" | jq -r '.errors[0].message // "Unknown error"' 2>/dev/null)
        err "Failed to create panel DNS: $ERR"
    fi

    # CDN subdomain — proxy ON
    info "Creating $CDN_DOMAIN → $VPS_IP (proxy ON)..."
    delete_existing_dns "$CDN_SUB"
    RESP=$(curl -s -X POST \
        -H "Authorization: Bearer $CF_API_TOKEN" \
        -H "Content-Type: application/json" \
        "$CF_API/zones/$CF_ZONE_ID/dns_records" \
        -d "{\"type\":\"A\",\"name\":\"$CDN_SUB\",\"content\":\"$VPS_IP\",\"ttl\":1,\"proxied\":true}")

    if echo "$RESP" | jq -e '.success' 2>/dev/null | grep -q true; then
        ok "$CDN_DOMAIN → $VPS_IP  [proxy: ON ✓]"
    else
        ERR=$(echo "$RESP" | jq -r '.errors[0].message // "Unknown error"' 2>/dev/null)
        err "Failed to create CDN DNS: $ERR"
    fi

    print_end_step
}


# ═══════════════════════════════════════════════════════════════
# STEP 6: Cloudflare Origin Rule (443 → 8080)
# ═══════════════════════════════════════════════════════════════
setup_origin_rule() {
    print_step "6" "Cloudflare Origin Rule (Port Forward)"

    CF_API="https://api.cloudflare.com/client/v4"

    info "Creating Origin Rule: $CDN_DOMAIN:443 → VPS:8080..."

    # Step 1: Check if http_request_origin phase entry point already exists
    EXISTING=$(curl -s -X GET \
        -H "Authorization: Bearer $CF_API_TOKEN" \
        -H "Content-Type: application/json" \
        "$CF_API/zones/$CF_ZONE_ID/rulesets/phases/http_request_origin/entrypoint" 2>/dev/null)

    EXISTING_ID=$(echo "$EXISTING" | jq -r '.result.id // empty' 2>/dev/null)

    if [[ -n "$EXISTING_ID" ]]; then
        # Ruleset exists — PUT to update it
        info "Existing Origin ruleset found ($EXISTING_ID) — updating..."
        RESP=$(curl -s -X PUT \
            -H "Authorization: Bearer $CF_API_TOKEN" \
            -H "Content-Type: application/json" \
            "$CF_API/zones/$CF_ZONE_ID/rulesets/$EXISTING_ID" \
            -d "{
              \"rules\": [{
                \"action\": \"route\",
                \"action_parameters\": { \"origin\": { \"port\": 8080 } },
                \"expression\": \"http.host eq \\\"${CDN_DOMAIN}\\\"\",
                \"description\": \"X-Connect: ${CDN_DOMAIN}:443 → origin:8080\",
                \"enabled\": true
              }]
            }")
    else
        # No ruleset yet — POST to create
        info "No existing ruleset — creating new..."
        RESP=$(curl -s -X POST \
            -H "Authorization: Bearer $CF_API_TOKEN" \
            -H "Content-Type: application/json" \
            "$CF_API/zones/$CF_ZONE_ID/rulesets" \
            -d "{
              \"name\": \"X-Connect Origin Rules\",
              \"kind\": \"zone\",
              \"phase\": \"http_request_origin\",
              \"rules\": [{
                \"action\": \"route\",
                \"action_parameters\": { \"origin\": { \"port\": 8080 } },
                \"expression\": \"http.host eq \\\"${CDN_DOMAIN}\\\"\",
                \"description\": \"X-Connect: ${CDN_DOMAIN}:443 → origin:8080\",
                \"enabled\": true
              }]
            }")
    fi

    if echo "$RESP" | jq -e '.success' 2>/dev/null | grep -q true; then
        ok "Origin Rule created: $CDN_DOMAIN:443 → origin:8080"
    else
        ERR=$(echo "$RESP" | jq -r '.errors[0].message // "Unknown error"' 2>/dev/null)
        err "Origin Rule failed: $ERR"
        echo ""
        echo -e "  ${YELLOW}Your API token is missing the Origin Write permission.${NC}"
        echo -e "  Go to CF Dashboard → API Tokens → edit xconnect-api → Add more:"
        echo -e "  ${CYAN}Zone → Config Rules → Edit${NC}"
        echo -e "  Save token, copy new value, re-run script."
        echo ""
        read -p "  Or press ENTER to do it manually and continue..." _
    fi

    print_end_step
}
# ═══════════════════════════════════════════════════════════════
# STEP 7: 3x-ui Login
# ═══════════════════════════════════════════════════════════════
xui_login() {
    info "Logging into 3x-ui panel..."

    COOKIE_FILE="/tmp/xconnect_xui_session.txt"
    rm -f "$COOKIE_FILE"

    LOGIN_RESP=$(curl -s -c "$COOKIE_FILE" \
        -X POST "http://localhost:${PANEL_PORT}/login" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "username=${PANEL_USER}&password=${PANEL_PASS}")

    if echo "$LOGIN_RESP" | jq -e '.success' 2>/dev/null | grep -q true; then
        ok "Logged into 3x-ui panel"
        return 0
    else
        err "3x-ui login failed. Check username/password and panel port."
        echo -e "  Response: $(echo "$LOGIN_RESP" | head -c 200)"
        return 1
    fi
}


# ═══════════════════════════════════════════════════════════════
# STEP 8: Create WS Inbound in 3x-ui
# ═══════════════════════════════════════════════════════════════
setup_xui_inbound() {
    print_step "7" "Creating 3x-ui Inbound"

    COOKIE_FILE="/tmp/xconnect_xui_session.txt"

    if ! xui_login; then
        print_end_step
        return 1
    fi

    DEFAULT_UUID=$(uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid)
    ENCODED_PATH=$(python3 -c "import urllib.parse; print(urllib.parse.quote('$WS_PATH'))" 2>/dev/null || echo "%2F")

    # Build proper JSON settings strings
    SETTINGS_JSON=$(jq -c -n \
        --arg uuid "$DEFAULT_UUID" \
        '{clients:[{id:$uuid,flow:"",email:"default",limitIp:0,totalGB:0,expiryTime:0,enable:true,tgId:"",subId:""}],decryption:"none",fallbacks:[]}')

    STREAM_JSON=$(jq -c -n \
        --arg path "$WS_PATH" \
        '{network:"ws",security:"none",wsSettings:{path:$path,headers:{}}}')

    SNIFF_JSON='{"enabled":true,"destOverride":["http","tls"]}'

    INBOUND_PAYLOAD=$(jq -c -n \
        --arg remark "xconnect-ws-cdn" \
        --argjson port 8080 \
        --arg settings "$SETTINGS_JSON" \
        --arg stream "$STREAM_JSON" \
        --arg sniff "$SNIFF_JSON" \
        '{remark:$remark,enable:true,listen:"",port:$port,protocol:"vless",expiryTime:0,settings:$settings,streamSettings:$stream,sniffing:$sniff}')

    info "Creating VLESS+WS inbound on port 8080..."

    RESP=$(curl -s -b "$COOKIE_FILE" \
        -X POST "http://localhost:${PANEL_PORT}/xui/API/inbounds/add" \
        -H "Content-Type: application/json" \
        -d "$INBOUND_PAYLOAD")

    if echo "$RESP" | jq -e '.success' 2>/dev/null | grep -q true; then
        INBOUND_ID=$(echo "$RESP" | jq -r '.obj.id // "1"')
        ok "WS Inbound created on port 8080 (ID: $INBOUND_ID)"

        # Save inbound ID
        echo "$INBOUND_ID" > /tmp/xconnect_inbound_id.txt

        # Build transformed key
        TAG="X-Connect-$(echo $CDN_SUB | tr '[:lower:]' '[:upper:]')"
        TRANSFORMED_KEY="vless://${DEFAULT_UUID}@${CDN_DOMAIN}:443?type=ws&path=${ENCODED_PATH}&security=tls&sni=${CDN_DOMAIN}&host=${CDN_DOMAIN}&fp=chrome&alpn=http%2F1.1#${TAG}"

        echo ""
        divider
        echo -e "  ${GREEN}${BOLD}Default Key (transformed, ready to distribute):${NC}"
        echo ""
        echo -e "  ${CYAN}$TRANSFORMED_KEY${NC}"
        echo ""

        # QR code in terminal if qrencode is available
        if command -v qrencode &>/dev/null; then
            echo -e "  ${BOLD}QR Code:${NC}"
            qrencode -t ANSIUTF8 "$TRANSFORMED_KEY"
            echo ""
        fi

        divider
    else
        err "Failed to create inbound via API"
        echo -e "  ${DIM}Response: $(echo "$RESP" | head -c 300)${NC}"
        info "You may need to create the inbound manually in the panel."
        INBOUND_ID="1"
        echo "$INBOUND_ID" > /tmp/xconnect_inbound_id.txt
    fi

    print_end_step
}


# ═══════════════════════════════════════════════════════════════
# STEP 9: Open Firewall Port 8080
# ═══════════════════════════════════════════════════════════════
setup_firewall() {
    print_step "8" "Firewall Setup"

    if command -v ufw &>/dev/null; then
        info "Opening port 8080 (ufw)..."
        ufw allow 8080/tcp > /dev/null 2>&1
        ufw allow "${PANEL_PORT}/tcp" > /dev/null 2>&1
        ok "ufw: ports 8080 and $PANEL_PORT opened"
    elif command -v firewall-cmd &>/dev/null; then
        info "Opening port 8080 (firewalld)..."
        firewall-cmd --permanent --add-port=8080/tcp > /dev/null 2>&1
        firewall-cmd --permanent --add-port="${PANEL_PORT}/tcp" > /dev/null 2>&1
        firewall-cmd --reload > /dev/null 2>&1
        ok "firewalld: ports 8080 and $PANEL_PORT opened"
    else
        info "No ufw/firewalld detected — using iptables..."
        iptables -I INPUT -p tcp --dport 8080 -j ACCEPT 2>/dev/null
        iptables -I INPUT -p tcp --dport "$PANEL_PORT" -j ACCEPT 2>/dev/null
        ok "iptables: ports 8080 and $PANEL_PORT opened"
    fi

    print_end_step
}


# ═══════════════════════════════════════════════════════════════
# STEP 10: Save Config File
# ═══════════════════════════════════════════════════════════════
save_config() {
    CONFIG_FILE="/root/xconnect.conf"

    cat > "$CONFIG_FILE" <<EOF
# ─── X-Connect Setup Config ───────────────────────────────────
# Generated: $(date)
VPS_IP=$VPS_IP
PANEL_PORT=$PANEL_PORT
PANEL_USER=$PANEL_USER
PANEL_DOMAIN=$PANEL_DOMAIN
CDN_DOMAIN=$CDN_DOMAIN
CF_ZONE_ID=$CF_ZONE_ID
CF_DOMAIN=$CF_DOMAIN
CDN_SUB=$CDN_SUB
PANEL_SUB=$PANEL_SUB
WS_PATH=$WS_PATH
WS_PORT=8080
INBOUND_ID=$(cat /tmp/xconnect_inbound_id.txt 2>/dev/null || echo "1")
EOF

    chmod 600 "$CONFIG_FILE"
    ok "Config saved to $CONFIG_FILE"
}


# ═══════════════════════════════════════════════════════════════
# Final Summary
# ═══════════════════════════════════════════════════════════════
print_summary() {
    echo ""
    echo -e "${GREEN}${BOLD}"
    echo "  ╔══════════════════════════════════════════════════════╗"
    echo "  ║                  SETUP COMPLETE ✓                   ║"
    echo "  ╚══════════════════════════════════════════════════════╝"
    echo -e "${NC}"

    echo -e "  ${BOLD}Panel URL:${NC}       http://$PANEL_DOMAIN:$PANEL_PORT"
    echo -e "  ${BOLD}CDN Domain:${NC}      $CDN_DOMAIN"
    echo -e "  ${BOLD}WS Port:${NC}         8080 (CF forwards 443 → 8080)"
    echo -e "  ${BOLD}WS Path:${NC}         $WS_PATH"
    echo -e "  ${BOLD}Config file:${NC}     /root/xconnect.conf"
    echo -e "  ${BOLD}Inbound ID:${NC}      $(cat /tmp/xconnect_inbound_id.txt 2>/dev/null || echo '1')"
    echo ""
    echo -e "  ${YELLOW}Next Step:${NC}"
    echo -e "  Deploy the Admin Panel web app to create & manage keys."
    echo -e "  Keys will auto-transform to use ${BOLD}$CDN_DOMAIN:443${NC}"
    echo ""
}


# ═══════════════════════════════════════════════════════════════
# Main
# ═══════════════════════════════════════════════════════════════
main() {
    print_banner
    check_root
    install_dependencies
    collect_inputs
    setup_cloudflare_dns
    setup_origin_rule
    echo ""
    echo -e "  ${YELLOW}${BOLD}  DNS records created. Waiting 15s for propagation...${NC}"
    sleep 15
    echo -e "  ${GREEN}✓${NC}  Ready — starting 3x-ui install"
    echo ""
    echo -e "  ${YELLOW}  NOTE: When 3x-ui asks for SSL setup:${NC}"
    echo -e "  ${YELLOW}  → Choose option 1 (Let's Encrypt for Domain)${NC}"
    echo -e "  ${YELLOW}  → Enter panel subdomain: ${BOLD}${PANEL_DOMAIN}${NC}"
    echo ""
    read -p "  Press ENTER to continue to 3x-ui install..." _
    setup_3xui
    setup_xui_inbound
    setup_firewall
    save_config
    print_summary
}

main
