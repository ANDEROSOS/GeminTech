#!/bin/bash
# ============================================
# INSTALADOR GeminTech VPS Panel v2.0
# Sistema propio de gestión VPS
# GitHub: https://github.com/AdmRufus/GeminTech
# ============================================

INSTALL_DIR="/etc/gemintech"
API_DIR="/opt/gemintech/api"
REPO_URL="https://raw.githubusercontent.com/AdmRufus/GeminTech/main"

# Colores
RED='\033[1;31m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'
CYAN='\033[1;36m'; WHITE='\033[1;37m'; NC='\033[0m'

banner_install() {
    clear
    echo -e "${CYAN}"
    echo "   ██████╗ ███████╗███╗   ███╗██╗███╗   ██╗"
    echo "  ██╔════╝ ██╔════╝████╗ ████║██║████╗  ██║"
    echo "  ██║  ███╗█████╗  ██╔████╔██║██║██╔██╗ ██║"
    echo "  ██║   ██║██╔══╝  ██║╚██╔╝██║██║██║╚██╗██║"
    echo "  ╚██████╔╝███████╗██║ ╚═╝ ██║██║██║ ╚████║"
    echo "   ╚═════╝ ╚══════╝╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝"
    echo -e "${WHITE}  ████████╗███████╗ ██████╗██╗  ██╗"
    echo "     ██╔══╝██╔════╝██╔════╝██║  ██║"
    echo "     ██║   █████╗  ██║     ███████║"
    echo "     ██║   ██╔══╝  ██║     ██╔══██║"
    echo "     ██║   ███████╗╚██████╗██║  ██║"
    echo -e "     ╚═╝   ╚══════╝ ╚═════╝╚═╝  ╚═╝${NC}"
    echo -e "${CYAN}══════════════════════════════════════════════${NC}"
    echo -e "  ${WHITE}Instalador GeminTech VPS Panel v2.0${NC}"
    echo -e "${CYAN}══════════════════════════════════════════════${NC}"
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        echo -e "${RED}  ✗ Este script debe ejecutarse como root (sudo -i)${NC}"
        exit 1
    fi
}

msg_ok()  { echo -e "  ${GREEN}✓${NC} $1"; }
msg_err() { echo -e "  ${RED}✗${NC} $1"; }
msg_info(){ echo -e "  ${YELLOW}→${NC} $1"; }

# ─── Paso 1: Dependencias ─────────────────────────────────────
install_deps() {
    msg_info "Actualizando paquetes..."
    apt-get update -qq
    apt-get install -y -qq curl wget git openssh-server python3 python3-pip openssl 2>/dev/null
    msg_ok "Dependencias instaladas"
}

# ─── Paso 2: Crear estructura de directorios ──────────────────
setup_dirs() {
    msg_info "Creando directorios GeminTech..."
    mkdir -p "${INSTALL_DIR}"/{user,tmp,bin}
    chmod 777 "${INSTALL_DIR}/tmp"
    msg_ok "Directorios creados en ${INSTALL_DIR}"
}

# ─── Paso 3: Descargar scripts del panel ──────────────────────
download_panel() {
    msg_info "Descargando scripts del panel..."
    for script in module.sh user.sh services.sh extras.sh config.sh cleanup.sh menu; do
        curl -sL "${REPO_URL}/panel/${script}" -o "${INSTALL_DIR}/${script}"
        chmod +x "${INSTALL_DIR}/${script}"
    done
    msg_ok "Scripts descargados en ${INSTALL_DIR}"
}

# ─── Paso 4: Instalar BadVPN ──────────────────────────────────
install_badvpn() {
    if command -v badvpn-udpgw &>/dev/null; then
        msg_ok "BadVPN ya está instalado"
    else
        msg_info "Compilando BadVPN desde código fuente..."
        apt-get install -y -qq cmake build-essential wget unzip 2>/dev/null

        cd /tmp
        wget -qO badvpn.zip https://github.com/ambrop72/badvpn/archive/master.zip
        unzip -q badvpn.zip
        mkdir -p badvpn-master/build && cd badvpn-master/build
        cmake .. -DCMAKE_INSTALL_PREFIX=/usr \
                 -DBUILD_NOTHING_BY_DEFAULT=1 \
                 -DBUILD_UDPGW=1 -q 2>/dev/null
        make -j$(nproc) 2>/dev/null && make install 2>/dev/null
        cd /tmp && rm -rf badvpn.zip badvpn-master
    fi

    mkdir -p "${INSTALL_DIR}/bin"
    [[ -f /usr/bin/badvpn-udpgw ]] && cp /usr/bin/badvpn-udpgw "${INSTALL_DIR}/bin/"

    # Instalar servicio systemd
    cat > /etc/systemd/system/gemintech-badvpn.service << EOF
[Unit]
Description=GeminTech BadVPN UDP Gateway
After=network.target

[Service]
Type=simple
User=root
ExecStart=${INSTALL_DIR}/bin/badvpn-udpgw --listen-addr 127.0.0.1:7300 --max-clients 500
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable gemintech-badvpn
    systemctl start gemintech-badvpn
    msg_ok "BadVPN instalado (puerto 7300)"
}

# ─── Paso 5: WebSocket SSH ────────────────────────────────────
install_websocket() {
    cat > /usr/local/bin/ws-ssh.py << 'PYEOF'
#!/usr/bin/env python3
"""GeminTech WebSocket SSH Bridge"""
import asyncio, websockets, socket, sys

LISTEN_PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 80
SSH_PORT    = int(sys.argv[2]) if len(sys.argv) > 2 else 22

async def handle(ws):
    try:
        sock = socket.socket()
        sock.connect(('127.0.0.1', SSH_PORT))
        sock.setblocking(False)
        loop = asyncio.get_event_loop()
        async def ws2ssh():
            async for d in ws: sock.send(d if isinstance(d,bytes) else d.encode())
        async def ssh2ws():
            while True:
                data = await loop.sock_recv(sock, 4096)
                if not data: break
                await ws.send(data)
        await asyncio.gather(ws2ssh(), ssh2ws())
    finally:
        sock.close()

async def main():
    async with websockets.serve(handle, '0.0.0.0', LISTEN_PORT):
        await asyncio.Future()

asyncio.run(main())
PYEOF
    chmod +x /usr/local/bin/ws-ssh.py
    pip3 install websockets -q 2>/dev/null

    cat > /etc/systemd/system/gemintech-ws.service << EOF
[Unit]
Description=GeminTech WebSocket SSH Bridge
After=network.target sshd.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/ws-ssh.py ${LISTEN_PORT:-80} 22
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable gemintech-ws
    systemctl start gemintech-ws
    msg_ok "WebSocket SSH instalado (puerto 80)"
}

# ─── Paso 6: Instalar API FastAPI ─────────────────────────────
install_api() {
    msg_info "Instalando GeminTech API..."
    mkdir -p "${API_DIR}"
    curl -sL "${REPO_URL}/api/main.py"          -o "${API_DIR}/main.py"
    curl -sL "${REPO_URL}/api/requirements.txt" -o "${API_DIR}/requirements.txt"

    # Variables de entorno de la API
    if [[ ! -f "${API_DIR}/.env" ]]; then
        cat > "${API_DIR}/.env" << EOF
API_KEY=Ecuador2026_Secreto_Api
API_PORT=9000
VPS_DIR=/etc/gemintech
VPS_IP=$(curl -s ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
EOF
    fi

    pip3 install -r "${API_DIR}/requirements.txt" -q

    cat > /etc/systemd/system/gemintech-api.service << EOF
[Unit]
Description=GeminTech FastAPI Backend
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${API_DIR}
ExecStart=/usr/bin/python3 -m uvicorn main:app --host 0.0.0.0 --port 9000 --workers 1
Restart=always
RestartSec=5
EnvironmentFile=${API_DIR}/.env
SyslogIdentifier=gemintech-api

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable gemintech-api
    systemctl start gemintech-api
    msg_ok "API FastAPI instalada (puerto 9000)"
}

# ─── Paso 7: Crear comando global ─────────────────────────────
setup_command() {
    ln -sf "${INSTALL_DIR}/menu" /usr/local/bin/gemintech
    chmod +x /usr/local/bin/gemintech
    msg_ok "Comando 'gemintech' disponible globalmente"
}

# ─── Main ─────────────────────────────────────────────────────
main() {
    banner_install
    check_root

    echo ""
    msg_info "Iniciando instalación de GeminTech VPS Panel..."
    echo ""

    install_deps
    setup_dirs
    download_panel
    install_badvpn
    install_websocket
    install_api
    setup_command

    echo ""
    echo -e "${CYAN}══════════════════════════════════════════════${NC}"
    msg_ok "¡Instalación completada!"
    echo ""
    echo -e "  ${WHITE}Ejecuta:${NC} ${CYAN}gemintech${NC}"
    echo ""
    echo -e "  ${WHITE}Panel Web:${NC}"
    echo -e "    API URL: http://$(curl -s ifconfig.me 2>/dev/null):9000"
    echo -e "    API Key: Ecuador2026_Secreto_Api"
    echo -e "${CYAN}══════════════════════════════════════════════${NC}"
}

main
