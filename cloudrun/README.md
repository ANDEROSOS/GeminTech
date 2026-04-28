# GeminTech CloudRun — Proxy WebSocket

[![Open in Cloud Shell](https://gstatic.com/cloudssh/images/open-btn.svg)](https://shell.cloud.google.com/cloudshell/editor?cloudshell_git_repo=https://github.com/TU_USUARIO/GeminTech&cloudshell_open_in_editor=cloudrun/server.js&cloudshell_working_dir=cloudrun)

---

## 🚀 Deploy en Google Cloud Shell (1 comando)

### Paso 1 — Abrir Google Cloud Shell
👉 Ir a **[console.cloud.google.com](https://console.cloud.google.com)**  
Clic en el ícono **`>_`** (esquina superior derecha)

### Paso 2 — Clonar el repo y ejecutar

```bash
git clone https://github.com/TU_USUARIO/GeminTech.git
cd GeminTech/cloudrun
bash deploy.sh
```

### El wizard hace todo — solo presiona Enter:
```
✓ Detecta Google Cloud Shell (ya autenticado)
✓ Auto-detecta tu Proyecto GCP

Región [Enter = São Paulo ★ mejor para Ecuador]:
  [1] southamerica-east1  ~50ms  ⭐ RECOMENDADO (default)
  [2] us-south1           ~60ms
  [3] us-central1         ~80ms
  ...

IP del VPS   [Enter = 172.245.184.188]: 

Auth Key     [Enter = Ecuador2026_Secreto]:

✔ Habilita APIs de GCP
✔ Despliega el contenedor
✅ Muestra URL + payload NPV Tunnel completo
```

---

## 🔄 Migrar a otro Proyecto GCP

Cuando se acaban los créditos o quieres mover a otra cuenta:

1. Abrir Cloud Shell en el **nuevo proyecto GCP**
2. Correr:
```bash
git clone https://github.com/TU_USUARIO/GeminTech.git
cd GeminTech/cloudrun
bash deploy.sh
# El wizard detecta automáticamente el nuevo proyecto
```
3. Copiar la nueva URL al `config.php` del panel web
4. ✅ Listo — menos de 3 minutos

---

## 🔄 Migrar a otro VPS

Si cambias de servidor:

```bash
cd GeminTech/cloudrun
bash deploy.sh
# Cuando te pregunte la IP del VPS → ingresa la nueva IP
```

---

## ⚙️ Variables de Entorno (Cloud Run)

Solo 2 variables necesarias — el wizard las configura automáticamente:

| Variable | Descripción |
|---|---|
| `PROXY_TARGETS` | `IP_VPS:80` (puede ser múltiple, separado por coma) |
| `KEY_TARGETS` | Clave de autenticación WebSocket |

---

## 📋 Configuración del Servicio (óptima para VPN)

| Parámetro | Valor | Motivo |
|---|---|---|
| `timeout` | `3600s` | WebSocket no se corta en 5 min |
| `cpu-throttling` | `false` | Sin lag en conexiones persistentes |
| `session-affinity` | `true` | Misma instancia por cliente |
| `min-instances` | `0` | Ahorra créditos cuando no hay uso |
| `max-instances` | `3` | Escala automáticamente |

---

## 🌍 Regiones recomendadas para Ecuador

| # | Región | Ubicación | Latencia |
|---|---|---|---|
| **Enter** | `southamerica-east1` | São Paulo 🇧🇷 | **~50ms ⭐ DEFAULT** |
| 2 | `us-south1` | Texas EEUU | ~60ms |
| 3 | `us-central1` | Iowa EEUU | ~80ms |
| 4 | `us-east1` | Carolina Sur | ~75ms |
| 5 | `europe-west1` | Bélgica | ~150ms |
