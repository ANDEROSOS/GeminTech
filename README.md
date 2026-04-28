# GeminTech VPS Panel

[![Open in Cloud Shell](https://gstatic.com/cloudssh/images/open-btn.svg)](https://shell.cloud.google.com/cloudshell/editor?cloudshell_git_repo=https://github.com/ANDEROSOS/GeminTech&cloudshell_working_dir=cloudrun)

Sistema completo de gestión VPS para SSH/VPN. Portable en cualquier proveedor.

> 🟢 **Cloud Run activo:** `https://gemintech-proxy-49300283327.southamerica-east1.run.app` — Region: `southamerica-east1`

---

## 📁 Estructura

```
GeminTech/
├── install.sh              ← Instalador VPS (1 comando, auto-detección)
├── .env.example            ← Template de variables
│
├── api/                    ← Backend FastAPI (gestión usuarios SSH)
│   ├── main.py             ← API completa
│   ├── requirements.txt
│   └── README.md
│
├── cloudrun/               ← Proxy WebSocket (Google Cloud Run)
│   ├── server.js           ← Proxy Node.js
│   ├── package.json
│   ├── Dockerfile
│   ├── deploy.sh           ← Deploy portable con auto-detección
│   └── README.md
│
├── panel/                  ← Scripts bash interactivos
│   ├── menu                ← Comando: gemintech
│   ├── module.sh, user.sh, services.sh
│   ├── extras.sh, config.sh, cleanup.sh
│   └── README.md
│
├── web-panel/              ← Panel PHP (AlwaysData / cualquier hosting)
│   ├── index.php           ← Panel administrativo
│   ├── config.php          ← ⚙️ Editar con tu VPS IP y API Key
│   ├── .htaccess
│   └── README.md
│
└── configs/
    ├── NPV-TUNNEL-CONFIG.txt
    ├── payload-npvtunnel.txt
    └── systemd/            ← Services Linux para el VPS
        ├── fastapi.service
        ├── ws-ssh.service
        └── badvpn.service
```

---

## 🚀 Instalación VPS — 1 Comando

```bash
# Como root en el VPS:
bash <(curl -sL https://raw.githubusercontent.com/AdmRufus/GeminTech/main/install.sh)
```

**Auto-detecta:**
- IP pública del VPS
- Versión de Debian/Ubuntu
- Dependencias faltantes

**Instala automáticamente:**
- Panel bash (`gemintech`)
- WebSocket SSH (puerto 80)
- BadVPN UDPGW (puerto 7300)
- API GeminTech (puerto 9000)

---

## 🌐 Panel Web (AlwaysData)

1. Subir carpeta `web-panel/` al hosting
2. Editar `config.php`:
   ```php
   // Clase GeminConfig — editar estos valores:
   public static string $api_base = 'http://TU_IP_VPS:9000';
   public static string $api_key  = 'TU_API_KEY';
   ```
3. Abrir en el navegador ✅

---

## ☁️ Cloud Run (Proxy WebSocket)

```bash
cd cloudrun
bash deploy.sh
# Menú interactivo: elige región, ingresa IP del VPS
```

**Migrar a otra región:**
```bash
bash deploy.sh NUEVA_IP:80 TU_KEY us-central1
```

---

## 📡 Arquitectura

```
[App Móvil: NPVTunnel / HTTP Injector]
        │ HTTPS:443 WebSocket
        ▼
[Cloud Run — gemintech-proxy]
  cloudrun/server.js
        │ HTTP:80 WebSocket
        ▼
[VPS — cualquier proveedor]
  ├── :22   SSH
  ├── :80   WebSocket SSH
  ├── :7300 BadVPN UDP
  └── :9000 GeminTech API
              │ x-api-key
              ▼
[web-panel/index.php]
  Panel PHP en AlwaysData
```

---

## 🔑 Credenciales por defecto

| Variable | Valor |
|---|---|
| API Key | `Ecuador2026_Secreto_Api` |
| WS Key | `Ecuador2026_Secreto` |

---

## 🔄 Migrar a otro VPS

1. Instalar en el nuevo VPS: `bash <(curl -sL https://raw.githubusercontent.com/AdmRufus/GeminTech/main/install.sh)`
2. Actualizar Cloud Run con la nueva IP: `bash cloudrun/deploy.sh`
3. Actualizar `web-panel/config.php` con la nueva IP
4. ¡Listo!
