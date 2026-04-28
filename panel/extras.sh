#!/bin/bash
# HERRAMIENTAS EXTRAS - Conectado a la API GeminTech
# Test velocidad, limpieza, TCP optimize via API

source /etc/gemintech/module.sh 2>/dev/null || source "$(dirname "$0")/module.sh"

# Test de velocidad
speed_test() {
    title "TEST DE VELOCIDAD"

    echo -e "  ${CYAN}Instalando speedtest-cli...${NC}"
    apt-get install -y speedtest-cli &>/dev/null || pip3 install speedtest-cli &>/dev/null

    if command -v speedtest-cli &>/dev/null; then
        echo -e "  ${YELLOW}Ejecutando test de velocidad...${NC}"
        bar2
        speedtest-cli
    else
        echo -e "  ${RED}No se pudo instalar speedtest-cli${NC}"
    fi
    enter
}

# Limpiar logs via API
limpiar_logs() {
    title "LIMPIAR LOGS DEL SISTEMA (API)"

    msg -yellow "  Limpiando logs via API..."
    bar2

    local response
    response=$(api_post "/sistema/limpiar-logs" "")

    if api_ok "$response"; then
        local detalle=$(json_field "$response" "detalle")
        msg -green "  ${detalle:-Logs limpiados correctamente}"
    else
        local error=$(api_error "$response")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Optimizar TCP via API
tcp_speed() {
    title "OPTIMIZADOR TCP SPEED (API)"

    # Verificar estado actual
    if grep -q "^#ADM_TCP" /etc/sysctl.conf 2>/dev/null; then
        echo -e "  ${GREEN}TCP Speed: [ACTIVADO]${NC}"
        bar2
        read -p "  Desea DESACTIVAR? [s/N]: " resp
        if [[ "$resp" == "s" || "$resp" == "S" ]]; then
            local response
            response=$(api_post "/sistema/tcp-optimize" "activar=0")
            if api_ok "$response"; then
                msg -yellow "  TCP Speed desactivado via API"
            else
                msg -red "  Error al desactivar"
            fi
        fi
    else
        echo -e "  ${RED}TCP Speed: [DESACTIVADO]${NC}"
        bar2
        read -p "  Desea ACTIVAR? [S/n]: " resp
        if [[ "$resp" != "n" && "$resp" != "N" ]]; then
            local response
            response=$(api_post "/sistema/tcp-optimize" "activar=1")
            if api_ok "$response"; then
                local detalle=$(json_field "$response" "detalle")
                msg -green "  ${detalle:-TCP Speed activado}"
            else
                msg -red "  Error al activar"
            fi
        fi
    fi
    enter
}

# Bloquear BitTorrent
bloquear_torrent() {
    title "BLOQUEAR BITTORRENT"

    if iptables -L | grep -q "torrent" 2>/dev/null; then
        echo -e "  ${GREEN}BitTorrent: [BLOQUEADO]${NC}"
        bar2
        read -p "  Desea DESBLOQUEAR? [s/N]: " resp
        if [[ "$resp" == "s" || "$resp" == "S" ]]; then
            iptables -D OUTPUT -p tcp --dport 6881:6889 -j DROP 2>/dev/null
            iptables -D OUTPUT -p udp --dport 6881:6889 -j DROP 2>/dev/null
            echo -e "  ${YELLOW}BitTorrent desbloqueado${NC}"
        fi
    else
        echo -e "  ${RED}BitTorrent: [PERMITIDO]${NC}"
        bar2
        read -p "  Desea BLOQUEAR? [S/n]: " resp
        if [[ "$resp" != "n" && "$resp" != "N" ]]; then
            iptables -A OUTPUT -p tcp --dport 6881:6889 -j DROP -m comment --comment "torrent"
            iptables -A OUTPUT -p udp --dport 6881:6889 -j DROP -m comment --comment "torrent"
            echo -e "  ${GREEN}BitTorrent bloqueado!${NC}"
        fi
    fi
    enter
}

# Ver uso de disco via API
uso_disco() {
    title "USO DE DISCO (API)"

    msg -yellow "  Obteniendo informacion via API..."
    bar2

    local response
    response=$(api_get "/sistema/disco")

    echo -e "  ${CYAN}Espacio en disco:${NC}"
    bar2

    # Parsear filesystem
    echo "$response" | grep -o '"[^"]*"' | grep -v '"filesystem"\|"directorios_grandes"' | tr -d '"' | while read -r line; do
        [[ -n "$line" ]] && echo "  $line"
    done

    bar
    enter
}

# Ver uso de memoria via API
uso_memoria() {
    title "USO DE MEMORIA (API)"

    msg -yellow "  Obteniendo informacion via API..."
    bar2

    local response
    response=$(api_get "/sistema/memoria")

    echo -e "  ${CYAN}Memoria RAM:${NC}"
    bar2

    # La API devuelve free -h output como string
    local mem_info=$(json_field "$response" "memoria")
    echo "$mem_info"

    bar
    enter
}

# Monitor de conexiones via API
monitor_conexiones() {
    title "MONITOR DE CONEXIONES (API)"

    msg -yellow "  Obteniendo conexiones via API..."
    bar2

    local response
    response=$(api_get "/usuarios/monitor")

    local activas=$(json_field "$response" "activas")
    echo -e "  ${GREEN}Conexiones activas: ${activas:-0}${NC}"
    bar2

    echo -e "  ${CYAN}Conexiones por usuario:${NC}"
    bar2

    while IFS= read -r line; do
        local u=$(echo "$line" | grep -o '"[^"]*":' | tr -d '":' | head -1)
        local c=$(echo "$line" | grep -o ':[0-9]*' | tr -d ':' | head -1)
        if [[ -n "$u" && -n "$c" && "$c" -gt 0 ]]; then
            echo -e "  ${GREEN}$u${NC}: $c conexion(es)"
        fi
    done <<< "$(echo "$response" | grep -o '"[^"]*":[0-9]*')"

    bar
    enter
}

# Instalar HTOP
instalar_htop() {
    title "MONITOR HTOP"

    if ! command -v htop &>/dev/null; then
        echo -e "  ${YELLOW}Instalando htop...${NC}"
        apt-get install -y htop &>/dev/null
    fi

    if command -v htop &>/dev/null; then
        htop
    else
        echo -e "  ${RED}No se pudo instalar htop${NC}"
        enter
    fi
}

# Reiniciar iptables
reiniciar_iptables() {
    title "REINICIAR IPTABLES"

    echo -e "  ${RED}ADVERTENCIA: Esto eliminara todas las reglas de firewall${NC}"
    bar2
    read -p "  Confirmar? [s/N]: " resp

    if [[ "$resp" == "s" || "$resp" == "S" ]]; then
        iptables -F
        iptables -X
        iptables -t nat -F
        iptables -t nat -X
        iptables -t mangle -F
        iptables -t mangle -X
        iptables -P INPUT ACCEPT
        iptables -P FORWARD ACCEPT
        iptables -P OUTPUT ACCEPT
        echo -e "  ${GREEN}Iptables reiniciado!${NC}"
    else
        echo -e "  ${YELLOW}Cancelado${NC}"
    fi
    enter
}

# Menu de herramientas extras
menu_extras() {
    while true; do
        title "HERRAMIENTAS EXTRAS"

        check_api_status
        bar2

        echo -e "  ${GREEN}[1]${NC} > Test de Velocidad"
        echo -e "  ${GREEN}[2]${NC} > Limpiar Logs (API)"
        echo -e "  ${GREEN}[3]${NC} > Optimizador TCP Speed (API)"
        echo -e "  ${GREEN}[4]${NC} > Bloquear BitTorrent"
        bar2
        echo -e "  ${GREEN}[5]${NC} > Ver Uso de Disco (API)"
        echo -e "  ${GREEN}[6]${NC} > Ver Uso de Memoria (API)"
        echo -e "  ${GREEN}[7]${NC} > Monitor de Conexiones (API)"
        echo -e "  ${GREEN}[8]${NC} > Monitor HTOP"
        bar2
        echo -e "  ${GREEN}[9]${NC} > Reiniciar Iptables"
        echo -e "  ${RED}[0]${NC} > Volver"

        bar
        read -p "  Opcion: " opc

        case $opc in
            1) speed_test ;;
            2) limpiar_logs ;;
            3) tcp_speed ;;
            4) bloquear_torrent ;;
            5) uso_disco ;;
            6) uso_memoria ;;
            7) monitor_conexiones ;;
            8) instalar_htop ;;
            9) reiniciar_iptables ;;
            0) return ;;
            *) msg -red "  Opcion invalida" && sleep 1 ;;
        esac
    done
}

[[ "${BASH_SOURCE[0]}" == "${0}" ]] && menu_extras
