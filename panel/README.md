# GeminTech Panel VPS — Scripts Bash

Panel de administración interactivo para VPS Debian/Ubuntu.

## Instalación

```bash
# 1 comando desde el VPS (como root):
bash <(curl -sL https://raw.githubusercontent.com/TU_USUARIO/GeminTech/main/install.sh)

# Luego ejecutar:
gemintech
```

## Menú

```
[1] Administrar Cuentas SSH
[2] Configuración de Protocolos (WebSocket, Stunnel, Squid, BadVPN)
[3] Herramientas Extras (Speed test, TCP tuning, logs)
[4] Configuración del Servidor (hostname, timezone, puertos)
[6] Desinstalar panel
```

## Scripts

| Archivo | Función |
|---|---|
| `menu` | Menú principal (comando: `gemintech`) |
| `module.sh` | Colores, funciones, rutas (`/etc/gemintech`) |
| `user.sh` | Crear / eliminar / renovar / cambiar password usuarios SSH |
| `services.sh` | Instalar WebSocket, Stunnel, Squid, BadVPN |
| `extras.sh` | Speed test, optimizar TCP, rotación de logs |
| `config.sh` | Hostname, timezone, actualizar sistema |
| `cleanup.sh` | Liberar puertos antes de instalar servicios |

## Rutas en el VPS

```
/etc/gemintech/          ← Directorio principal
/etc/gemintech/user/     ← Datos de usuarios (límites, demos)
/etc/gemintech/tmp/      ← Home de usuarios SSH (sin acceso)
/opt/gemintech/api/      ← Backend FastAPI
/usr/local/bin/gemintech ← Comando global del menú
```
