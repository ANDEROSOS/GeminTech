#!/bin/bash
# ============================================================
# GeminTech CloudRun Deploy
# Funciona en Google Cloud Shell sin configuración manual.
# Uso: bash deploy.sh
# ============================================================
set -e

# ─── Colores ─────────────────────────────────────────────────
R='\033[1;31m'; G='\033[1;32m'; Y='\033[1;33m'
C='\033[1;36m'; W='\033[1;37m'; NC='\033[0m'
ok()   { echo -e "  ${G}✓${NC} $1"; }
info() { echo -e "  ${Y}→${NC} $1"; }
err()  { echo -e "  ${R}✗ ERROR:${NC} $1"; exit 1; }
line() { echo -e "${C}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; }

# ─── Banner ──────────────────────────────────────────────────
clear
line
echo -e "   ${W}★  GeminTech CloudRun — Deploy Wizard  ★${NC}"
line
echo ""

# ─── Verificar gcloud ────────────────────────────────────────
if ! command -v gcloud &>/dev/null; then
    err "gcloud no encontrado. Usa Google Cloud Shell: console.cloud.google.com"
fi

# ─── Detectar Google Cloud Shell ─────────────────────────────
if [[ "$CLOUD_SHELL" == "true" ]]; then
    ok "Ejecutando en Google Cloud Shell (autenticación automática)"
else
    info "No estás en Cloud Shell. Verificando autenticación gcloud..."
    gcloud auth print-access-token &>/dev/null || {
        info "Iniciando autenticación..."
        gcloud auth login --no-launch-browser
    }
fi

# ─── Auto-detectar Proyecto GCP ──────────────────────────────
PROJECT_ID=$(gcloud config get-value project 2>/dev/null)
if [[ -z "$PROJECT_ID" ]]; then
    echo ""
    info "Proyectos disponibles:"
    gcloud projects list --format="table(projectId,name)" 2>/dev/null | head -10
    echo ""
    read -p "  Ingresa el Project ID: " PROJECT_ID
    gcloud config set project "$PROJECT_ID"
fi
ok "Proyecto GCP: ${C}${PROJECT_ID}${NC}"

# ─── Nombre del servicio ─────────────────────────────────────
SERVICE_NAME="gemintech-proxy"
ok "Servicio: ${C}${SERVICE_NAME}${NC}"

# ─── Seleccionar Región ───────────────────────────────────────
REGION="${3:-${CLOUDRUN_REGION:-}}"
if [[ -z "$REGION" ]]; then
    echo ""
    echo -e "  ${W}Selecciona la región de Cloud Run:${NC}"
    echo -e "  ${C}[1]${NC} southamerica-east1 (São Paulo)   ~50ms Ecuador ${G}⭐ RECOMENDADO${NC}"
    echo -e "  ${C}[2]${NC} us-south1          (Texas EEUU)  ~60ms Ecuador"
    echo -e "  ${C}[3]${NC} us-central1        (Iowa EEUU)   ~80ms Ecuador"
    echo -e "  ${C}[4]${NC} us-east1           (Carolina)    ~75ms Ecuador"
    echo -e "  ${C}[5]${NC} europe-west1       (Bélgica)     ~150ms Ecuador"
    echo ""
    read -p "  Opción [1-5] (Enter = São Paulo): " REG_OPT
    case "$REG_OPT" in
        ""|1) REGION="southamerica-east1";;
        2)   REGION="us-south1";;
        3)   REGION="us-central1";;
        4)   REGION="us-east1";;
        5)   REGION="europe-west1";;
        *)   REGION="$REG_OPT";;
    esac
fi
ok "Región: ${C}${REGION}${NC}"

# ─── IP del VPS ────────────────────────────────────────
echo ""
read -p "  IP del VPS (ejemplo: 1.2.3.4): " VPS_IP_INPUT
[[ -z "$VPS_IP_INPUT" ]] && err "Debes ingresar la IP del VPS"
VPS_IP="${VPS_IP_INPUT}"
VPS_TARGET="${VPS_IP}:80"
ok "VPS Target: ${C}${VPS_TARGET}${NC}"

# ─── Auth Key ─────────────────────────────────────────────────
read -p "  Auth Key (Enter para usar Ecuador2026_Secreto): " AUTH_INPUT
AUTH_KEY="${AUTH_INPUT:-Ecuador2026_Secreto}"
ok "Auth Key configurada"

# ─── Confirmación ─────────────────────────────────────────────
echo ""
line
echo -e "  ${W}Resumen del deploy:${NC}"
echo -e "  Servicio:   ${C}${SERVICE_NAME}${NC}"
echo -e "  Proyecto:   ${C}${PROJECT_ID}${NC}"
echo -e "  Región:     ${C}${REGION}${NC}"
echo -e "  VPS:        ${C}${VPS_TARGET}${NC}"
line
echo ""
read -p "  ¿Confirmar deploy? [S/n]: " CONFIRM
[[ "${CONFIRM,,}" == "n" ]] && { echo "  Cancelado."; exit 0; }

# ─── Habilitar APIs necesarias (solo primera vez) ─────────────
echo ""
info "Habilitando APIs de GCP..."
gcloud services enable run.googleapis.com \
    cloudbuild.googleapis.com \
    artifactregistry.googleapis.com \
    --project="$PROJECT_ID" --quiet 2>/dev/null || true
ok "APIs habilitadas"

# ─── Deploy ──────────────────────────────────────────────────
echo ""
info "Desplegando GeminTech Proxy en Cloud Run..."
gcloud run deploy "$SERVICE_NAME" \
    --source "$(dirname "$0")" \
    --region "$REGION" \
    --project "$PROJECT_ID" \
    --allow-unauthenticated \
    --set-env-vars "PROXY_TARGETS=${VPS_TARGET},KEY_TARGETS=${AUTH_KEY}" \
    --timeout=3600 \
    --min-instances=0 \
    --max-instances=3 \
    --concurrency=80 \
    --memory=512Mi \
    --cpu=1 \
    --no-cpu-throttling \
    --session-affinity \
    --quiet

# ─── Forzar tráfico a última revisión ────────────────────────
gcloud run services update-traffic "$SERVICE_NAME" \
    --region "$REGION" \
    --project "$PROJECT_ID" \
    --to-latest --quiet 2>/dev/null || true

# ─── Obtener URL ─────────────────────────────────────────────
URL=$(gcloud run services describe "$SERVICE_NAME" \
    --region "$REGION" \
    --project "$PROJECT_ID" \
    --format="value(status.url)" 2>/dev/null)

HOST="${URL#https://}"

# ─── Resultado final ──────────────────────────────────────────
echo ""
line
echo -e "  ${G}✅ GeminTech Proxy desplegado exitosamente!${NC}"
line
echo -e "  URL: ${C}${URL}${NC}"
echo ""
echo -e "  ${W}╔═ Configuración NPV Tunnel / HTTP Injector ══╗${NC}"
echo -e "  ${W}║${NC} SSH Host: ${C}${HOST}${NC}"
echo -e "  ${W}║${NC} Puerto:   ${C}443${NC}"
echo -e "  ${W}║${NC} SNI:      ${C}firebase-settings.crashlytics.com${NC}"
echo -e "  ${W}║${NC} UDPGW:    ${C}127.0.0.1:7300${NC}"
echo -e "  ${W}╚═══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${W}Payload HTTP:${NC}"
echo    "  GET / HTTP/1.1[crlf]"
echo    "  Host: ${HOST}[crlf]"
echo    "  Connection: Upgrade[crlf]"
echo    "  Upgrade: websocket[crlf]"
echo    "  x-auth-key: ${AUTH_KEY}[crlf][crlf]"
echo ""
echo -e "  ${W}Panel Web — actualizar config.php:${NC}"
echo    "  define('API_BASE', 'http://${VPS_IP}:9000');"
line
