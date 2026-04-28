# GeminTech API — Backend FastAPI

Backend de gestión de usuarios SSH. Corre directamente en el VPS como servicio systemd.

## Instalación en VPS

El `install.sh` principal instala todo automáticamente. Para instalar solo la API:

```bash
cd /opt/gemintech/api
pip3 install -r requirements.txt

# Configurar
nano .env          # editar API_KEY y VPS_IP

# Instalar servicio
cp ../configs/systemd/fastapi.service /etc/systemd/system/gemintech-api.service
systemctl daemon-reload
systemctl enable --now gemintech-api

# Verificar
curl -H "x-api-key: Ecuador2026_Secreto_Api" http://localhost:9000/status
```

## Variables de entorno (.env)

```
API_KEY=Ecuador2026_Secreto_Api
API_PORT=9000
VPS_DIR=/etc/gemintech
VPS_IP=172.245.184.188
```

## Endpoints

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/healthz` | Health check (sin auth) |
| GET | `/status` | Total usuarios, uptime, memoria |
| GET | `/usuarios/listar` | Lista usuarios SSH con expiración |
| GET | `/usuarios/monitor` | Conexiones SSH activas |
| POST | `/usuario/crear` | Crear usuario (regular o demo por minutos) |
| POST | `/usuario/eliminar` | Eliminar usuario |
| POST | `/usuario/renovar` | Renovar días o minutos demo |
| POST | `/usuario/password` | Cambiar contraseña |

## Auth

Todos los endpoints (excepto `/healthz`) requieren:
```
x-api-key: Ecuador2026_Secreto_Api
```

## Puerto 9000 — abrir en firewall

```bash
ufw allow 9000/tcp
# o con iptables:
iptables -I INPUT -p tcp --dport 9000 -j ACCEPT
```
