#!/bin/bash
# ============================================
# GeminTech VPS Panel — Módulo de Funciones v3.0
# Sistema propio de gestión VPS
# CONECTADO A LA API FastAPI
# ============================================

# Colores
export RED='\033[1;31m'
export GREEN='\033[1;32m'
export YELLOW='\033[1;33m'
export BLUE='\033[1;34m'
export CYAN='\033[1;36m'
export MAGENTA='\033[1;35m'
export WHITE='\033[1;37m'
export NC='\033[0m'

# ─── Directorios GeminTech ────────────────────────────────────
export VPS_DIR="/etc/gemintech"
export VPS_USER="${VPS_DIR}/user"
export VPS_TMP="${VPS_DIR}/tmp"

# Crear directorios si no existen
[[ ! -d "${VPS_DIR}"  ]] && mkdir -p "${VPS_DIR}"
[[ ! -d "${VPS_USER}" ]] && mkdir -p "${VPS_USER}"
[[ ! -d "${VPS_TMP}"  ]] && mkdir -p "${VPS_TMP}" && chmod 777 "${VPS_TMP}"

# ─── Configuración API ────────────────────────────────────────
# Cargar configuración desde archivo .env o usar defaults
load_api_config() {
    if [[ -f "${VPS_DIR}/.env" ]]; then
        source "${VPS_DIR}/.env"
    fi

    export API_KEY="${API_KEY:-Ecuador2026_Secreto_Api}"
    export API_PORT="${API_PORT:-9000}"
    export API_BASE="${API_BASE:-http://127.0.0.1:${API_PORT}}"
    export VPS_IP="${VPS_IP:-$(curl -s ifconfig.me 2>/dev/null || echo '172.245.184.188')}"
}

# Cargar configuración al importar el módulo
load_api_config

# ─── Funciones de llamada a la API ────────────────────────────

# GET request a la API
# Uso: api_get "/endpoint" [param1=value1&param2=value2]
api_get() {
    local endpoint="$1"
    local params="$2"
    local url="${API_BASE}${endpoint}"

    if [[ -n "$params" ]]; then
        url="${url}?${params}"
    fi

    local response
    response=$(curl -s -m 10 \
        -H "x-api-key: ${API_KEY}" \
        -H "Accept: application/json" \
        "$url" 2>/dev/null)

    if [[ $? -ne 0 ]]; then
        echo '{"ok":false,"detail":"Error de conexión con la API"}'
        return 1
    fi

    echo "$response"
}

# POST request a la API (form-urlencoded)
# Uso: api_post "/endpoint" "param1=value1&param2=value2"
api_post() {
    local endpoint="$1"
    local data="$2"
    local url="${API_BASE}${endpoint}"

    local response
    response=$(curl -s -m 15 \
        -X POST \
        -H "x-api-key: ${API_KEY}" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "$data" \
        "$url" 2>/dev/null)

    if [[ $? -ne 0 ]]; then
        echo '{"ok":false,"detail":"Error de conexión con la API"}'
        return 1
    fi

    echo "$response"
}

# Verificar si la API está activa
api_check() {
    local response
    response=$(curl -s -m 5 "${API_BASE}/healthz" 2>/dev/null)

    if echo "$response" | grep -q '"ok"' 2>/dev/null; then
        return 0
    else
        return 1
    fi
}

# Obtener valor de un campo JSON simple
# Uso: json_field '{"ok":true,"username":"test"}' "username"
json_field() {
    local json="$1"
    local field="$2"
    echo "$json" | grep -o "\"${field}\":[^,}]*" | head -1 | cut -d'"' -f3 | tr -d '"'
}

# Obtener valor booleano ok de respuesta API
api_ok() {
    local json="$1"
    local ok_val
    ok_val=$(echo "$json" | grep -o '"ok":[^,}]*' | head -1 | cut -d':' -f2)
    [[ "$ok_val" == "true" ]]
}

# Obtener detalle de error de respuesta API
api_error() {
    local json="$1"
    echo "$json" | grep -o '"detail":"[^"]*"' | head -1 | cut -d'"' -f4
}

# ─── Funciones de UI ──────────────────────────────────────────
bar() {
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
}

bar2() {
    echo -e "${BLUE}─────────────────────────────────────────────────────${NC}"
}

msg() {
    case $1 in
        -red)     echo -e "${RED}$2${NC}";;
        -green)   echo -e "${GREEN}$2${NC}";;
        -yellow)  echo -e "${YELLOW}$2${NC}";;
        -blue)    echo -e "${BLUE}$2${NC}";;
        -cyan)    echo -e "${CYAN}$2${NC}";;
        -magenta) echo -e "${MAGENTA}$2${NC}";;
        -white)   echo -e "${WHITE}$2${NC}";;
        -bar)     bar;;
        -bar2)    bar2;;
        *)        echo -e "$1";;
    esac
}

print_center() {
    local text="$1"
    local width=53
    local len=${#text}
    local padding=$(( (width - len) / 2 ))
    printf "%${padding}s%s\n" "" "$text"
}

title() {
    clear
    bar
    print_center "★  GeminTech VPS Panel  ★"
    print_center "$1"
    bar
}

logo() {
    clear
    echo -e "${CYAN}"
    echo "  ██████╗ ███████╗███╗   ███╗██╗███╗   ██╗"
    echo " ██╔════╝ ██╔════╝████╗ ████║██║████╗  ██║"
    echo " ██║  ███╗█████╗  ██╔████╔██║██║██╔██╗ ██║"
    echo " ██║   ██║██╔══╝  ██║╚██╔╝██║██║██║╚██╗██║"
    echo " ╚██████╔╝███████╗██║ ╚═╝ ██║██║██║ ╚████║"
    echo "  ╚═════╝ ╚══════╝╚═╝     ╚═╝╚═╝╚═╝  ╚═══╝"
    echo -e "${WHITE}           ████████╗███████╗ ██████╗██╗  ██╗"
    echo "              ██╔══╝██╔════╝██╔════╝██║  ██║"
    echo "              ██║   █████╗  ██║     ███████║"
    echo "              ██║   ██╔══╝  ██║     ██╔══██║"
    echo "              ██║   ███████╗╚██████╗██║  ██║"
    echo -e "              ╚═╝   ╚══════╝ ╚═════╝╚═╝  ╚═╝${NC}"
    bar
}

enter() {
    echo ""
    read -p "  Presione ENTER para continuar..."
}

get_ip() {
    curl -s ifconfig.me 2>/dev/null || curl -s icanhazip.com 2>/dev/null || echo "No disponible"
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        msg -red "Este script debe ejecutarse como root (sudo -i)"
        exit 1
    fi
}

# ─── Verificar API al iniciar ─────────────────────────────────
check_api_status() {
    if api_check; then
        msg -green "  API: Conectada (${API_BASE})"
    else
        msg -red "  API: No disponible (${API_BASE})"
        msg -yellow "  Verifique que el servicio gemintech-api esté activo"
        msg -yellow "  Ejecute: systemctl restart gemintech-api"
    fi
}
