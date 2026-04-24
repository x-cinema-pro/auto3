#!/bin/bash

# ═══════════════════════════════════════════════════════════════
#   X-Connect VPN Infrastructure Setup
#   Method: VLESS + WebSocket + Cloudflare CDN
#   Features: 3x-ui, CF Auto-DNS, PHP Web Transformer
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
        err "This script must be run as root. Run: sudo su"
        exit 1
    fi
    ok "Running as root"
    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# STEP 2: Install Dependencies & Web Server
# ═══════════════════════════════════════════════════════════════
install_dependencies() {
    print_step "2" "Installing Dependencies & Web Server"

    info "Updating package list..."
    apt-get update -qq 2>/dev/null

    # Added apache2 and php for the Transformer Tool
    PKGS=("curl" "jq" "uuid-runtime" "apache2" "php" "libapache2-mod-php")

    for PKG in "${PKGS[@]}"; do
        if dpkg -l | grep -qw "$PKG"; then
            ok "$PKG already installed"
        else
            info "Installing $PKG..."
            apt-get install -y "$PKG" -qq 2>/dev/null
            ok "$PKG installed"
        fi
    done

    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# STEP 3: Check / Install 3x-ui (Interactive Fix applied)
# ═══════════════════════════════════════════════════════════════
setup_3xui() {
    print_step "3" "3x-ui Panel Installation"

    if systemctl is-active --quiet x-ui 2>/dev/null; then
        ok "3x-ui is already installed and running"
        print_end_step
        return 0
    fi

    info "Downloading 3x-ui installer for interactive setup..."
    echo ""
    echo -e "  ${YELLOW}${BOLD}NOTE: The 3x-ui installer will now launch.${NC}"
    echo -e "  ${YELLOW}Please type your desired port, username, and password.${NC}"
    echo ""
    
    # Securely download and run locally to prevent stdin freezing
    curl -Ls https://raw.githubusercontent.com/mhsanaei/3x-ui/master/install.sh -o /tmp/xui_install.sh
    bash /tmp/xui_install.sh
    rm -f /tmp/xui_install.sh

    sleep 3

    if systemctl is-active --quiet x-ui 2>/dev/null; then
        ok "3x-ui installed and running"
    else
        err "3x-ui installation failed or service didn't start."
    fi

    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# Auto-read 3x-ui credentials
# ═══════════════════════════════════════════════════════════════
read_xui_credentials() {
    print_step "3b" "Reading Panel Configuration"

    XUI_DB="/etc/x-ui/x-ui.db"
    if ! command -v sqlite3 &>/dev/null; then
        apt-get install -y sqlite3 -qq 2>/dev/null
    fi

    PANEL_PORT=$(sqlite3 "$XUI_DB" "SELECT value FROM settings WHERE key='webPort' LIMIT 1;" 2>/dev/null)
    WEB_BASE=$(sqlite3 "$XUI_DB" "SELECT value FROM settings WHERE key='webBasePath' LIMIT 1;" 2>/dev/null)
    
    PANEL_PORT=${PANEL_PORT:-2053}

    ok "Detected Panel Port: $PANEL_PORT"
    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# STEP 4: Collect Inputs
# ═══════════════════════════════════════════════════════════════
collect_inputs() {
    print_step "4" "Configuration"

    VPS_IP=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 api.ipify.org 2>/dev/null)
    ok "VPS IP detected: ${BOLD}$VPS_IP${NC}"
    read -p "  Press Enter to use this IP or type a different one: " custom_ip
    [[ -n "$custom_ip" ]] && VPS_IP="$custom_ip"

    divider
    echo -e "  ${BOLD}Cloudflare Details${NC}"
    ask "Cloudflare API Token: "
    read -s CF_API_TOKEN
    echo ""
    ask "Cloudflare Zone ID: "
    read CF_ZONE_ID
    ask "Your Domain (e.g. mydomain.com): "
    read CF_DOMAIN

    divider
    echo -e "  ${BOLD}Subdomain Setup${NC}"
    ask "Panel subdomain name (e.g. 'admin' → admin.$CF_DOMAIN, proxy OFF): "
    read PANEL_SUB
    PANEL_SUB=$(echo "$PANEL_SUB" | sed "s/\.${CF_DOMAIN}$//" | awk -F'.' '{print $1}')

    ask "CDN subdomain name for keys (e.g. 'th1' → th1.$CF_DOMAIN, proxy ON): "
    read CDN_SUB
    CDN_SUB=$(echo "$CDN_SUB" | sed "s/\.${CF_DOMAIN}$//" | awk -F'.' '{print $1}')

    PANEL_DOMAIN="${PANEL_SUB}.${CF_DOMAIN}"
    CDN_DOMAIN="${CDN_SUB}.${CF_DOMAIN}"

    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# STEP 5: Cloudflare DNS
# ═══════════════════════════════════════════════════════════════
setup_cloudflare_dns() {
    print_step "5" "Cloudflare DNS Records"
    CF_API="https://api.cloudflare.com/client/v4"

    delete_existing_dns() {
        local NAME="$1"
        local EXISTING=$(curl -s -X GET -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" "$CF_API/zones/$CF_ZONE_ID/dns_records?type=A&name=$NAME.$CF_DOMAIN")
        local RECORD_ID=$(echo "$EXISTING" | jq -r '.result[0].id // empty')
        if [[ -n "$RECORD_ID" ]]; then
            curl -s -X DELETE -H "Authorization: Bearer $CF_API_TOKEN" "$CF_API/zones/$CF_ZONE_ID/dns_records/$RECORD_ID" > /dev/null
        fi
    }

    # Panel subdomain
    delete_existing_dns "$PANEL_SUB"
    curl -s -X POST -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" "$CF_API/zones/$CF_ZONE_ID/dns_records" -d "{\"type\":\"A\",\"name\":\"$PANEL_SUB\",\"content\":\"$VPS_IP\",\"ttl\":1,\"proxied\":false}" >/dev/null
    ok "$PANEL_DOMAIN → $VPS_IP  [proxy: OFF]"

    # CDN subdomain
    delete_existing_dns "$CDN_SUB"
    curl -s -X POST -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" "$CF_API/zones/$CF_ZONE_ID/dns_records" -d "{\"type\":\"A\",\"name\":\"$CDN_SUB\",\"content\":\"$VPS_IP\",\"ttl\":1,\"proxied\":true}" >/dev/null
    ok "$CDN_DOMAIN → $VPS_IP  [proxy: ON ✓]"

    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# STEP 6 & 7: CF Rules & SSL (Using POST/Append to protect other VPSs)
# ═══════════════════════════════════════════════════════════════
setup_cloudflare_rules() {
    print_step "6" "Cloudflare Rules & SSL"
    CF_API="https://api.cloudflare.com/client/v4"

    # Origin Rule (Port Forwarding)
    EXISTING=$(curl -s -X GET -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" "$CF_API/zones/$CF_ZONE_ID/rulesets/phases/http_request_origin/entrypoint" 2>/dev/null)
    EXISTING_ID=$(echo "$EXISTING" | jq -r '.result.id // empty' 2>/dev/null)

    if [[ -n "$EXISTING_ID" ]]; then
        info "Appending to existing Origin Rules..."
        curl -s -X POST -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" "$CF_API/zones/$CF_ZONE_ID/rulesets/$EXISTING_ID/rules" -d "{\"action\": \"route\",\"action_parameters\": { \"origin\": { \"port\": 8080 } },\"expression\": \"http.host eq \\\"${CDN_DOMAIN}\\\"\",\"description\": \"X-Connect: ${CDN_DOMAIN}:443 → origin:8080\",\"enabled\": true}" >/dev/null
    else
        info "Creating new Origin Rules..."
        curl -s -X POST -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" "$CF_API/zones/$CF_ZONE_ID/rulesets" -d "{\"name\": \"X-Connect Origin Rules\",\"kind\": \"zone\",\"phase\": \"http_request_origin\",\"rules\": [{\"action\": \"route\",\"action_parameters\": { \"origin\": { \"port\": 8080 } },\"expression\": \"http.host eq \\\"${CDN_DOMAIN}\\\"\",\"description\": \"X-Connect: ${CDN_DOMAIN}:443 → origin:8080\",\"enabled\": true}]}" >/dev/null
    fi
    ok "Origin Rule added for $CDN_DOMAIN:443 → 8080"

    # Set SSL to Flexible
    curl -s -X PATCH -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" "$CF_API/zones/$CF_ZONE_ID/settings/ssl" -d "{\"value\":\"flexible\"}" >/dev/null
    ok "SSL/TLS Mode set to Flexible"

    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# STEP 8: Save Global Config
# ═══════════════════════════════════════════════════════════════
save_config() {
    # Saved to /etc so the PHP web server can read it
    CONFIG_FILE="/etc/xconnect.conf"
    cat > "$CONFIG_FILE" <<EOF
VPS_IP=$VPS_IP
PANEL_DOMAIN=$PANEL_DOMAIN
CDN_DOMAIN=$CDN_DOMAIN
EOF
    chmod 644 "$CONFIG_FILE"
}

# ═══════════════════════════════════════════════════════════════
# STEP 9: Setup PHP Web Transformer
# ═══════════════════════════════════════════════════════════════
setup_web_transformer() {
    print_step "9" "Hosting PHP Web Transformer"
    
    # Configure Apache to listen on port 8081 (prevents conflicts with 3x-ui SSL cert generation)
    sed -i 's/Listen 80/Listen 8081/g' /etc/apache2/ports.conf
    sed -i 's/<VirtualHost \*:80>/<VirtualHost \*:8081>/g' /etc/apache2/sites-available/000-default.conf
    systemctl restart apache2
    
    # Create the PHP App
    cat > /var/www/html/index.php << 'EOF'
<?php
// Auto-load CDN Domain from setup config
$cdn_domain = "Unknown (Check /etc/xconnect.conf)";
if (file_exists('/etc/xconnect.conf')) {
    $lines = file('/etc/xconnect.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'CDN_DOMAIN=') === 0) {
            $cdn_domain = substr($line, 11);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>X-Connect Transformer</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-300 font-sans min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-2xl bg-slate-900 border border-slate-800 rounded-xl p-6 md:p-8 shadow-2xl">
        
        <div class="text-center mb-8">
            <h1 class="text-2xl md:text-3xl font-bold text-white mb-2">X-Connect Link Transformer</h1>
            <p class="text-sm text-emerald-400 font-mono bg-emerald-950/30 inline-block px-3 py-1 rounded-full border border-emerald-900/50">
                Active CDN: <span id="sysCdn"><?= htmlspecialchars($cdn_domain) ?></span>
            </p>
        </div>

        <div class="space-y-6">
            <div>
                <label class="text-sm text-slate-400 mb-2 block">Original 3x-ui Link (Port 8080)</label>
                <textarea id="inputLink" rows="3" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-sm font-mono focus:outline-none focus:border-blue-500 transition-colors" placeholder="vless://..."></textarea>
            </div>

            <button onclick="transform()" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 rounded-lg transition-colors shadow-lg">
                Convert to CDN Link
            </button>

            <div>
                <label class="text-sm text-slate-400 mb-2 block">Transformed CDN Link (Port 443 / TLS)</label>
                <div class="relative">
                    <textarea id="outputLink" rows="4" readonly class="w-full bg-emerald-950/20 border border-emerald-900/50 rounded-lg p-3 text-sm font-mono text-emerald-300 outline-none pr-12"></textarea>
                    <button onclick="copyLink()" class="absolute top-2 right-2 p-2 bg-slate-900 hover:bg-slate-800 rounded-md border border-slate-700 text-slate-400 hover:text-white transition-colors" title="Copy">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function transform() {
            const cdn = document.getElementById('sysCdn').innerText;
            const input = document.getElementById('inputLink').value.trim();
            if(!input) return alert("Paste a link first.");
            
            try {
                const match = input.match(/^vless:\/\/([^@]+)@([^:]+):(\d+)(\?[^#]*)?(#.*)?$/);
                if(!match) throw new Error("Invalid VLESS link. Must start with vless://");
                
                const uuid = match[1];
                const queryRaw = match[4] || '';
                const hashRaw = match[5] || '';
                
                let params = new URLSearchParams(queryRaw.startsWith('?') ? queryRaw.substring(1) : queryRaw);
                params.set('security', 'tls');
                params.set('sni', cdn);
                params.set('host', cdn);
                params.set('type', 'ws');
                params.set('fp', 'chrome');
                
                document.getElementById('outputLink').value = `vless://${uuid}@${cdn}:443?${params.toString()}${hashRaw}`;
            } catch (e) {
                alert(e.message);
            }
        }

        function copyLink() {
            const out = document.getElementById('outputLink');
            out.select();
            document.execCommand("copy");
            alert("Copied to clipboard!");
        }
    </script>
</body>
</html>
EOF

    rm -f /var/www/html/index.html
    ok "PHP Transformer hosted on Apache (Port 8081)"
    print_end_step
}

# ═══════════════════════════════════════════════════════════════
# STEP 10: Final Firewall (Dynamic Port Reading)
# ═══════════════════════════════════════════════════════════════
setup_firewalls() {
    print_step "10" "Firewall Setup"

    open_port() {
        local port=$1
        if command -v ufw &>/dev/null; then ufw allow "$port/tcp" >/dev/null 2>&1; fi
        if command -v firewall-cmd &>/dev/null; then firewall-cmd --permanent --add-port="$port/tcp" >/dev/null 2>&1; fi
        iptables -I INPUT -p tcp --dport "$port" -j ACCEPT 2>/dev/null
    }

    # Open standard base ports + Web Transformer port
    open_port 80
    open_port 443
    open_port 8080
    open_port 8081
    
    # Open the dynamically detected 3x-ui port!
    if [[ -n "$PANEL_PORT" ]]; then
        open_port "$PANEL_PORT"
        ok "Opened Web Ports + Custom Panel Port ($PANEL_PORT)"
    else
        err "Could not detect panel port to open in firewall!"
    fi

    if command -v firewall-cmd &>/dev/null; then firewall-cmd --reload >/dev/null 2>&1; fi
    print_end_step
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

    echo -e "  ${BOLD}3x-ui Panel:${NC}      http://$PANEL_DOMAIN:$PANEL_PORT"
    echo -e "  ${DIM}(Use https:// if you successfully generated Let's Encrypt)${NC}"
    echo -e "  ${BOLD}PHP Transformer:${NC}  http://$PANEL_DOMAIN:8081"
    echo -e "  ${BOLD}CDN Domain:${NC}       $CDN_DOMAIN"
    echo ""
    echo -e "  ${YELLOW}Next — Install the auto Admin Panel if needed:${NC}"
    echo -e "  ${CYAN}bash <(curl -Ls https://raw.githubusercontent.com/x-cinema-pro/auto3/main/install_panel.sh)${NC}"
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
    setup_cloudflare_rules
    echo ""
    echo -e "  ${YELLOW}${BOLD}  DNS records created. Waiting 10s for propagation...${NC}"
    sleep 10
    
    # Open base ports (80) so Let's Encrypt can work during install
    setup_firewalls 
    
    setup_3xui
    read_xui_credentials
    save_config
    setup_web_transformer
    
    # Run firewall AGAIN now that we know exactly what custom port you chose
    setup_firewalls 
    print_summary
}

main
