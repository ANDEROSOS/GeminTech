#!/bin/bash
# GESTION DE SERVICIOS - Conectado a la API GeminTech
# Los servicios se instalan directamente en el VPS (requiere root)
# El estado y reinicio pasan por la API

source /etc/gemintech/module.sh 2>/dev/null || source "$(dirname "$0")/module.sh"

# Instalar Dropbear
install_dropbear() {
    title "INSTALAR DROPBEAR"

    if dpkg -l | grep -q dropbear; then
        msg -yellow "  Dropbear ya esta instalado"
        bar2
        read -p "  Desea reinstalar? [s/N]: " resp
        [[ "$resp" != "s" && "$resp" != "S" ]] && return
    fi

    read -p "  Puerto para Dropbear [442]: " puerto
    [[ -z $puerto ]] && puerto=442

    echo -e "  ${CYAN}Instalando Dropbear...${NC}"
    bar2

    apt-get update -y &>/dev/null
    apt-get install dropbear -y &>/dev/null

    # Configurar
    cat > /etc/default/dropbear <<EOF
NO_START=0
DROPBEAR_PORT=$puerto
DROPBEAR_EXTRA_ARGS=""
DROPBEAR_BANNER="/etc/dropbear/banner"
DROPBEAR_RECEIVE_WINDOW=65536
EOF

    # Banner
    echo "Bienvenido al servidor" > /etc/dropbear/banner

    # Iniciar servicio
    systemctl enable dropbear &>/dev/null
    systemctl restart dropbear &>/dev/null

    # Abrir puerto en firewall
    ufw allow $puerto/tcp &>/dev/null

    bar
    msg -green "  Dropbear instalado en puerto: $puerto"
    enter
}

# Instalar Stunnel (SSL)
install_stunnel() {
    title "INSTALAR STUNNEL (SSL)"

    if dpkg -l | grep -q stunnel4; then
        msg -yellow "  Stunnel ya esta instalado"
        bar2
        read -p "  Desea reinstalar? [s/N]: " resp
        [[ "$resp" != "s" && "$resp" != "S" ]] && return
    fi

    read -p "  Puerto SSL [443]: " puerto_ssl
    [[ -z $puerto_ssl ]] && puerto_ssl=443

    read -p "  Puerto destino (SSH) [22]: " puerto_dest
    [[ -z $puerto_dest ]] && puerto_dest=22

    echo -e "  ${CYAN}Instalando Stunnel...${NC}"
    bar2

    apt-get update -y &>/dev/null
    apt-get install stunnel4 openssl -y &>/dev/null

    # Generar certificado
    openssl genrsa -out /etc/stunnel/stunnel.key 2048 &>/dev/null
    openssl req -new -key /etc/stunnel/stunnel.key -x509 -days 1000 -out /etc/stunnel/stunnel.crt \
        -subj "/C=US/ST=State/L=City/O=VPS/CN=localhost" &>/dev/null
    cat /etc/stunnel/stunnel.key /etc/stunnel/stunnel.crt > /etc/stunnel/stunnel.pem

    # Configuracion
    cat > /etc/stunnel/stunnel.conf <<EOF
client = no
[SSL]
cert = /etc/stunnel/stunnel.pem
accept = $puerto_ssl
connect = 127.0.0.1:$puerto_dest
EOF

    # Habilitar
    sed -i 's/ENABLED=0/ENABLED=1/g' /etc/default/stunnel4 2>/dev/null

    systemctl enable stunnel4 &>/dev/null
    systemctl restart stunnel4 &>/dev/null

    ufw allow $puerto_ssl/tcp &>/dev/null

    bar
    msg -green "  Stunnel SSL instalado"
    msg -green "  Puerto SSL: $puerto_ssl -> $puerto_dest"
    enter
}

# Instalar Squid Proxy
install_squid() {
    title "INSTALAR SQUID PROXY"

    if dpkg -l | grep -q squid; then
        msg -yellow "  Squid ya esta instalado"
        bar2
        read -p "  Desea reinstalar? [s/N]: " resp
        [[ "$resp" != "s" && "$resp" != "S" ]] && return
    fi

    read -p "  Puerto para Squid [8080]: " puerto
    [[ -z $puerto ]] && puerto=8080

    echo -e "  ${CYAN}Instalando Squid...${NC}"
    bar2

    apt-get update -y &>/dev/null
    apt-get install squid -y &>/dev/null

    # Backup config original
    cp /etc/squid/squid.conf /etc/squid/squid.conf.bak 2>/dev/null

    # Nueva configuracion
    cat > /etc/squid/squid.conf <<EOF
http_port $puerto
acl localhost src 127.0.0.1/32
acl SSL_ports port 443
acl Safe_ports port 80
acl Safe_ports port 21
acl Safe_ports port 443
acl Safe_ports port 70
acl Safe_ports port 210
acl Safe_ports port 1025-65535
acl Safe_ports port 280
acl Safe_ports port 488
acl Safe_ports port 591
acl Safe_ports port 777
acl CONNECT method CONNECT
http_access allow all
forwarded_for off
via off
request_header_access Allow allow all
request_header_access Authorization allow all
request_header_access WWW-Authenticate allow all
request_header_access Proxy-Authorization allow all
request_header_access Proxy-Authenticate allow all
request_header_access Cache-Control allow all
request_header_access Content-Encoding allow all
request_header_access Content-Length allow all
request_header_access Content-Type allow all
request_header_access Date allow all
request_header_access Expires allow all
request_header_access Host allow all
request_header_access If-Modified-Since allow all
request_header_access Last-Modified allow all
request_header_access Location allow all
request_header_access Pragma allow all
request_header_access Accept allow all
request_header_access Accept-Charset allow all
request_header_access Accept-Encoding allow all
request_header_access Accept-Language allow all
request_header_access Content-Language allow all
request_header_access Mime-Version allow all
request_header_access Retry-After allow all
request_header_access Title allow all
request_header_access Connection allow all
request_header_access Proxy-Connection allow all
request_header_access User-Agent allow all
request_header_access Cookie allow all
request_header_access All deny all
EOF

    systemctl enable squid &>/dev/null
    systemctl restart squid &>/dev/null

    ufw allow $puerto/tcp &>/dev/null

    bar
    msg -green "  Squid instalado en puerto: $puerto"
    enter
}

# Instalar BadVPN
install_badvpn() {
    title "INSTALAR BADVPN-UDPGW"

    read -p "  Puerto UDP [7300]: " puerto
    [[ -z $puerto ]] && puerto=7300

    echo -e "  ${CYAN}Instalando BadVPN...${NC}"
    bar2

    # Descargar badvpn desde GitHub
    wget -q -O /usr/bin/badvpn-udpgw "https://raw.githubusercontent.com/ANDEROSOS/gemintechs-vps/main/bin/badvpn-udpgw" 2>/dev/null

    # Si falla, intentar desde el directorio local
    if [[ ! -f /usr/bin/badvpn-udpgw ]] && [[ -f "/etc/gemintech/badvpn-udpgw" ]]; then
        cp /etc/gemintech/badvpn-udpgw /usr/bin/badvpn-udpgw
    fi

    if [[ ! -f /usr/bin/badvpn-udpgw ]]; then
        msg -red "  Error: No se pudo instalar BadVPN."
        return
    fi

    chmod +x /usr/bin/badvpn-udpgw

    # Crear servicio
    cat > /etc/systemd/system/badvpn.service <<EOF
[Unit]
Description=BadVPN UDPGW
After=network.target

[Service]
ExecStart=/usr/bin/badvpn-udpgw --listen-addr 127.0.0.1:$puerto --max-clients 500
Restart=always

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable badvpn &>/dev/null
    systemctl start badvpn &>/dev/null

    bar
    msg -green "  BadVPN instalado en puerto: $puerto"
    enter
}

# Instalar WebSocket SSH (Python)
install_ws_python() {
    title "INSTALAR SSH WEBSOCKET (Python)"

    read -p "  Puerto WebSocket [80]: " WS_PORT
    [[ -z $WS_PORT ]] && WS_PORT=80

    read -p "  Puerto SSH destino [22]: " SSH_PORT
    [[ -z $SSH_PORT ]] && SSH_PORT=22

    echo -e "  ${CYAN}Instalando dependencias...${NC}"
    bar2

    apt-get update -y &>/dev/null
    apt-get install -y python3 python3-pip screen &>/dev/null

    # Crear script WebSocket
    cat > /usr/local/bin/ws-ssh.py <<'WSEOF'
#!/usr/bin/env python3
import socket
import threading
import sys
import select

# Configuracion
LISTEN_PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 80
SSH_PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 22

def handle_client(client_socket):
    try:
        request = client_socket.recv(8192).decode('utf-8', errors='ignore')

        # Responder con 101 Switching Protocols
        if 'HTTP' in request or 'CONNECT' in request:
            response = "HTTP/1.1 101 Switching Protocols\r\n"
            response += "Upgrade: websocket\r\n"
            response += "Connection: Upgrade\r\n\r\n"
            client_socket.send(response.encode())

        # Conectar a SSH local
        ssh_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        ssh_socket.connect(('127.0.0.1', SSH_PORT))

        # Relay bidireccional
        sockets = [client_socket, ssh_socket]
        while True:
            readable, _, exceptional = select.select(sockets, [], sockets, 1)
            if exceptional:
                break
            for sock in readable:
                data = sock.recv(65536)
                if not data:
                    return
                if sock is client_socket:
                    ssh_socket.send(data)
                else:
                    client_socket.send(data)
    except Exception as e:
        pass
    finally:
        try:
            client_socket.close()
            ssh_socket.close()
        except:
            pass

def main():
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(('0.0.0.0', LISTEN_PORT))
    server.listen(100)
    print(f"[*] WebSocket SSH listening on port {LISTEN_PORT} -> SSH {SSH_PORT}")

    while True:
        client, addr = server.accept()
        thread = threading.Thread(target=handle_client, args=(client,))
        thread.daemon = True
        thread.start()

if __name__ == "__main__":
    main()
WSEOF

    chmod +x /usr/local/bin/ws-ssh.py

    # Crear servicio systemd
    cat > /etc/systemd/system/ws-ssh.service <<EOF
[Unit]
Description=WebSocket SSH Tunnel
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/ws-ssh.py $WS_PORT $SSH_PORT
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable ws-ssh &>/dev/null
    systemctl restart ws-ssh &>/dev/null

    # Abrir puerto
    ufw allow $WS_PORT/tcp &>/dev/null

    bar
    msg -green "  WebSocket SSH instalado!"
    bar2
    echo -e "  ${YELLOW}Puerto WS:${NC} $WS_PORT"
    echo -e "  ${YELLOW}Puerto SSH:${NC} $SSH_PORT"
    echo -e "  ${YELLOW}Estado:${NC} $(systemctl is-active ws-ssh)"
    bar
    enter
}

# Gestionar puertos SSH
gestionar_ssh() {
    title "GESTIONAR PUERTOS SSH"

    echo -e "  ${YELLOW}Puertos SSH actuales:${NC}"
    grep -E "^Port" /etc/ssh/sshd_config 2>/dev/null || echo "  22 (default)"
    bar2

    read -p "  Nuevos puertos (separados por espacio): " puertos
    [[ -z $puertos ]] && return

    # Eliminar puertos actuales
    sed -i '/^Port/d' /etc/ssh/sshd_config

    # Agregar nuevos puertos
    for p in $puertos; do
        echo "Port $p" >> /etc/ssh/sshd_config
        ufw allow $p/tcp &>/dev/null
    done

    systemctl restart sshd &>/dev/null || systemctl restart ssh &>/dev/null

    msg -green "  Puertos SSH configurados: $puertos"
    enter
}

# Reiniciar servicio via API
reiniciar_servicio_api() {
    title "REINICIAR SERVICIO VIA API"

    # Obtener estado de servicios via API
    msg -yellow "  Obteniendo estado de servicios..."
    local response
    response=$(api_get "/servicios/estado")

    echo -e "  ${CYAN}Servicios:${NC}"
    bar2

    while IFS= read -r line; do
        local svc=$(echo "$line" | grep -o '"servicio":"[^"]*"' | cut -d'"' -f4)
        local active=$(echo "$line" | grep -o '"activo":[^,}]*' | cut -d':' -f2)
        if [[ -n "$svc" ]]; then
            if [[ "$active" == "true" ]]; then
                printf "  %-20s ${GREEN}[ACTIVO]${NC}\n" "$svc"
            else
                printf "  %-20s ${RED}[INACTIVO]${NC}\n" "$svc"
            fi
        fi
    done <<< "$(echo "$response" | grep -o '{[^}]*}')"

    bar2
    read -p "  Servicio a reiniciar: " servicio
    [[ -z $servicio ]] && return

    local resp
    resp=$(api_post "/servicios/reiniciar" "servicio=${servicio}")

    if api_ok "$resp"; then
        local estado=$(json_field "$resp" "estado")
        msg -green "  Servicio $servicio reiniciado. Estado: $estado"
    else
        local error=$(api_error "$resp")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Estado de servicios via API
estado_servicios_api() {
    title "ESTADO DE SERVICIOS (API)"

    msg -yellow "  Obteniendo estado via API..."
    bar2

    local response
    response=$(api_get "/servicios/estado")

    echo -e "  ${CYAN}Estado de servicios:${NC}"
    bar2

    while IFS= read -r line; do
        local svc=$(echo "$line" | grep -o '"servicio":"[^"]*"' | cut -d'"' -f4)
        local active=$(echo "$line" | grep -o '"activo":[^,}]*' | cut -d':' -f2)
        local enabled=$(echo "$line" | grep -o '"auto_inicio":[^,}]*' | cut -d':' -f2)
        if [[ -n "$svc" ]]; then
            if [[ "$active" == "true" ]]; then
                printf "  %-20s ${GREEN}[ACTIVO]${NC}" "$svc"
            else
                printf "  %-20s ${RED}[INACTIVO]${NC}" "$svc"
            fi
            if [[ "$enabled" == "true" ]]; then
                echo -e "  ${CYAN}(Auto-inicio: Si)${NC}"
            else
                echo -e "  ${YELLOW}(Auto-inicio: No)${NC}"
            fi
        fi
    done <<< "$(echo "$response" | grep -o '{[^}]*}')"

    bar
    enter
}

# Desinstalar servicio
desinstalar_servicio() {
    title "DESINSTALAR SERVICIO"

    echo -e "  ${GREEN}[1]${NC} > Dropbear"
    echo -e "  ${GREEN}[2]${NC} > Stunnel (SSL)"
    echo -e "  ${GREEN}[3]${NC} > Squid"
    echo -e "  ${GREEN}[4]${NC} > BadVPN"
    echo -e "  ${GREEN}[5]${NC} > WebSocket SSH"
    echo -e "  ${RED}[0]${NC} > Volver"

    bar
    read -p "  Opcion: " opc

    case $opc in
        1)
            apt-get purge dropbear -y &>/dev/null
            msg -green "  Dropbear desinstalado"
            ;;
        2)
            apt-get purge stunnel4 -y &>/dev/null
            msg -green "  Stunnel desinstalado"
            ;;
        3)
            apt-get purge squid -y &>/dev/null
            msg -green "  Squid desinstalado"
            ;;
        4)
            systemctl stop badvpn &>/dev/null
            systemctl disable badvpn &>/dev/null
            rm -f /usr/bin/badvpn-udpgw /etc/systemd/system/badvpn.service
            systemctl daemon-reload
            msg -green "  BadVPN desinstalado"
            ;;
        5)
            systemctl stop ws-ssh &>/dev/null
            systemctl disable ws-ssh &>/dev/null
            rm -f /usr/local/bin/ws-ssh.py /etc/systemd/system/ws-ssh.service
            systemctl daemon-reload
            msg -green "  WebSocket SSH desinstalado"
            ;;
        0) return ;;
    esac
    enter
}

# Menu de servicios
menu_servicios() {
    while true; do
        title "GESTION DE SERVICIOS"

        check_api_status
        bar2

        echo -e "  ${GREEN}[1]${NC} > Instalar Dropbear"
        echo -e "  ${GREEN}[2]${NC} > Instalar Stunnel (SSL)"
        echo -e "  ${GREEN}[3]${NC} > Instalar Squid Proxy"
        echo -e "  ${GREEN}[4]${NC} > Instalar BadVPN"
        echo -e "  ${GREEN}[5]${NC} > Instalar WebSocket (Python)"
        echo -e "  ${GREEN}[6]${NC} > Gestionar puertos SSH"
        bar2
        echo -e "  ${GREEN}[7]${NC} > Estado de servicios (API)"
        echo -e "  ${GREEN}[8]${NC} > Reiniciar servicio (API)"
        echo -e "  ${GREEN}[9]${NC} > Desinstalar servicio"
        echo -e "  ${RED}[0]${NC} > Volver"

        bar
        read -p "  Opcion: " opc

        case $opc in
            1) install_dropbear ;;
            2) install_stunnel ;;
            3) install_squid ;;
            4) install_badvpn ;;
            5) install_ws_python ;;
            6) gestionar_ssh ;;
            7) estado_servicios_api ;;
            8) reiniciar_servicio_api ;;
            9) desinstalar_servicio ;;
            0) return ;;
            *) msg -red "  Opcion invalida" && sleep 1 ;;
        esac
    done
}

[[ "${BASH_SOURCE[0]}" == "${0}" ]] && menu_servicios
