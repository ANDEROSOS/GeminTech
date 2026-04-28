#!/bin/bash
# GESTION DE USUARIOS SSH - Conectado a la API GeminTech
# Todas las operaciones pasan por la API FastAPI

source /etc/gemintech/module.sh 2>/dev/null || source "$(dirname "$0")/module.sh"

# Crear usuario SSH (vía API)
crear_usuario() {
    title "CREAR USUARIO SSH"

    read -p "  Nombre de usuario: " usuario
    [[ -z $usuario ]] && msg -red "  Usuario vacio" && return

    read -p "  Contrasena: " -s pass
    echo ""
    [[ -z $pass ]] && msg -red "  Contrasena vacia" && return

    read -p "  Dias de duracion (0=ilimitado): " dias
    [[ -z $dias ]] && dias=30

    read -p "  Minutos demo (0=no demo): " minutos
    [[ -z $minutos ]] && minutos=0

    read -p "  Limite de conexiones: " limite
    [[ -z $limite ]] && limite=1

    # Llamar a la API
    msg -yellow "  Creando usuario via API..."
    bar2

    local data="username=${usuario}&password=${pass}&dias=${dias}&minutos=${minutos}&limite=${limite}"
    local response
    response=$(api_post "/usuario/crear" "$data")

    if api_ok "$response"; then
        local tipo=$(json_field "$response" "tipo")
        local expira=$(json_field "$response" "expira")
        local expira_ts=$(json_field "$response" "expira_ts")

        bar2
        msg -green "  Usuario creado exitosamente via API"
        bar2
        echo -e "  ${YELLOW}Usuario:${NC} $usuario"
        echo -e "  ${YELLOW}Contrasena:${NC} $pass"
        echo -e "  ${YELLOW}Tipo:${NC} $tipo"
        echo -e "  ${YELLOW}Limite:${NC} $limite conexiones"

        if [[ "$tipo" == "demo" ]]; then
            echo -e "  ${YELLOW}Expira en:${NC} $minutos minutos (ts: $expira_ts)"
        elif [[ "$expira" == "Nunca" ]]; then
            echo -e "  ${YELLOW}Expira:${NC} Nunca (ilimitado)"
        else
            echo -e "  ${YELLOW}Expira:${NC} $expira"
        fi

        echo -e "  ${YELLOW}IP:${NC} $(get_ip)"
        bar2
    else
        local error=$(api_error "$response")
        msg -red "  Error al crear usuario: ${error:-Error desconocido}"
    fi
    enter
}

# Eliminar usuario (vía API)
eliminar_usuario() {
    title "ELIMINAR USUARIO SSH"

    # Listar usuarios via API
    msg -yellow "  Obteniendo lista de usuarios..."
    local response
    response=$(api_get "/usuarios/listar")

    echo -e "  ${CYAN}Usuarios existentes:${NC}"
    bar2

    # Parsear usernames de la respuesta JSON
    local usuarios=()
    while IFS= read -r line; do
        local u=$(echo "$line" | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
        local e=$(echo "$line" | grep -o '"expiration":"[^"]*"' | cut -d'"' -f4)
        local est=$(echo "$line" | grep -o '"estado":"[^"]*"' | cut -d'"' -f4)
        if [[ -n "$u" ]]; then
            usuarios+=("$u")
            printf "  %-15s %-15s %s\n" "$u" "${e:-N/A}" "${est:-N/A}"
        fi
    done <<< "$(echo "$response" | grep -o '{[^}]*}')"

    if [[ ${#usuarios[@]} -eq 0 ]]; then
        msg -yellow "  No hay usuarios SSH"
        enter
        return
    fi

    bar2
    read -p "  Usuario a eliminar (nombre): " seleccion
    [[ -z $seleccion ]] && return

    # Confirmar eliminación
    read -p "  Confirmar eliminacion de '$seleccion'? [s/N]: " confirm
    [[ "$confirm" != "s" && "$confirm" != "S" ]] && msg -yellow "  Cancelado" && return

    # Llamar a la API
    local resp
    resp=$(api_post "/usuario/eliminar" "username=${seleccion}")

    if api_ok "$resp"; then
        msg -green "  Usuario $seleccion eliminado via API"
    else
        local error=$(api_error "$resp")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Listar usuarios (vía API)
listar_usuarios() {
    title "LISTA DE USUARIOS SSH"

    msg -yellow "  Obteniendo usuarios via API..."
    bar2

    local response
    response=$(api_get "/usuarios/listar")

    # Verificar si la respuesta es válida
    if ! echo "$response" | grep -q '"usuarios"'; then
        msg -red "  Error al obtener usuarios de la API"
        enter
        return
    fi

    printf "  ${YELLOW}%-15s %-15s %-8s %-12s %-8s${NC}\n" "USUARIO" "EXPIRACION" "LIMITE" "ESTADO" "ONLINE"
    bar2

    # Parsear cada usuario del JSON
    local total=0
    while IFS= read -r line; do
        local u=$(echo "$line" | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
        local e=$(echo "$line" | grep -o '"expiration":"[^"]*"' | cut -d'"' -f4)
        local l=$(echo "$line" | grep -o '"limite":"[^"]*"' | cut -d'"' -f4)
        local est=$(echo "$line" | grep -o '"estado":"[^"]*"' | cut -d'"' -f4)

        if [[ -n "$u" ]]; then
            ((total++))

            # Obtener conexiones online via API
            local detail
            detail=$(api_get "/usuario/detalle" "username=${u}")
            local online=$(json_field "$detail" "online")

            printf "  %-15s %-15s %-8s %-12s %-8s\n" "$u" "${e:-N/A}" "${l:-1}" "${est:-N/A}" "${online:-0}"
        fi
    done <<< "$(echo "$response" | grep -o '{[^}]*}')"

    bar2
    echo -e "  ${CYAN}Total: $total usuarios${NC}"
    bar
    enter
}

# Cambiar contraseña (vía API)
cambiar_pass() {
    title "CAMBIAR CONTRASENA"

    read -p "  Usuario: " usuario
    [[ -z $usuario ]] && return

    read -p "  Nueva contrasena: " -s pass
    echo ""
    [[ -z $pass ]] && msg -red "  Contrasena vacia" && return

    # Llamar a la API
    local resp
    resp=$(api_post "/usuario/password" "username=${usuario}&password=${pass}")

    if api_ok "$resp"; then
        msg -green "  Contrasena cambiada exitosamente via API"
    else
        local error=$(api_error "$resp")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Renovar usuario (vía API)
renovar_usuario() {
    title "RENOVAR USUARIO"

    read -p "  Usuario: " usuario
    [[ -z $usuario ]] && return

    echo -e "  ${CYAN}Tipo de renovacion:${NC}"
    echo -e "  ${GREEN}[1]${NC} Por dias"
    echo -e "  ${GREEN}[2]${NC} Demo (minutos)"
    bar
    read -p "  Opcion: " tipo_renovar

    local dias=0
    local minutos=0

    case $tipo_renovar in
        1)
            read -p "  Dias a agregar: " dias
            [[ -z $dias ]] && dias=30
            ;;
        2)
            read -p "  Minutos demo: " minutos
            [[ -z $minutos ]] && minutos=30
            ;;
        *)
            msg -red "  Opcion invalida"
            return
            ;;
    esac

    # Llamar a la API
    local resp
    resp=$(api_post "/usuario/renovar" "username=${usuario}&dias=${dias}&minutos=${minutos}")

    if api_ok "$resp"; then
        local expira=$(json_field "$resp" "expira")
        local expira_ts=$(json_field "$resp" "expira_ts")

        if [[ $minutos -gt 0 ]]; then
            msg -green "  Usuario renovado (demo): $minutos minutos"
        else
            msg -green "  Usuario renovado hasta: $expira"
        fi
    else
        local error=$(api_error "$resp")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Bloquear usuario (vía API)
bloquear_usuario() {
    title "BLOQUEAR USUARIO"

    read -p "  Usuario a bloquear: " usuario
    [[ -z $usuario ]] && return

    local resp
    resp=$(api_post "/usuario/bloquear" "username=${usuario}")

    if api_ok "$resp"; then
        msg -green "  Usuario $usuario bloqueado via API"
    else
        local error=$(api_error "$resp")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Desbloquear usuario (vía API)
desbloquear_usuario() {
    title "DESBLOQUEAR USUARIO"

    read -p "  Usuario a desbloquear: " usuario
    [[ -z $usuario ]] && return

    local resp
    resp=$(api_post "/usuario/desbloquear" "username=${usuario}")

    if api_ok "$resp"; then
        msg -green "  Usuario $usuario desbloqueado via API"
    else
        local error=$(api_error "$resp")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Cambiar limite de conexiones (vía API)
cambiar_limite() {
    title "CAMBIAR LIMITE DE CONEXIONES"

    read -p "  Usuario: " usuario
    [[ -z $usuario ]] && return

    read -p "  Nuevo limite de conexiones: " limite
    [[ -z $limite ]] && limite=1

    local resp
    resp=$(api_post "/usuario/limite" "username=${usuario}&limite=${limite}")

    if api_ok "$resp"; then
        msg -green "  Limite cambiado a $limite conexiones via API"
    else
        local error=$(api_error "$resp")
        msg -red "  Error: ${error:-Error desconocido}"
    fi
    enter
}

# Monitor de conexiones (vía API)
monitor_conexiones() {
    title "MONITOR DE CONEXIONES"

    msg -yellow "  Obteniendo conexiones via API..."
    bar2

    local response
    response=$(api_get "/usuarios/monitor")

    # Mostrar conexiones activas
    echo -e "  ${CYAN}Conexiones SSH activas:${NC}"
    bar2

    local activas=$(json_field "$response" "activas")
    echo -e "  ${GREEN}Total conexiones activas: ${activas:-0}${NC}"
    bar2

    # Conexiones por usuario
    echo -e "  ${CYAN}Conexiones por usuario:${NC}"
    bar2

    # Parsear por_usuario del JSON
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

# Menu principal de usuarios
menu_usuarios() {
    while true; do
        title "GESTION DE USUARIOS SSH"

        # Mostrar estado de la API
        check_api_status
        bar2

        echo -e "  ${GREEN}[1]${NC} > Crear usuario"
        echo -e "  ${GREEN}[2]${NC} > Eliminar usuario"
        echo -e "  ${GREEN}[3]${NC} > Listar usuarios"
        echo -e "  ${GREEN}[4]${NC} > Cambiar contrasena"
        echo -e "  ${GREEN}[5]${NC} > Renovar usuario"
        echo -e "  ${GREEN}[6]${NC} > Bloquear usuario"
        echo -e "  ${GREEN}[7]${NC} > Desbloquear usuario"
        echo -e "  ${GREEN}[8]${NC} > Cambiar limite de conexiones"
        echo -e "  ${GREEN}[9]${NC} > Monitor conexiones"
        echo -e "  ${RED}[0]${NC} > Volver"

        bar
        read -p "  Opcion: " opc

        case $opc in
            1) crear_usuario ;;
            2) eliminar_usuario ;;
            3) listar_usuarios ;;
            4) cambiar_pass ;;
            5) renovar_usuario ;;
            6) bloquear_usuario ;;
            7) desbloquear_usuario ;;
            8) cambiar_limite ;;
            9) monitor_conexiones ;;
            0) return ;;
            *) msg -red "  Opcion invalida" && sleep 1 ;;
        esac
    done
}

# Ejecutar si se llama directamente
[[ "${BASH_SOURCE[0]}" == "${0}" ]] && menu_usuarios
