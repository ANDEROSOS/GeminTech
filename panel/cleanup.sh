#!/bin/bash
# Script de limpieza para GEMINTECH
# Detiene servicios y libera puertos

echo -e "\033[1;33m[!] INICIANDO LIMPIEZA DE SERVICIOS Y PUERTOS...\033[0m"

# Detener servicios conflictivos
servicios="apache2 nginx dropbear stunnel4 squid openvpn badvpn"
for s in $servicios; do
    if systemctl is-active --quiet $s; then
        echo -e "Deteniendo $s..."
        systemctl stop $s 2>/dev/null
        systemctl disable $s 2>/dev/null
    fi
done

# Matar procesos de Python y Node que puedan estar usando puertos
echo -e "Limpiando procesos Python/Node..."
pkill -f python 2>/dev/null
pkill -f python3 2>/dev/null
pkill -f node 2>/dev/null
pkill -f badvpn-udpgw 2>/dev/null

# Liberar puertos especificos (fuser -k mata el proceso usando el puerto)
puertos="80 81 443 8080 8888 7300"
for p in $puertos; do
    # Solo matar si hay algo escuchando
    if lsof -i :$p >/dev/null 2>&1; then
        echo -e "Liberando puerto $p..."
        fuser -k -n tcp $p 2>/dev/null
        fuser -k -n udp $p 2>/dev/null
    fi
done

echo -e "\033[1;32m[!] LIMPIEZA COMPLETADA. SISTEMA LISTO PARA GEMINTECH.\033[0m"
