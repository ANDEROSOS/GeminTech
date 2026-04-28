#!/bin/bash
# CONFIGURACION DEL SCRIPT - Conectado a la API GeminTech
# Funciones de configuración del VPS y la API

source /etc/gemintech/module.sh 2>/dev/null || source "$(dirname "$0")/module.sh"

# Cambiar hostname
cambiar_hostname() {
    title "CAMBIAR HOSTNAME"

    echo -e "  ${CYAN}Hostname actual: ${WHITE}$(hostname)${NC}"
    bar2
    read -p "  Nuevo hostname: " nuevo_host

    if [[ -n "$nuevo_host" ]]; then
        hostnamectl set-hostname "$nuevo_host"
        echo -e "  ${GREEN}Hostname cambiado a: $nuevo_host${NC}"
        echo -e "  ${YELLOW}Reinicie el VPS para aplicar completamente${NC}"
    else
        echo -e "  ${RED}Hostname vacio, cancelado${NC}"
    fi
    enter
}

# Cambiar zona horaria
cambiar_timezone() {
    title "CAMBIAR ZONA HORARIA"

    echo -e "  ${CYAN}Zona horaria actual: ${WHITE}$(timedatectl | grep "Time zone" | awk '{print $3}')${NC}"
    echo -e "  ${CYAN}Hora actual: ${WHITE}$(date)${NC}"
    bar2

    echo -e "  ${GREEN}[1]${NC} > Mexico (America/Mexico_City)"
    echo -e "  ${GREEN}[2]${NC} > Argentina (America/Buenos_Aires)"
    echo -e "  ${GREEN}[3]${NC} > Colombia (America/Bogota)"
    echo -e "  ${GREEN}[4]${NC} > Peru (America/Lima)"
    echo -e "  ${GREEN}[5]${NC} > Chile (America/Santiago)"
    echo -e "  ${GREEN}[6]${NC} > Ecuador (America/Guayaquil)"
    echo -e "  ${GREEN}[7]${NC} > Venezuela (America/Caracas)"
    echo -e "  ${GREEN}[8]${NC} > Espana (Europe/Madrid)"
    echo -e "  ${GREEN}[9]${NC} > USA Eastern (America/New_York)"
    echo -e "  ${RED}[0]${NC} > Volver"

    bar
    read -p "  Opcion: " opc

    case $opc in
        1) timedatectl set-timezone America/Mexico_City ;;
        2) timedatectl set-timezone America/Buenos_Aires ;;
        3) timedatectl set-timezone America/Bogota ;;
        4) timedatectl set-timezone America/Lima ;;
        5) timedatectl set-timezone America/Santiago ;;
        6) timedatectl set-timezone America/Guayaquil ;;
        7) timedatectl set-timezone America/Caracas ;;
        8) timedatectl set-timezone Europe/Madrid ;;
        9) timedatectl set-timezone America/New_York ;;
        0) return ;;
        *) echo -e "  ${RED}Opcion invalida${NC}"; sleep 1; return ;;
    esac

    echo -e "\n  ${GREEN}Zona horaria cambiada!${NC}"
    echo -e "  ${CYAN}Nueva hora: ${WHITE}$(date)${NC}"
    enter
}

# Reiniciar servicios via API
reiniciar_servicios() {
    title "REINICIAR SERVICIOS"

    echo -e "  ${YELLOW}Reiniciando servicios via API...${NC}"
    bar2

    services="ssh dropbear stunnel4 squid badvpn ws-ssh"

    for svc in $services; do
        if systemctl is-enabled "$svc" &>/dev/null; then
            local resp
            resp=$(api_post "/servicios/reiniciar" "servicio=${svc}")
            if api_ok "$resp"; then
                echo -e "  $svc: ${GREEN}[OK]${NC}"
            else
                echo -e "  $svc: ${RED}[FAIL]${NC}"
            fi
        fi
    done

    bar
    echo -e "  ${GREEN}Servicios reiniciados${NC}"
    enter
}

# Ver estado de servicios via API
estado_servicios() {
    title "ESTADO DE SERVICIOS (API)"

    msg -yellow "  Consultando API..."
    bar2

    local response
    response=$(api_get "/servicios/estado")

    echo -e "  ${CYAN}Estado de servicios:${NC}"
    bar2

    while IFS= read -r line; do
        local svc=$(echo "$line" | grep -o '"servicio":"[^"]*"' | cut -d'"' -f4)
        local active=$(echo "$line" | grep -o '"activo":[^,}]*' | cut -d':' -f2)
        if [[ -n "$svc" ]]; then
            if [[ "$active" == "true" ]]; then
                printf "  %-25s ${GREEN}[ACTIVO]${NC}\n" "$svc"
            else
                printf "  %-25s ${RED}[INACTIVO]${NC}\n" "$svc"
            fi
        fi
    done <<< "$(echo "$response" | grep -o '{[^}]*}')"

    bar
    enter
}

# Ver puertos abiertos via API
ver_puertos() {
    title "PUERTOS ABIERTOS (API)"

    msg -yellow "  Consultando API..."
    bar2

    local response
    response=$(api_get "/servicios/puertos")

    echo -e "  ${CYAN}Puertos en escucha:${NC}"
    bar2

    echo "$response" | grep -o '"[^"]*"' | grep -v '"puertos"' | tr -d '"' | while read -r line; do
        [[ -n "$line" ]] && echo "  $line"
    done

    bar
    enter
}

# Actualizar sistema
actualizar_sistema() {
    title "ACTUALIZAR SISTEMA"

    echo -e "  ${YELLOW}Actualizando repositorios...${NC}"
    bar2
    apt-get update -y

    bar
    echo -e "  ${YELLOW}Actualizando paquetes...${NC}"
    bar2
    apt-get upgrade -y

    bar
    echo -e "  ${GREEN}Sistema actualizado!${NC}"
    enter
}

# Cambiar password root
cambiar_pass_root() {
    title "CAMBIAR PASSWORD ROOT"

    echo -e "  ${RED}ADVERTENCIA: Cambiara la contrasena de root${NC}"
    bar2
    read -p "  Continuar? [s/N]: " resp

    if [[ "$resp" == "s" || "$resp" == "S" ]]; then
        passwd root
    else
        echo -e "  ${YELLOW}Cancelado${NC}"
    fi
    enter
}

# Informacion del sistema via API
info_sistema() {
    title "INFORMACION DEL SISTEMA (API)"

    msg -yellow "  Obteniendo informacion via API..."
    bar2

    local response
    response=$(api_get "/sistema/info")

    local so=$(json_field "$response" "so")
    local kernel=$(json_field "$response" "kernel")
    local arch=$(json_field "$response" "arquitectura")
    local hostname=$(json_field "$response" "hostname")
    local uptime=$(json_field "$response" "uptime")
    local cpu_model=$(json_field "$response" "modelo")
    local cpu_cores=$(json_field "$response" "nucleos")
    local mem_total=$(json_field "$response" "total")
    local mem_used=$(json_field "$response" "usada")
    local disk_pct=$(json_field "$response" "porcentaje")
    local vps_ip=$(json_field "$response" "vps_ip")

    echo -e "  ${CYAN}Sistema Operativo:${NC} $so"
    echo -e "  ${CYAN}Kernel:${NC} $kernel"
    echo -e "  ${CYAN}Arquitectura:${NC} $arch"
    echo -e "  ${CYAN}Hostname:${NC} $hostname"
    echo -e "  ${CYAN}Uptime:${NC} $uptime"
    bar2
    echo -e "  ${CYAN}CPU:${NC} $cpu_model"
    echo -e "  ${CYAN}Nucleos:${NC} $cpu_cores"
    bar2
    echo -e "  ${CYAN}Memoria:${NC} $mem_used / $mem_total"
    echo -e "  ${CYAN}Disco usado:${NC} $disk_pct"
    bar2
    echo -e "  ${CYAN}IP del VPS:${NC} $vps_ip"

    bar
    enter
}

# Configurar API (cambiar IP, puerto, key)
configurar_api() {
    title "CONFIGURAR API"

    echo -e "  ${CYAN}Configuracion actual:${NC}"
    bar2
    echo -e "  ${YELLOW}API Base:${NC} ${API_BASE}"
    echo -e "  ${YELLOW}API Key:${NC} ${API_KEY}"
    echo -e "  ${YELLOW}API Puerto:${NC} ${API_PORT}"
    echo -e "  ${YELLOW}VPS IP:${NC} ${VPS_IP}"
    bar2

    echo -e "  ${GREEN}[1]${NC} > Cambiar IP del VPS"
    echo -e "  ${GREEN}[2]${NC} > Cambiar API Key"
    echo -e "  ${GREEN}[3]${NC} > Cambiar Puerto API"
    echo -e "  ${GREEN}[4]${NC} > Guardar configuracion"
    echo -e "  ${RED}[0]${NC} > Volver"

    bar
    read -p "  Opcion: " opc

    case $opc in
        1)
            read -p "  Nueva IP del VPS: " nueva_ip
            if [[ -n "$nueva_ip" ]]; then
                export VPS_IP="$nueva_ip"
                export API_BASE="http://127.0.0.1:${API_PORT}"
                msg -green "  IP cambiada a: $nueva_ip"
                msg -yellow "  Use opcion 4 para guardar permanentemente"
            fi
            ;;
        2)
            read -p "  Nueva API Key: " nueva_key
            if [[ -n "$nueva_key" ]]; then
                export API_KEY="$nueva_key"
                msg -green "  API Key cambiada"
                msg -yellow "  Use opcion 4 para guardar permanentemente"
            fi
            ;;
        3)
            read -p "  Nuevo puerto API: " nuevo_puerto
            if [[ -n "$nuevo_puerto" ]]; then
                export API_PORT="$nuevo_puerto"
                export API_BASE="http://127.0.0.1:${nuevo_puerto}"
                msg -green "  Puerto API cambiado a: $nuevo_puerto"
                msg -yellow "  Use opcion 4 para guardar permanentemente"
            fi
            ;;
        4)
            # Guardar en .env
            cat > "${VPS_DIR}/.env" <<EOF
# GeminTech API Configuracion
API_KEY=${API_KEY}
API_PORT=${API_PORT}
API_BASE=${API_BASE}
VPS_IP=${VPS_IP}
WS_KEY=${WS_KEY:-Ecuador2026}
VPS_DIR=${VPS_DIR}
EOF
            msg -green "  Configuracion guardada en ${VPS_DIR}/.env"
            ;;
        0) return ;;
    esac
    enter
}

# Menu de configuracion
menu_config() {
    while true; do
        title "CONFIGURACION DEL SCRIPT"

        check_api_status
        bar2

        echo -e "  ${GREEN}[1]${NC} > Cambiar Hostname"
        echo -e "  ${GREEN}[2]${NC} > Cambiar Zona Horaria"
        echo -e "  ${GREEN}[3]${NC} > Reiniciar Servicios (API)"
        bar2
        echo -e "  ${GREEN}[4]${NC} > Estado de Servicios (API)"
        echo -e "  ${GREEN}[5]${NC} > Ver Puertos Abiertos (API)"
        echo -e "  ${GREEN}[6]${NC} > Actualizar Sistema"
        bar2
        echo -e "  ${GREEN}[7]${NC} > Cambiar Password Root"
        echo -e "  ${GREEN}[8]${NC} > Informacion del Sistema (API)"
        echo -e "  ${MAGENTA}[9]${NC} > Configurar API (IP, Key, Puerto)"
        echo -e "  ${RED}[0]${NC} > Volver"

        bar
        read -p "  Opcion: " opc

        case $opc in
            1) cambiar_hostname ;;
            2) cambiar_timezone ;;
            3) reiniciar_servicios ;;
            4) estado_servicios ;;
            5) ver_puertos ;;
            6) actualizar_sistema ;;
            7) cambiar_pass_root ;;
            8) info_sistema ;;
            9) configurar_api ;;
            0) return ;;
            *) msg -red "  Opcion invalida" && sleep 1 ;;
        esac
    done
}

[[ "${BASH_SOURCE[0]}" == "${0}" ]] && menu_config
