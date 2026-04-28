#!/usr/bin/env python3
"""
GeminTech FastAPI Backend v3.1
================================
Backend de gestion completo para VPS Debian/Ubuntu.
IP auto-detectada — Sin IPs hardcodeadas.

Endpoints:
  --- Sistema -----------------------------------------------
  GET  /status               -> Estado del servidor
  GET  /healthz              -> Health check

  --- Usuarios SSH ------------------------------------------
  GET  /usuarios/listar      -> Lista usuarios SSH con expiracion y limite
  GET  /usuarios/monitor     -> Conexiones SSH activas
  POST /usuario/crear        -> Crear usuario SSH
  POST /usuario/eliminar     -> Eliminar usuario SSH
  POST /usuario/renovar      -> Renovar acceso (dias o minutos demo)
  POST /usuario/password     -> Cambiar contrasena
  POST /usuario/bloquear     -> Bloquear usuario (passwd -l)
  POST /usuario/desbloquear  -> Desbloquear usuario (passwd -u)
  GET  /usuario/detalle      -> Detalle completo de un usuario
  POST /usuario/limite       -> Cambiar limite de conexiones

  --- Servicios ---------------------------------------------
  GET  /servicios/estado     -> Estado de todos los servicios
  POST /servicios/reiniciar  -> Reiniciar un servicio
  GET  /servicios/puertos    -> Puertos abiertos en el VPS

  --- Sistema VPS -------------------------------------------
  GET  /sistema/info         -> Informacion completa del sistema
  POST /sistema/limpiar-logs -> Limpiar logs del sistema
  POST /sistema/tcp-optimize -> Optimizar TCP
  GET  /sistema/disco        -> Uso de disco
  GET  /sistema/memoria      -> Uso de memoria

Auth: Header  x-api-key: TU_API_KEY

NOTA: El PHP panel y el Bash panel envian form-urlencoded.
    Los POST endpoints usan Form(...) para compatibilidad perfecta.
"""

import os
import subprocess
import time
import json
import re
from datetime import datetime, timedelta
from pathlib import Path

from fastapi import FastAPI, Depends, HTTPException, Header, Form, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from dotenv import load_dotenv
import uvicorn

load_dotenv()

# --- Configuracion -----------------------------------------------------------
API_KEY  = os.getenv("API_KEY", "Ecuador2026_Secreto_Api")
VPS_DIR  = Path(os.getenv("VPS_DIR", "/etc/gemintech"))
VPS_USER = VPS_DIR / "user"
VPS_TMP  = VPS_DIR / "tmp"
API_PORT = int(os.getenv("API_PORT", "9000"))
WS_KEY   = os.getenv("WS_KEY", "Ecuador2026")


def auto_detect_ip() -> str:
    """
    Auto-detectar la IP publica del VPS.
    Intenta multiples servicios para maximizar compatibilidad.
    Si todos fallan, intenta obtener la IP de la interfaz principal.
    """
    # 1. Intentar con servicios externos
    ip_services = [
        "curl -s -m 5 ifconfig.me",
        "curl -s -m 5 icanhazip.com",
        "curl -s -m 5 api.ipify.org",
        "curl -s -m 5 ipinfo.io/ip",
        "curl -s -m 5 checkip.amazonaws.com",
        "curl -s -m 5 ipecho.net/plain",
    ]
    for cmd in ip_services:
        try:
            result = subprocess.run(
                cmd, shell=True, capture_output=True, text=True, timeout=8
            )
            ip = result.stdout.strip()
            # Validar que sea una IPv4 valida
            if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
                return ip
        except Exception:
            continue

    # 2. Intentar desde hostname -I (IP local, puede ser privada)
    try:
        result = subprocess.run(
            "hostname -I 2>/dev/null | awk '{print $1}'",
            shell=True, capture_output=True, text=True, timeout=5
        )
        ip = result.stdout.strip()
        if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
            return ip
    except Exception:
        pass

    # 3. Intentar desde ip route
    try:
        result = subprocess.run(
            "ip route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}'",
            shell=True, capture_output=True, text=True, timeout=5
        )
        ip = result.stdout.strip()
        if ip and re.match(r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$', ip):
            return ip
    except Exception:
        pass

    return "127.0.0.1"


# IP auto-detectada al arrancar (se puede sobreescribir con .env)
VPS_IP = os.getenv("VPS_IP") or auto_detect_ip()

# --- App ---------------------------------------------------------------------
app = FastAPI(
    title="GeminTech API",
    description="Backend gestion completa VPS — Sistema GeminTech v3.1",
    version="3.1.0",
    docs_url="/docs",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


# --- Autenticacion -----------------------------------------------------------
def require_key(x_api_key: str = Header(...)):
    """Verifica el header x-api-key en cada request."""
    if x_api_key != API_KEY:
        raise HTTPException(status_code=403, detail="Forbidden: api key invalida")
    return x_api_key


# --- Utilidades --------------------------------------------------------------
def cmd(command: str) -> tuple[int, str, str]:
    """Ejecutar comando de shell -> (codigo_salida, stdout, stderr)."""
    try:
        result = subprocess.run(
            command, shell=True,
            capture_output=True, text=True, timeout=30,
        )
        return result.returncode, result.stdout.strip(), result.stderr.strip()
    except subprocess.TimeoutExpired:
        return 1, "", "Timeout"


def init_dirs():
    """Crear directorios de GeminTech si no existen."""
    VPS_USER.mkdir(parents=True, exist_ok=True)
    VPS_TMP.mkdir(parents=True, exist_ok=True)
    VPS_TMP.chmod(0o777)


def list_ssh_users() -> list[str]:
    """Lista de usuarios SSH activos (shell /bin/false)."""
    _, out, _ = cmd(
        "grep '/bin/false' /etc/passwd"
        " | grep -vE 'syslog|nologin|nobody|sync|usbmux|_'"
        " | cut -d: -f1"
    )
    return [u for u in out.splitlines() if u.strip()]


def get_expiration(username: str) -> str:
    """
    Expiracion del usuario:
    - Modo demo -> 'ts:TIMESTAMP_UNIX'
    - Regular   -> 'YYYY-MM-DD' o 'Nunca'
    """
    demo_file = VPS_USER / f"{username}.demo"
    if demo_file.exists():
        try:
            ts = int(demo_file.read_text().strip())
            return f"ts:{ts}" if ts > int(time.time()) else "Expirado"
        except Exception:
            pass

    _, out, _ = cmd(f"chage -l '{username}' 2>/dev/null")
    for line in out.splitlines():
        if "Account expires" in line:
            val = line.split(":", 1)[1].strip()
            return "Nunca" if "never" in val.lower() else val
    return "Desconocido"


def get_limit(username: str) -> str:
    """Obtener el limite de conexiones del usuario."""
    limit_file = VPS_USER / f"{username}.limit"
    if limit_file.exists():
        return limit_file.read_text().strip()
    return "1"


def is_locked(username: str) -> bool:
    """Verificar si el usuario esta bloqueado."""
    _, out, _ = cmd(f"passwd -S '{username}' 2>/dev/null")
    if out:
        status = out.split()[1] if len(out.split()) > 1 else ""
        return status == "L"
    return False


def user_exists(username: str) -> bool:
    code, _, _ = cmd(f"id '{username}' 2>/dev/null")
    return code == 0


def validate_username(username: str):
    """Validar que el username sea seguro."""
    clean = username.replace("_", "").replace("-", "")
    if not clean.isalnum() or len(username) < 2 or len(username) > 32:
        raise HTTPException(
            status_code=400,
            detail="Username invalido (solo letras, numeros, _ y -, 2-32 chars)"
        )


# =============================================================================
# ENDPOINTS — SISTEMA
# =============================================================================

@app.get("/healthz", tags=["Sistema"])
def health_check():
    """Health check basico."""
    return {"status": "ok", "service": "gemintech-api", "version": "3.1.0"}


@app.get("/status", tags=["Sistema"])
def get_status(_: str = Depends(require_key)):
    """Estado del servidor: online, total usuarios, uptime, memoria, IP."""
    usuarios = list_ssh_users()
    _, uptime, _ = cmd("uptime -p 2>/dev/null || uptime")
    _, mem, _ = cmd("free -h | awk '/^Mem:/{print $3\"/\"$2}'")
    _, load, _ = cmd("cat /proc/loadavg | awk '{print $1\" \"$2\" \"$3}'")
    _, disk, _ = cmd("df -h / | tail -1 | awk '{print $5}'")

    return {
        "api_status": "online",
        "total_usuarios": len(usuarios),
        "timestamp": datetime.utcnow().isoformat() + "Z",
        "vps_ip": VPS_IP,
        "uptime": uptime or "N/A",
        "memoria": mem or "N/A",
        "load_avg": load or "N/A",
        "disco_usado": disk or "N/A",
        "api_version": "3.1.0",
    }


@app.get("/ip", tags=["Sistema"])
def get_ip(_: str = Depends(require_key)):
    """Retorna la IP del VPS (auto-detectada o configurada)."""
    return {"vps_ip": VPS_IP}


# =============================================================================
# ENDPOINTS — USUARIOS SSH
# =============================================================================

@app.get("/usuarios/listar", tags=["Usuarios"])
def listar_usuarios(_: str = Depends(require_key)):
    """Lista todos los usuarios SSH con su expiracion, limite y estado."""
    usuarios = list_ssh_users()
    resultado = []
    for u in usuarios:
        locked = is_locked(u)
        resultado.append({
            "username": u,
            "expiration": get_expiration(u),
            "limite": get_limit(u),
            "bloqueado": locked,
            "estado": "Bloqueado" if locked else "Activo",
        })
    return {"usuarios": resultado, "total": len(resultado)}


@app.get("/usuarios/monitor", tags=["Usuarios"])
def monitor_conexiones(_: str = Depends(require_key)):
    """Conexiones SSH activas en tiempo real."""
    _, who_out, _ = cmd("who 2>/dev/null")
    conexiones = [l.strip() for l in who_out.splitlines() if l.strip()]

    # Conexiones por usuario
    por_usuario = {}
    for u in list_ssh_users():
        _, count, _ = cmd(f"ps -u '{u}' 2>/dev/null | grep -c sshd")
        c = int(count) if count.isdigit() else 0
        if c > 0:
            por_usuario[u] = c

    return {
        "conexiones": conexiones,
        "activas": len(conexiones),
        "por_usuario": por_usuario,
    }


@app.get("/usuario/detalle", tags=["Usuarios"])
def detalle_usuario(
    username: str,
    _: str = Depends(require_key),
):
    """Detalle completo de un usuario: expiracion, limite, estado, conexiones activas."""
    validate_username(username)

    if not user_exists(username):
        raise HTTPException(status_code=404, detail=f"Usuario '{username}' no encontrado")

    locked = is_locked(username)
    _, count, _ = cmd(f"ps -u '{username}' 2>/dev/null | grep -c sshd")
    online = int(count) if count.isdigit() else 0

    return {
        "username": username,
        "expiration": get_expiration(username),
        "limite": get_limit(username),
        "bloqueado": locked,
        "estado": "Bloqueado" if locked else "Activo",
        "online": online,
    }


@app.post("/usuario/crear", tags=["Usuarios"])
def crear_usuario(
    username: str = Form(...),
    password: str = Form(...),
    dias:     int = Form(7),
    minutos:  int = Form(0),
    limite:   int = Form(1),
    _: str = Depends(require_key),
):
    """
    Crear usuario SSH en el VPS.
    - dias > 0  -> usuario regular con expiracion
    - dias = 0  -> usuario sin expiracion
    - minutos > 0 -> modo demo (expira en N minutos)
    - limite -> numero maximo de conexiones simultaneas
    """
    validate_username(username)

    if len(password) < 4:
        raise HTTPException(status_code=400, detail="Contrasena muy corta (min. 4 chars)")

    if user_exists(username):
        raise HTTPException(status_code=400, detail=f"El usuario '{username}' ya existe")

    init_dirs()

    # Hash de contrasena
    _, hashed, err_hash = cmd(f"openssl passwd -1 '{password}'")
    if not hashed:
        raise HTTPException(status_code=500, detail=f"Error generando hash: {err_hash}")

    base_cmd = f"useradd -M -s /bin/false -d '{VPS_TMP}' -p '{hashed}'"

    # -- Modo DEMO ------------------------------------------------------------
    if minutos > 0:
        code, _, err = cmd(f"{base_cmd} '{username}'")
        if code != 0:
            raise HTTPException(status_code=500, detail=f"Error: {err}")

        end_ts = int(time.time()) + (minutos * 60)
        (VPS_USER / f"{username}.demo").write_text(str(end_ts))
        (VPS_USER / f"{username}.limit").write_text(str(limite))

        return {
            "ok": True,
            "username": username,
            "tipo": "demo",
            "expira_ts": end_ts,
            "minutos": minutos,
            "limite": limite,
        }

    # -- Usuario Regular ------------------------------------------------------
    if dias == 0:
        code, _, err = cmd(f"{base_cmd} '{username}'")
        expira = "Nunca"
    else:
        fecha_exp = (datetime.now() + timedelta(days=int(dias))).strftime("%Y-%m-%d")
        code, _, err = cmd(f"{base_cmd} -e '{fecha_exp}' '{username}'")
        expira = fecha_exp

    if code != 0:
        raise HTTPException(status_code=500, detail=f"Error creando usuario: {err}")

    (VPS_USER / f"{username}.limit").write_text(str(limite))

    return {
        "ok": True,
        "username": username,
        "tipo": "regular",
        "expira": expira,
        "dias": int(dias),
        "limite": limite,
    }


@app.post("/usuario/eliminar", tags=["Usuarios"])
def eliminar_usuario(
    username: str = Form(...),
    _: str = Depends(require_key),
):
    """Eliminar usuario SSH del sistema."""
    validate_username(username)

    if not user_exists(username):
        raise HTTPException(status_code=404, detail=f"Usuario '{username}' no encontrado")

    # Matar conexiones activas del usuario
    cmd(f"pkill -u '{username}' 2>/dev/null")
    time.sleep(0.3)
    cmd(f"userdel -f '{username}' 2>/dev/null")

    # Limpiar archivos de configuracion del usuario
    for suffix in [".limit", ".demo"]:
        f = VPS_USER / f"{username}{suffix}"
        if f.exists():
            f.unlink(missing_ok=True)

    return {"ok": True, "username": username}


@app.post("/usuario/renovar", tags=["Usuarios"])
def renovar_usuario(
    username: str = Form(...),
    dias:     int = Form(7),
    minutos:  int = Form(0),
    _: str = Depends(require_key),
):
    """Renovar acceso de un usuario (dias o modo demo por minutos)."""
    validate_username(username)

    if not user_exists(username):
        raise HTTPException(status_code=404, detail=f"Usuario '{username}' no encontrado")

    # Desbloquear primero
    cmd(f"usermod -U '{username}' 2>/dev/null")

    if minutos > 0:
        end_ts = int(time.time()) + (minutos * 60)
        (VPS_USER / f"{username}.demo").write_text(str(end_ts))
        cmd(f"usermod -e '' '{username}' 2>/dev/null")
        return {"ok": True, "tipo": "demo", "expira_ts": end_ts, "minutos": minutos}

    # Regular
    (VPS_USER / f"{username}.demo").unlink(missing_ok=True)
    fecha_exp = (datetime.now() + timedelta(days=int(dias))).strftime("%Y-%m-%d")
    code, _, err = cmd(f"chage -E '{fecha_exp}' '{username}'")
    if code != 0:
        raise HTTPException(status_code=500, detail=f"Error renovando: {err}")

    return {"ok": True, "expira": fecha_exp, "dias": int(dias)}


@app.post("/usuario/password", tags=["Usuarios"])
def cambiar_password(
    username: str = Form(...),
    password: str = Form(...),
    _: str = Depends(require_key),
):
    """Cambiar la contrasena de un usuario SSH."""
    validate_username(username)

    if not user_exists(username):
        raise HTTPException(status_code=404, detail=f"Usuario '{username}' no encontrado")

    if len(password) < 4:
        raise HTTPException(status_code=400, detail="Contrasena muy corta (min 4 chars)")

    code, _, err = cmd(f"echo '{username}:{password}' | chpasswd")
    if code != 0:
        raise HTTPException(status_code=500, detail=f"Error: {err}")

    return {"ok": True, "username": username}


@app.post("/usuario/bloquear", tags=["Usuarios"])
def bloquear_usuario(
    username: str = Form(...),
    _: str = Depends(require_key),
):
    """Bloquear un usuario SSH (passwd -l)."""
    validate_username(username)

    if not user_exists(username):
        raise HTTPException(status_code=404, detail=f"Usuario '{username}' no encontrado")

    code, _, err = cmd(f"passwd -l '{username}' 2>/dev/null")
    if code != 0:
        raise HTTPException(status_code=500, detail=f"Error bloqueando: {err}")

    # Matar conexiones activas
    cmd(f"pkill -u '{username}' 2>/dev/null")

    return {"ok": True, "username": username, "estado": "Bloqueado"}


@app.post("/usuario/desbloquear", tags=["Usuarios"])
def desbloquear_usuario(
    username: str = Form(...),
    _: str = Depends(require_key),
):
    """Desbloquear un usuario SSH (passwd -u)."""
    validate_username(username)

    if not user_exists(username):
        raise HTTPException(status_code=404, detail=f"Usuario '{username}' no encontrado")

    code, _, err = cmd(f"passwd -u '{username}' 2>/dev/null")
    if code != 0:
        raise HTTPException(status_code=500, detail=f"Error desbloqueando: {err}")

    return {"ok": True, "username": username, "estado": "Activo"}


@app.post("/usuario/limite", tags=["Usuarios"])
def cambiar_limite(
    username: str = Form(...),
    limite:   int = Form(1),
    _: str = Depends(require_key),
):
    """Cambiar el limite de conexiones simultaneas de un usuario."""
    validate_username(username)

    if not user_exists(username):
        raise HTTPException(status_code=404, detail=f"Usuario '{username}' no encontrado")

    (VPS_USER / f"{username}.limit").write_text(str(limite))

    return {"ok": True, "username": username, "limite": limite}


# =============================================================================
# ENDPOINTS — SERVICIOS
# =============================================================================

@app.get("/servicios/estado", tags=["Servicios"])
def estado_servicios(_: str = Depends(require_key)):
    """Estado de todos los servicios del VPS."""
    servicios = ["ssh", "sshd", "dropbear", "stunnel4", "squid", "squid3", "badvpn", "ws-ssh", "gemintech-api"]
    resultado = []

    for svc in servicios:
        active = cmd(f"systemctl is-active '{svc}' 2>/dev/null")[1]
        enabled = cmd(f"systemctl is-enabled '{svc}' 2>/dev/null")[1]

        if active in ("active", "inactive", "failed"):
            resultado.append({
                "servicio": svc,
                "activo": active == "active",
                "estado": active,
                "auto_inicio": enabled == "enabled",
            })

    return {"servicios": resultado}


@app.post("/servicios/reiniciar", tags=["Servicios"])
def reiniciar_servicio(
    servicio: str = Form(...),
    _: str = Depends(require_key),
):
    """Reiniciar un servicio especifico."""
    # Validar nombre de servicio (solo alfanumericos y guiones)
    clean = servicio.replace("-", "").replace("_", "")
    if not clean.isalnum():
        raise HTTPException(status_code=400, detail="Nombre de servicio invalido")

    code, _, err = cmd(f"systemctl restart '{servicio}' 2>/dev/null")
    if code != 0:
        raise HTTPException(status_code=500, detail=f"Error reiniciando {servicio}: {err}")

    # Verificar estado despues de reiniciar
    _, status, _ = cmd(f"systemctl is-active '{servicio}' 2>/dev/null")

    return {"ok": True, "servicio": servicio, "estado": status}


@app.get("/servicios/puertos", tags=["Servicios"])
def puertos_abiertos(_: str = Depends(require_key)):
    """Puertos abiertos en el VPS."""
    _, out, _ = cmd("ss -tuln | grep LISTEN")
    puertos = [l.strip() for l in out.splitlines() if l.strip()]
    return {"puertos": puertos}


# =============================================================================
# ENDPOINTS — SISTEMA VPS
# =============================================================================

@app.get("/sistema/info", tags=["Sistema VPS"])
def info_sistema(_: str = Depends(require_key)):
    """Informacion completa del sistema."""
    _, so, _ = cmd("cat /etc/os-release | grep PRETTY_NAME | cut -d'\"' -f2")
    _, kernel, _ = cmd("uname -r")
    _, arch, _ = cmd("uname -m")
    _, hostname, _ = cmd("hostname")
    _, uptime, _ = cmd("uptime -p 2>/dev/null || uptime")
    _, cpu_model, _ = cmd("grep 'model name' /proc/cpuinfo | head -1 | cut -d':' -f2")
    _, cpu_cores, _ = cmd("nproc")
    _, mem_total, _ = cmd("free -h | awk '/^Mem:/{print $2}'")
    _, mem_used, _ = cmd("free -h | awk '/^Mem:/{print $3}'")
    _, mem_free, _ = cmd("free -h | awk '/^Mem:/{print $4}'")
    _, disk_total, _ = cmd("df -h / | tail -1 | awk '{print $2}'")
    _, disk_used, _ = cmd("df -h / | tail -1 | awk '{print $3}'")
    _, disk_pct, _ = cmd("df -h / | tail -1 | awk '{print $5}'")
    _, disk_free, _ = cmd("df -h / | tail -1 | awk '{print $4}'")

    return {
        "so": so or "Desconocido",
        "kernel": kernel,
        "arquitectura": arch,
        "hostname": hostname,
        "uptime": uptime or "N/A",
        "cpu": {
            "modelo": cpu_model.strip() or "N/A",
            "nucleos": cpu_cores or "N/A",
        },
        "memoria": {
            "total": mem_total or "N/A",
            "usada": mem_used or "N/A",
            "libre": mem_free or "N/A",
        },
        "disco": {
            "total": disk_total or "N/A",
            "usado": disk_used or "N/A",
            "porcentaje": disk_pct or "N/A",
            "libre": disk_free or "N/A",
        },
        "vps_ip": VPS_IP,
    }


@app.post("/sistema/limpiar-logs", tags=["Sistema VPS"])
def limpiar_logs(_: str = Depends(require_key)):
    """Limpiar logs del sistema."""
    logs = ["/var/log/syslog", "/var/log/auth.log", "/var/log/messages",
            "/var/log/kern.log", "/var/log/daemon.log", "/var/log/debug"]
    for log in logs:
        cmd(f"truncate -s 0 '{log}' 2>/dev/null")

    cmd("rm -rf /var/log/*.gz /var/log/*.1 /var/log/*.old 2>/dev/null")
    cmd("journalctl --vacuum-time=1d 2>/dev/null")
    cmd("apt-get clean 2>/dev/null")
    cmd("apt-get autoclean 2>/dev/null")

    return {"ok": True, "detalle": "Logs limpiados correctamente"}


@app.post("/sistema/tcp-optimize", tags=["Sistema VPS"])
def tcp_optimize(
    activar: int = Form(1),
    _: str = Depends(require_key),
):
    """Activar o desactivar optimizacion TCP."""
    if activar:
        # Verificar si ya esta activado
        _, check, _ = cmd("grep -c '#ADM_TCP' /etc/sysctl.conf 2>/dev/null")
        if check != "0":
            return {"ok": True, "detalle": "TCP Speed ya estaba activado"}

        cmd("""cat >> /etc/sysctl.conf <<'SYSCTL_EOF'
#ADM_TCP
net.ipv4.tcp_window_scaling = 1
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.ipv4.tcp_rmem = 4096 87380 16777216
net.ipv4.tcp_wmem = 4096 16384 16777216
net.ipv4.tcp_low_latency = 1
net.ipv4.tcp_slow_start_after_idle = 0
SYSCTL_EOF
""")
        cmd("sysctl -p 2>/dev/null")
        return {"ok": True, "detalle": "TCP Speed activado"}
    else:
        cmd("sed -i '/#ADM_TCP/,/tcp_slow_start_after_idle/d' /etc/sysctl.conf 2>/dev/null")
        cmd("sysctl -p 2>/dev/null")
        return {"ok": True, "detalle": "TCP Speed desactivado"}


@app.get("/sistema/disco", tags=["Sistema VPS"])
def uso_disco(_: str = Depends(require_key)):
    """Uso de disco del VPS."""
    _, df_out, _ = cmd("df -h | grep -E '^/dev|^Filesystem'")
    _, du_out, _ = cmd("du -sh /var/* 2>/dev/null | sort -rh | head -10")

    return {
        "filesystem": [l.strip() for l in df_out.splitlines() if l.strip()],
        "directorios_grandes": [l.strip() for l in du_out.splitlines() if l.strip()],
    }


@app.get("/sistema/memoria", tags=["Sistema VPS"])
def uso_memoria(_: str = Depends(require_key)):
    """Uso de memoria del VPS."""
    _, free_out, _ = cmd("free -h")
    _, ps_out, _ = cmd("ps aux --sort=-%mem | head -11")

    return {
        "memoria": free_out,
        "procesos": [l.strip() for l in ps_out.splitlines() if l.strip()],
    }


# --- Handler de errores global -----------------------------------------------
@app.exception_handler(Exception)
async def generic_error_handler(request: Request, exc: Exception):
    return JSONResponse(
        status_code=500,
        content={"ok": False, "detail": str(exc)},
    )


# --- Arranque ----------------------------------------------------------------
if __name__ == "__main__":
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=API_PORT,
        reload=False,
        access_log=True,
    )
