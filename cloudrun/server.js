/**
 * GeminTech CloudRun Proxy
 * WebSocket SSH/VPN — Portable en cualquier plataforma cloud
 * Auto-detecta VPS via variables de entorno PROXY_TARGETS y KEY_TARGETS
 */

const express = require("express");
const http = require("http");
const httpProxy = require("http-proxy");
const net = require("net");
const { URL } = require("url");

const app = express();

// --- Variables de entorno ---
const key = process.env.KEY_TARGETS || "";
const targetsEnv = process.env.PROXY_TARGETS || "";

// --- Normalización y carga de múltiples targets ---
const targets = targetsEnv
  .split(",")
  .map(t => {
    const trimmed = t.trim();
    return /^https?:\/\//i.test(trimmed) ? trimmed : `http://${trimmed}`;
  });

let current = 0;
function getNextTarget() {
  const target = targets[current];
  current = (current + 1) % targets.length;
  return target;
}

// --- Configuración del proxy ---
const proxy = httpProxy.createProxyServer({
  changeOrigin: true,
  ws: true,
  secure: false,
  xfwd: true,
  timeout: 0,
  proxyTimeout: 0,
});

// --- Conexión persistente ---
proxy.on("proxyReq", (proxyReq) => {
  proxyReq.setHeader("Connection", "keep-alive");
});

proxy.on("error", (err, req, res) => {
  log("❌ Error en proxy:", err.message);
  if (!res.headersSent) {
    res.writeHead(502, { "Content-Type": "text/plain" });
  }
  res.end("Proxy error: " + err.message);
});

// --- Logging Cloud Run ---
const log = (...args) => console.log(new Date().toISOString(), "-", ...args);

// --- Endpoints de diagnóstico ---
app.get("/", (_req, res) => res.status(200).send("GeminTech Proxy OK"));
app.get("/healthz", (_req, res) => res.json({ status: "ok", service: "gemintech-proxy", backends: targets }));
app.get("/readyz", (_req, res) => res.json({ ready: true, backends: targets }));

// --- Proxy HTTP ---
app.use((req, res) => {
  const auth = req.headers["x-auth-key"];
  if (auth !== key) return res.status(403).send("Forbidden: invalid key");

  const target = getNextTarget();
  log("📡 HTTP ->", target, req.method, req.url);
  proxy.web(req, res, { target });
});

// --- Proxy WebSocket ---
const server = http.createServer(app);
server.on("upgrade", (req, socket, head) => {
  const auth = req.headers["x-auth-key"];
  if (auth !== key) {
    socket.destroy();
    return;
  }
  const target = getNextTarget();
  log("🔄 WS Upgrade ->", target);
  proxy.ws(req, socket, head, { target });
});

// --- Health check TCP para cada backend ---
function checkBackends() {
  targets.forEach(t => {
    try {
      const url = new URL(t);
      const host = url.hostname;
      const port = parseInt(url.port || (url.protocol === "https:" ? 443 : 80));

      const socket = net.connect({ host, port, timeout: 2000 }, () => {
        log("🟢 Backend activo ->", `${host}:${port}`);
        socket.end();
      });
      socket.on("error", () => log("🔴 Backend no accesible ->", `${host}:${port}`));
      socket.on("timeout", () => {
        log("⏱️ Timeout backend ->", `${host}:${port}`);
        socket.destroy();
      });
    } catch (e) {
      log("Error verificando backend:", e.message);
    }
  });
}

// --- Arranque del servidor ---
const port = process.env.PORT || 8080;
server.listen(port, "0.0.0.0", () => {
  log("✅ GeminTech Proxy operativo en puerto", port);
  log("📍 Backends configurados:", targets);
  checkBackends();
  setInterval(checkBackends, 30000);
});

// --- Mantener sesión viva en Cloud Run ---
setInterval(() => {
  http.get(`http://localhost:${port}/healthz`, (res) => res.resume()).on("error", () => {});
}, 240000);

// --- Heartbeat global WS ---
setInterval(() => {
  proxy.emit("ping");
  log("🔁 Keep-Alive frame enviado");
}, 20000);

// --- Captura de señales del contenedor ---
process.on("SIGTERM", () => {
  log("🧩 Recibido SIGTERM — apagando GeminTech Proxy limpiamente...");
  server.close(() => process.exit(0));
});
process.on("uncaughtException", (err) => {
  log("⚠️ Excepción no controlada:", err.message);
});
