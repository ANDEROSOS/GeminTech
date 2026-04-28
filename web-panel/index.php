<?php
/**
 * GeminTech Multi-Admin Panel
 * Sistema de Super Administrador → Administradores → Usuarios
 * @author GeminTech | UI: Cyberpunk HUD Edition
 */

session_start();

if (!file_exists(__DIR__ . '/config.php')) {
    die('<h2 style="font-family:monospace;color:#ff0080;padding:20px;background:#050508;">
    ⚠️ Falta config.php<br>
    <small>El archivo <b>config.php</b> no existe. Sube este archivo al servidor.</small>
    </h2>');
}
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: *');

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function admins_file() { return __DIR__ . '/data_admins.json'; }
function pagos_file()  { return __DIR__ . '/data_pagos.json'; }

function admins_leer(): array {
    $f = admins_file();
    if (!file_exists($f)) {
        $default = ['super' => ['username' => 'superadmin','password' => password_hash('admin123', PASSWORD_BCRYPT),'role' => 'super','email' => 'admin@gemintech.com','created' => date('c')]];
        file_put_contents($f, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    return json_decode(file_get_contents($f), true) ?: [];
}

function admins_guardar(array $data): void {
    file_put_contents(admins_file(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function pagos_leer(): array {
    $f = pagos_file();
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?: [];
}

function pagos_guardar(array $data): void {
    file_put_contents(pagos_file(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function current_user() { return $_SESSION['admin_user'] ?? null; }
function is_super() { $u = current_user(); return $u && $u['role'] === 'super'; }
function is_admin() { $u = current_user(); return $u && ($u['role'] === 'admin' || $u['role'] === 'super'); }
function require_login() { if (!current_user()) { header('Location: ?page=login'); exit; } }
function require_super() { require_login(); if (!is_super()) die('⛔ Acceso denegado.'); }

// ═══════════════════════════════════════════════════════════
// API HANDLER
// ═══════════════════════════════════════════════════════════

$action = $_REQUEST['action'] ?? '';
$inputJSON = json_decode(file_get_contents('php://input'), true);
if (!$action && isset($inputJSON['action'])) {
    $action = $inputJSON['action'];
    $_REQUEST = array_merge($_REQUEST, $inputJSON);
}

if ($action) {
    header('Content-Type: application/json');

    function api(string $method, string $path, array $body = []): array {
        $user = current_user();
        $vpsTarget = $_SERVER['HTTP_X_VPS_TARGET'] ?? ($user['vps_url'] ?? GeminConfig::$api_base);
        $vpsKey    = $_SERVER['HTTP_X_VPS_KEY']    ?? ($user['vps_key'] ?? GeminConfig::$api_key);
        $ch = curl_init(rtrim($vpsTarget, '/') . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['x-api-key: ' . $vpsKey], CURLOPT_CUSTOMREQUEST => $method]);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        $raw = curl_exec($ch); curl_close($ch);
        return json_decode((string)$raw, true) ?? ['error' => 'Error conectando al backend'];
    }

    if ($action === 'login') {
        $username = $_REQUEST['username'] ?? ''; $password = $_REQUEST['password'] ?? '';
        $admins = admins_leer();
        foreach ($admins as $key => $admin) {
            if ($admin['username'] === $username && password_verify($password, $admin['password'])) {
                $_SESSION['admin_user'] = $admin; $_SESSION['admin_key'] = $key;
                echo json_encode(['ok' => true, 'role' => $admin['role']]); exit;
            }
        }
        echo json_encode(['error' => 'Usuario o contraseña incorrectos']); exit;
    }
    if ($action === 'logout') { session_destroy(); echo json_encode(['ok' => true]); exit; }

    if ($action === 'admins_listar') {
        require_super(); $admins = admins_leer(); $list = [];
        foreach ($admins as $key => $a) {
            if ($a['role'] !== 'super') $list[] = ['key' => $key,'username' => $a['username'],'email' => $a['email'] ?? '','vps_url' => $a['vps_url'] ?? '','created' => $a['created'] ?? ''];
        }
        echo json_encode(['ok' => true, 'admins' => $list]); exit;
    }

    if ($action === 'admin_crear') {
        require_super();
        $username = trim($_REQUEST['username'] ?? ''); $password = $_REQUEST['password'] ?? '';
        $email = trim($_REQUEST['email'] ?? ''); $vps_url = trim($_REQUEST['vps_url'] ?? ''); $vps_key = trim($_REQUEST['vps_key'] ?? '');
        if (!$username || !$password) { echo json_encode(['error' => 'Username y password requeridos']); exit; }
        $admins = admins_leer();
        foreach ($admins as $a) { if ($a['username'] === $username) { echo json_encode(['error' => 'Username ya existe']); exit; } }
        $key = 'admin_' . uniqid();
        $admins[$key] = ['username' => $username,'password' => password_hash($password, PASSWORD_BCRYPT),'role' => 'admin','email' => $email,'vps_url' => $vps_url,'vps_key' => $vps_key,'created' => date('c')];
        admins_guardar($admins); echo json_encode(['ok' => true, 'message' => 'Administrador creado']); exit;
    }

    if ($action === 'admin_eliminar') {
        require_super(); $key = $_REQUEST['key'] ?? ''; $admins = admins_leer();
        if (!isset($admins[$key]) || $admins[$key]['role'] === 'super') { echo json_encode(['error' => 'No se puede eliminar']); exit; }
        unset($admins[$key]); admins_guardar($admins); echo json_encode(['ok' => true]); exit;
    }

    require_login();

    $res = match($action) {
        'status'  => api('GET', '/status'),
        'listar'  => api('GET', '/usuarios/listar'),
        'monitor' => api('GET', '/usuarios/monitor'),
        'crear'   => api('POST', '/usuario/crear', ['username' => $_REQUEST['username'] ?? '','password' => $_REQUEST['password'] ?? '','dias' => $_REQUEST['dias'] ?? 7,'minutos' => $_REQUEST['minutos'] ?? 0]),
        'eliminar'=> api('POST', '/usuario/eliminar', ['username' => $_REQUEST['username'] ?? '']),
        'renovar' => api('POST', '/usuario/renovar', ['username' => $_REQUEST['username'] ?? '','dias' => $_REQUEST['dias'] ?? 7,'minutos' => $_REQUEST['minutos'] ?? 0]),
        'password'=> api('POST', '/usuario/password', ['username' => $_REQUEST['username'] ?? '','password' => $_REQUEST['password'] ?? '']),
        'pagos_listar'   => (function(){
            $pagos = pagos_leer();
            if (!is_super()) { $me = current_user()['username']; $pagos = array_values(array_filter($pagos, fn($p) => ($p['admin'] ?? '') === $me)); }
            return ['ok' => true, 'pagos' => $pagos];
        })(),
        'pagos_agregar'  => (function(){
            $user = $_REQUEST['user'] ?? ''; $amount = floatval($_REQUEST['amount'] ?? 0); $type = $_REQUEST['type'] ?? 'cash'; $note = $_REQUEST['note'] ?? ''; $admin = current_user()['username'];
            if (!$user || $amount <= 0) return ['error' => 'Usuario y monto requeridos'];
            $pagos = pagos_leer(); $pagos[] = ['user' => $user,'amount' => $amount,'type' => $type,'note' => $note,'admin' => $admin,'date' => date('c')]; pagos_guardar($pagos); return ['ok' => true];
        })(),
        'pagos_eliminar' => (function(){
            $idx = intval($_REQUEST['idx'] ?? -1); $pagos = pagos_leer();
            if ($idx < 0 || $idx >= count($pagos)) return ['error' => 'Índice inválido'];
            if (!is_super() && ($pagos[$idx]['admin'] ?? '') !== current_user()['username']) return ['error' => 'No puedes eliminar pagos de otro admin'];
            array_splice($pagos, $idx, 1); pagos_guardar($pagos); return ['ok' => true];
        })(),
        'pagos_limpiar'  => (function(){
            if (is_super()) { pagos_guardar([]); return ['ok' => true]; }
            $me = current_user()['username']; $pagos = pagos_leer();
            $pagos = array_values(array_filter($pagos, fn($p) => ($p['admin'] ?? '') !== $me));
            pagos_guardar($pagos); return ['ok' => true];
        })(),
        'vpn_config_leer' => (function(){
            if (!is_super()) return ['error' => 'Solo Super Admin puede acceder a VPN Config'];
            $ch = curl_init(GeminConfig::$firebase_url . GeminConfig::$vpn_path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 8]);
            $r = curl_exec($ch); curl_close($ch);
            $cfg = json_decode((string)$r, true) ?: [];
            $defaults = ['vps_host' => 'api.google.com','vps_port' => 443,'sni_host' => 'config.miclaro.smapps.mx','payload' => '','socks_port' => 1080,'udpgw_port' => 7300];
            return ['ok' => true, 'config' => array_merge($defaults, $cfg)];
        })(),
        'vpn_config_guardar' => (function(){
            if (!is_super()) return ['error' => 'Solo Super Admin puede modificar VPN Config'];
            $data = json_encode(['vps_host' => trim($_REQUEST['vps_host'] ?? ''),'vps_port' => (int)($_REQUEST['vps_port'] ?? 443),'sni_host' => trim($_REQUEST['sni_host'] ?? ''),'payload' => trim($_REQUEST['payload'] ?? ''),'socks_port' => (int)($_REQUEST['socks_port'] ?? 1080),'udpgw_port' => (int)($_REQUEST['udpgw_port'] ?? 7300)]);
            $ch = curl_init(GeminConfig::$firebase_url . GeminConfig::$vpn_path);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $data, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 8]);
            $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            return $httpCode === 200 ? ['ok' => true] : ['error' => 'Firebase HTTP ' . $httpCode];
        })(),
        default => ['error' => 'Acción no reconocida']
    };
    echo json_encode($res); exit;
}

// ═══════════════════════════════════════════════════════════
// RENDER PAGES
// ═══════════════════════════════════════════════════════════

$page = $_GET['page'] ?? 'panel';
if ($page === 'login' && current_user()) { header('Location: ?page=panel'); exit; }
if ($page !== 'login') require_login();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,user-scalable=yes">
<title><?= $page === 'login' ? 'GeminTech // LOGIN' : 'GeminTech // CONTROL HUD' ?></title>
<link rel="icon" href="logo.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Exo+2:ital,wght@0,100..900;1,100..900&family=Orbitron:wght@400;500;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════
   GEMINTECH CYBERPUNK HUD — DESIGN SYSTEM
   ════════════════════════════════════════════ */
:root {
  --bg:       #04050d;
  --bg1:      #070914;
  --bg2:      #0b0d1f;
  --bg3:      #0f1128;
  --cyan:     #00f5ff;
  --cyan2:    #00c8ff;
  --magenta:  #ff007f;
  --magenta2: #cc0066;
  --green:    #00ff88;
  --amber:    #ffaa00;
  --red:      #ff2244;
  --violet:   #9b5de5;
  --text:     #d0e4ff;
  --text2:    #8899bb;
  --text3:    #445577;
  --border:   rgba(0,245,255,.12);
  --border2:  rgba(0,245,255,.25);
  --border3:  rgba(0,245,255,.45);
  --glow-c:   0 0 20px rgba(0,245,255,.5);
  --glow-m:   0 0 20px rgba(255,0,127,.5);
  --glow-g:   0 0 20px rgba(0,255,136,.4);
  --f-hud:    'Orbitron', monospace;
  --f-body:   'Exo 2', sans-serif;
  --f-mono:   'Share Tech Mono', monospace;
  --sidebar-w: min(265px,85vw);
  --tr:       .2s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html,body { height:100%; }
body {
  font-family: var(--f-body);
  background: var(--bg);
  color: var(--text);
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
  overflow: hidden;
}
body.login-page { overflow: auto; height: auto; min-height: 100%; }

/* ─── BACKGROUNDS ─── */
.hud-bg {
  position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none;
}
.hud-bg::before {
  content: '';
  position: absolute; inset: 0;
  background:
    radial-gradient(ellipse 80% 60% at 20% 0%, rgba(0,245,255,.07) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 80% 100%, rgba(255,0,127,.06) 0%, transparent 55%),
    radial-gradient(ellipse 40% 40% at 50% 50%, rgba(9,11,30,.95) 0%, transparent 70%);
}
.hud-grid {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(0,245,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,245,255,.025) 1px, transparent 1px);
  background-size: 30px 30px;
  animation: gridDrift 60s linear infinite;
}
@keyframes gridDrift { to { background-position: 30px 30px; } }
.hud-scan {
  position: absolute; inset: 0;
  background: repeating-linear-gradient(0deg, transparent, transparent 3px, rgba(0,0,0,.06) 3px, rgba(0,0,0,.06) 4px);
  pointer-events: none; z-index: 1;
}
.hud-corner {
  position: absolute; width: 60px; height: 60px; pointer-events: none;
}
.hud-corner.tl { top: 0; left: 0; border-top: 2px solid var(--cyan); border-left: 2px solid var(--cyan); opacity: .3; }
.hud-corner.tr { top: 0; right: 0; border-top: 2px solid var(--cyan); border-right: 2px solid var(--cyan); opacity: .3; }
.hud-corner.bl { bottom: 0; left: 0; border-bottom: 2px solid var(--cyan); border-left: 2px solid var(--cyan); opacity: .3; }
.hud-corner.br { bottom: 0; right: 0; border-bottom: 2px solid var(--cyan); border-right: 2px solid var(--cyan); opacity: .3; }

/* ─── LAYOUT ─── */
.layout {
  display: flex;
  height: 100vh; height: 100dvh;
  position: relative; z-index: 2;
}

/* ─── SIDEBAR ─── */
.sidebar {
  position: fixed; left: 0; top: 0; bottom: 0;
  transform: translateX(-100%);
  z-index: 200;
  width: min(265px,85vw); min-width: unset;
  height: 100%;
  display: flex; flex-direction: column;
  background: rgba(4,5,13,.92);
  border-right: 1px solid var(--border2);
  overflow: hidden;
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}
.sidebar.open { transform: translateX(0); }
.sidebar::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 100%;
  background: linear-gradient(180deg, rgba(0,245,255,.04) 0%, transparent 40%);
  pointer-events: none;
}
.sidebar-accent {
  position: absolute; top: 0; left: 0; width: 2px; height: 100%;
  background: linear-gradient(180deg, var(--cyan) 0%, rgba(0,245,255,.1) 60%, transparent 100%);
}

.sidebar-header {
  padding: 22px 20px 18px;
  border-bottom: 1px solid var(--border);
  position: relative;
}
.brand-logo {
  display: flex; align-items: center; gap: 12px;
}
.brand-hex {
  width: 44px; height: 44px; flex-shrink: 0;
  background: linear-gradient(135deg, rgba(0,245,255,.15), rgba(0,245,255,.05));
  border: 1px solid var(--border3);
  clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%);
  display: grid; place-items: center;
  position: relative;
  box-shadow: 0 0 15px rgba(0,245,255,.2);
  overflow: hidden;
}
.brand-hex img {
  width: 32px; height: 32px; object-fit: contain;
}
.brand-hex span {
  font-family: var(--f-hud);
  font-size: 13px; font-weight: 800;
  color: var(--cyan);
  text-shadow: 0 0 10px var(--cyan);
}
.brand-text h2 {
  font-family: var(--f-hud);
  font-size: 15px; font-weight: 700;
  color: #fff; letter-spacing: 2px;
}
.brand-text h2 span { color: var(--cyan); }
.brand-sub {
  font-family: var(--f-mono);
  font-size: 9px; color: var(--text2);
  letter-spacing: 2px; margin-top: 3px;
  text-transform: uppercase;
}
.brand-sub.super { color: var(--magenta); }

/* NAV */
.sidebar-nav {
  flex: 1; overflow-y: auto; padding: 12px 0;
}
.sidebar-nav::-webkit-scrollbar { width: 3px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(0,245,255,.15); }

.nav-section-label {
  padding: 14px 20px 6px;
  font-family: var(--f-hud);
  font-size: 8px; letter-spacing: 3px;
  text-transform: uppercase;
  color: var(--text3);
  font-weight: 600;
}

.nav-item {
  display: flex; align-items: center; gap: 13px;
  padding: 12px 20px;
  cursor: pointer;
  color: var(--text2);
  font-family: var(--f-body);
  font-size: 13px; font-weight: 500;
  position: relative;
  border-left: 2px solid transparent;
  transition: all var(--tr);
  user-select: none;
  -webkit-tap-highlight-color: transparent;
  touch-action: manipulation;
}
.nav-item:hover {
  color: var(--text);
  background: rgba(0,245,255,.04);
  border-left-color: rgba(0,245,255,.3);
}
.nav-item.active {
  color: var(--cyan);
  background: rgba(0,245,255,.07);
  border-left-color: var(--cyan);
  box-shadow: inset 0 0 30px rgba(0,245,255,.04);
}
.nav-item.active .nav-icon { filter: drop-shadow(0 0 6px var(--cyan)); }
.nav-icon { width: 18px; height: 18px; flex-shrink: 0; opacity: .7; }
.nav-item.active .nav-icon,
.nav-item:hover .nav-icon { opacity: 1; }
.nav-badge {
  margin-left: auto;
  font-family: var(--f-mono);
  font-size: 10px; padding: 2px 7px;
  background: rgba(0,245,255,.12);
  border: 1px solid rgba(0,245,255,.25);
  color: var(--cyan);
  border-radius: 2px;
}

/* SIDEBAR FOOTER */
.sidebar-footer {
  padding: 14px 20px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
}
.pulse-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--text3); flex-shrink: 0;
  transition: all .4s;
}
.pulse-dot.on {
  background: var(--green);
  box-shadow: 0 0 8px var(--green);
  animation: pulseAnim 2s infinite;
}
@keyframes pulseAnim { 0%,100%{opacity:1} 50%{opacity:.4} }
.sidebar-status {
  font-family: var(--f-mono);
  font-size: 10px; color: var(--text2);
}

/* ─── MAIN ─── */
.main {
  width: 100%; min-width: 0;
  flex: 1; display: flex; flex-direction: column;
  min-height: 0; overflow: hidden;
}

/* TOPBAR */
.topbar {
  padding: 0 14px;
  height: 52px;
  display: flex; align-items: center; justify-content: space-between;
  background: rgba(4,5,13,.9);
  border-bottom: 1px solid var(--border2);
  backdrop-filter: blur(20px);
  flex-shrink: 0; gap: 10px;
}
.topbar-left { display: flex; align-items: center; gap: 14px; }
.topbar-title {
  font-family: var(--f-hud);
  font-size: 12px; font-weight: 600;
  color: #fff; letter-spacing: 1.5px;
  text-transform: uppercase;
}
.topbar-right { display: flex; align-items: center; gap: 6px; }
.topbar-time {
  display: none;
}

/* MOBILE TOGGLE */
.btn-mobile {
  display: flex;
  background: rgba(0,245,255,.08);
  border: 1px solid var(--border2);
  padding: 9px; cursor: pointer;
  color: var(--cyan); border-radius: 3px;
  transition: all var(--tr);
  -webkit-tap-highlight-color: transparent;
  touch-action: manipulation;
}
.btn-mobile:hover, .btn-mobile:active { background: rgba(0,245,255,.18); }

/* VPS PILL */
.vps-pill {
  display: flex; align-items: center; gap: 7px;
  padding: 6px 13px;
  border: 1px solid rgba(0,245,255,.25);
  background: rgba(0,245,255,.05);
  border-radius: 2px;
  cursor: pointer; transition: all var(--tr);
  font-family: var(--f-mono); font-size: 10px;
  color: var(--cyan); max-width: 110px; overflow: hidden;
}
.vps-pill:hover { background: rgba(0,245,255,.12); border-color: var(--cyan); box-shadow: var(--glow-c); }
.vps-pill-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text3); flex-shrink: 0; }
.vps-pill-dot.on { background: var(--green); box-shadow: 0 0 6px var(--green); animation: pulseAnim 2s infinite; }
.vps-pill-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* USER PILL */
.user-pill {
  display: flex; align-items: center; gap: 6px;
  padding: 5px 9px;
  border: 1px solid var(--border);
  border-radius: 2px;
  font-family: var(--f-mono); font-size: 10px;
  color: var(--text2);
}
.user-pill-role {
  display: none;
}
.user-pill-role.super { background: rgba(255,0,127,.15); border-color: rgba(255,0,127,.3); color: #ff80bf; }

/* ─── CONTENT ─── */
.content {
  flex: 1; overflow-y: auto; overflow-x: hidden;
  padding: 14px 12px 30px;
  min-height: 0;
  scroll-behavior: smooth;
  -webkit-overflow-scrolling: touch;
}
.content::-webkit-scrollbar { width: 5px; }
.content::-webkit-scrollbar-thumb { background: rgba(0,245,255,.12); border-radius: 2px; }

/* ─── PANELS ─── */
.panel { display: none; }
.panel.active { display: block; animation: panelIn .25s ease; }
@keyframes panelIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

/* ─── CARDS ─── */
.card {
  background: rgba(7,9,20,.85);
  border: 1px solid var(--border);
  border-radius: 4px;
  position: relative; overflow: hidden;
  margin-bottom: 18px;
  backdrop-filter: blur(10px);
  transition: border-color var(--tr);
}
.card:hover { border-color: var(--border2); }
.card::after {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--cyan), transparent);
  opacity: .25;
}
.card-header {
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px;
}
.card-title {
  font-family: var(--f-hud);
  font-size: 11px; font-weight: 600;
  color: #fff; letter-spacing: 1.5px;
  text-transform: uppercase;
  display: flex; align-items: center; gap: 8px;
}
.card-title .ct-accent { color: var(--cyan); }
.card-body { padding: 16px 14px; }

/* ─── STATS GRID ─── */
.stats-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px; margin-bottom: 20px;
}
.stat-card {
  padding: 16px;
  background: rgba(7,9,20,.9);
  border: 1px solid var(--border);
  border-radius: 4px;
  position: relative; overflow: hidden;
  transition: all var(--tr);
}
.stat-card:hover { border-color: var(--border2); transform: translateY(-2px); }
.stat-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 2px;
  opacity: 0; transition: opacity .6s;
}
.stat-card.loaded::before { opacity: 1; }
.stat-card:nth-child(1)::before { background: linear-gradient(90deg,transparent,var(--cyan),transparent); }
.stat-card:nth-child(2)::before { background: linear-gradient(90deg,transparent,var(--magenta),transparent); }
.stat-card:nth-child(3)::before { background: linear-gradient(90deg,transparent,var(--violet),transparent); }
.stat-card:nth-child(4)::before { background: linear-gradient(90deg,transparent,var(--green),transparent); }
.stat-label {
  font-family: var(--f-hud);
  font-size: 8px; letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--text3); margin-bottom: 12px;
  font-weight: 500;
}
.stat-value {
  font-family: var(--f-hud);
  font-size: 22px; font-weight: 700;
  line-height: 1; letter-spacing: -1px;
}
.sv-c { color: var(--cyan); text-shadow: 0 0 15px rgba(0,245,255,.5); }
.sv-m { color: var(--magenta); text-shadow: 0 0 15px rgba(255,0,127,.5); }
.sv-v { color: var(--violet); text-shadow: 0 0 15px rgba(155,93,229,.5); }
.sv-g { color: var(--green); text-shadow: 0 0 15px rgba(0,255,136,.4); }
.stat-sub {
  font-family: var(--f-mono);
  font-size: 9px; color: var(--text3); margin-top: 6px;
}

/* ─── TABLES ─── */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
table { width: 100%; border-collapse: collapse; min-width: 460px; }
thead tr {
  background: rgba(0,245,255,.03);
  border-bottom: 1px solid var(--border2);
}
th {
  padding: 11px 16px;
  font-family: var(--f-hud);
  font-size: 9px; letter-spacing: 2px;
  text-transform: uppercase; color: var(--text3);
  font-weight: 600; text-align: left;
}
td {
  padding: 12px 16px;
  font-size: 13px;
  border-bottom: 1px solid rgba(0,245,255,.05);
  color: var(--text); vertical-align: middle;
}
tbody tr { transition: background var(--tr); }
tbody tr:hover { background: rgba(0,245,255,.03); }
.td-idx { color: var(--text3); font-family: var(--f-mono); font-size: 11px; }
.td-user { color: var(--cyan); font-weight: 600; font-family: var(--f-mono); }
.td-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.expired-row { opacity: .6; }
.expired-row .td-user { text-decoration: line-through; color: var(--text3); }
.time-low { color: var(--red) !important; animation: pulseAnim 1s infinite; }

/* ─── BUTTONS ─── */
.btn-hud {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 10px 20px;
  font-family: var(--f-hud); font-size: 10px;
  font-weight: 600; letter-spacing: 1px;
  text-transform: uppercase;
  cursor: pointer; border-radius: 2px;
  transition: all var(--tr); border: 1px solid;
  position: relative; overflow: hidden;
  white-space: nowrap;
}
.btn-hud::before {
  content: '';
  position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.06), transparent);
  transition: left .4s;
}
.btn-hud:hover::before { left: 100%; }

.btn-cyan {
  color: var(--cyan); border-color: rgba(0,245,255,.4);
  background: rgba(0,245,255,.07);
}
.btn-cyan:hover { background: rgba(0,245,255,.18); box-shadow: var(--glow-c); transform: translateY(-1px); }

.btn-red {
  color: var(--red); border-color: rgba(255,34,68,.35);
  background: rgba(255,34,68,.07);
}
.btn-red:hover { background: rgba(255,34,68,.18); box-shadow: var(--glow-m); transform: translateY(-1px); }

.btn-green {
  color: var(--green); border-color: rgba(0,255,136,.35);
  background: rgba(0,255,136,.07);
}
.btn-green:hover { background: rgba(0,255,136,.18); box-shadow: var(--glow-g); transform: translateY(-1px); }

.btn-amber {
  color: var(--amber); border-color: rgba(255,170,0,.35);
  background: rgba(255,170,0,.07);
}
.btn-amber:hover { background: rgba(255,170,0,.18); box-shadow: 0 0 15px rgba(255,170,0,.35); transform: translateY(-1px); }

.btn-magenta {
  color: var(--magenta); border-color: rgba(255,0,127,.35);
  background: rgba(255,0,127,.07);
}
.btn-magenta:hover { background: rgba(255,0,127,.2); box-shadow: var(--glow-m); transform: translateY(-1px); }

/* Small action buttons */
.btn-xs {
  padding: 5px 10px;
  font-family: var(--f-mono); font-size: 10px;
  cursor: pointer; border-radius: 2px;
  transition: all var(--tr); border: 1px solid;
  background: transparent; white-space: nowrap;
  min-height: 30px;
}
.btn-xs.del { color: var(--red); border-color: rgba(255,34,68,.25); }
.btn-xs.ren { color: var(--amber); border-color: rgba(255,170,0,.22); }
.btn-xs.ext { color: var(--green); border-color: rgba(0,255,136,.22); }
.btn-xs.demo { color: var(--violet); border-color: rgba(155,93,229,.3); }
.btn-xs:hover { transform: translateY(-1px); filter: brightness(1.4); }

/* ─── FORMS ─── */
.form-grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
.form-grid.cols3 { grid-template-columns: 1fr; }
.full { grid-column: 1/-1; }

.field { display: flex; flex-direction: column; gap: 6px; }
.field label {
  font-family: var(--f-hud);
  font-size: 9px; letter-spacing: 2px;
  text-transform: uppercase; color: var(--text3);
  font-weight: 600;
}
.field input,
.field select,
.field textarea {
  background: rgba(0,0,0,.5);
  border: 1px solid var(--border2);
  border-radius: 2px; padding: 11px 13px;
  font-family: var(--f-mono); font-size: 13px;
  color: var(--text); outline: none;
  transition: border-color var(--tr), box-shadow var(--tr);
  -webkit-appearance: none;
  resize: vertical;
}
.field input::placeholder,
.field textarea::placeholder { color: var(--text3); }
.field input:focus,
.field select:focus,
.field textarea:focus {
  border-color: var(--cyan);
  box-shadow: 0 0 0 2px rgba(0,245,255,.1), 0 0 15px rgba(0,245,255,.08);
}
.field select option { background: var(--bg2); color: var(--text); }
.field-end { justify-content: flex-end; }
.form-divider { grid-column: 1/-1; height: 1px; background: var(--border); margin: 4px 0; }
.form-label {
  grid-column: 1/-1;
  font-family: var(--f-hud); font-size: 8px;
  letter-spacing: 2.5px; text-transform: uppercase;
  color: var(--text3); padding-top: 4px;
}
.form-label.danger { color: rgba(255,34,68,.5); }

/* ─── PHONE CREATE PREVIEW ─── */
.create-preview {
  background: rgba(0,245,255,.04);
  border: 1px solid var(--border2);
  border-radius: 4px;
  padding: 16px;
  margin-bottom: 16px;
  display: none;
}
.create-preview.show { display: block; }
.create-preview-title {
  font-family: var(--f-hud);
  font-size: 9px; letter-spacing: 2px;
  color: var(--cyan); margin-bottom: 12px;
  text-transform: uppercase;
}
.create-preview-row {
  display: flex; align-items: center; gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid var(--border);
}
.create-preview-row:last-child { border-bottom: none; }
.create-preview-label {
  font-family: var(--f-hud);
  font-size: 8px; letter-spacing: 1.5px;
  color: var(--text3); text-transform: uppercase;
  min-width: 90px;
}
.create-preview-value {
  font-family: var(--f-mono);
  font-size: 15px; font-weight: 600;
}
.create-preview-value.user { color: var(--cyan); text-shadow: 0 0 8px rgba(0,245,255,.3); }
.create-preview-value.pass { color: var(--green); }

/* ─── MONITOR CONNECTIONS ─── */
.conn-list { display: flex; flex-direction: column; gap: 6px; min-height: 80px; }
.conn-item {
  padding: 11px 14px;
  background: rgba(0,245,255,.04);
  border: 1px solid rgba(0,245,255,.1);
  border-radius: 2px;
  font-family: var(--f-mono); font-size: 12px;
  color: var(--cyan); display: flex; align-items: center; gap: 10px;
  animation: connIn .2s ease;
}
@keyframes connIn { from{opacity:0;transform:translateX(-6px)} to{opacity:1;transform:none} }
.conn-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--green); box-shadow: 0 0 8px var(--green); flex-shrink: 0; animation: pulseAnim 2s infinite; }

/* ─── PAYMENTS ─── */
.pay-summary { display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 18px; }
.pay-card { padding: 18px; text-align: center; }
.pay-label { font-family: var(--f-hud); font-size: 8px; letter-spacing: 2px; text-transform: uppercase; color: var(--text3); margin-bottom: 8px; }
.pay-val { font-family: var(--f-hud); font-size: 24px; font-weight: 700; }
.pay-badge {
  display: inline-flex; align-items: center;
  padding: 3px 9px; border-radius: 2px;
  font-family: var(--f-mono); font-size: 10px;
}
.pay-badge.transfer { background: rgba(0,200,255,.1); color: var(--cyan2); border: 1px solid rgba(0,200,255,.25); }
.pay-badge.cash { background: rgba(0,255,136,.1); color: var(--green); border: 1px solid rgba(0,255,136,.25); }

/* ─── ADMIN CARDS ─── */
.admin-card {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 16px; border-radius: 4px;
  background: rgba(0,245,255,.04);
  border: 1px solid var(--border);
  transition: all var(--tr);
  margin-bottom: 8px;
  flex-wrap: wrap;
}
.admin-card:hover {
  background: rgba(0,245,255,.08);
  border-color: var(--border2);
}
.admin-avatar {
  width: 38px; height: 38px; border-radius: 4px;
  background: linear-gradient(135deg, rgba(255,0,127,.2), rgba(0,245,255,.15));
  border: 1px solid var(--border2);
  display: grid; place-items: center;
  font-family: var(--f-hud); font-size: 12px; font-weight: 800;
  color: var(--magenta); flex-shrink: 0;
  text-shadow: 0 0 8px rgba(255,0,127,.4);
}
.admin-info { flex: 1; min-width: 0; }
.admin-name {
  font-family: var(--f-hud); font-size: 11px;
  font-weight: 700; color: #fff; letter-spacing: 1px;
}
.admin-detail {
  font-family: var(--f-mono); font-size: 10px;
  color: var(--text3); margin-top: 3px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.admin-role-tag {
  display: none;
}
.admin-role-tag.super-tag {
  background: rgba(255,0,127,.1); color: var(--magenta);
  border: 1px solid rgba(255,0,127,.25);
}
.admin-actions { display: flex; gap: 6px; width: 100%; justify-content: flex-end; }

/* ─── EMPTY / SPINNER ─── */
.empty-state {
  display: flex; align-items: center; justify-content: center;
  min-height: 80px; padding: 30px;
  font-family: var(--f-mono); font-size: 11px; color: var(--text3);
}
.spin {
  display: inline-block; width: 14px; height: 14px;
  border: 1.5px solid rgba(0,245,255,.15);
  border-top-color: var(--cyan);
  border-radius: 50%;
  animation: spinAnim .6s linear infinite;
}
@keyframes spinAnim { to{transform:rotate(360deg)} }

/* ─── TOASTS ─── */
#toasts {
  position: fixed; bottom: 16px; left: 10px; right: 10px;
  z-index: 9999; display: flex; flex-direction: column; gap: 8px;
  pointer-events: none;
}
.toast {
  padding: 11px 16px;
  background: rgba(4,5,13,.97);
  border: 1px solid var(--border2);
  border-left: 3px solid var(--cyan);
  font-family: var(--f-mono); font-size: 11px;
  color: var(--text2); max-width: 100%;
  box-shadow: 0 4px 24px rgba(0,0,0,.6), var(--glow-c);
  animation: toastIn .25s ease;
  pointer-events: auto; border-radius: 2px;
}
.toast.err { border-left-color: var(--red); color: #ffa0b0; box-shadow: 0 4px 24px rgba(0,0,0,.6),var(--glow-m); }
.toast.ok  { border-left-color: var(--green); color: #a0ffcc; }
@keyframes toastIn  { from{opacity:0;transform:translateX(12px)} to{opacity:1;transform:none} }
@keyframes toastOut { to{opacity:0;transform:translateX(12px)} }
.toast-out { animation: toastOut .3s ease forwards; }

/* ─── SEARCHABLE SELECT ─── */
.ss-wrap { position: relative; width: 100%; }
.ss-trigger {
  display: flex; align-items: center; gap: 9px;
  background: rgba(0,0,0,.5); border: 1px solid var(--border2);
  border-radius: 2px; padding: 11px 13px;
  font-family: var(--f-mono); font-size: 13px;
  color: var(--text); cursor: pointer;
  transition: border-color var(--tr);
}
.ss-trigger > svg { flex-shrink: 0; }
.ss-trigger:hover { border-color: var(--border3); }
.ss-trigger.open { border-color: var(--cyan); box-shadow: 0 0 0 2px rgba(0,245,255,.1); border-radius: 2px 2px 0 0; }
.ss-placeholder { color: var(--text3); }
.ss-arrow { margin-left: auto; transition: transform .2s; }
.ss-trigger.open .ss-arrow { transform: rotate(180deg); }
.ss-drop {
  display: none; position: absolute; top: 100%; left: 0; right: 0;
  z-index: 50; background: rgba(4,5,20,.98);
  border: 1px solid var(--cyan);
  border-radius: 0 0 4px 4px; max-height: 50vh; overflow: hidden;
  flex-direction: column; box-shadow: 0 12px 40px rgba(0,0,0,.7), var(--glow-c);
  animation: dropIn .15s ease;
}
.ss-drop.show { display: flex; }
@keyframes dropIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:none} }
.ss-search-wrap { padding: 8px; border-bottom: 1px solid var(--border); position: relative; }
.ss-search {
  width: 100%; background: rgba(0,245,255,.05); border: 1px solid var(--border2);
  border-radius: 2px; padding: 8px 12px 8px 34px;
  font-family: var(--f-mono); font-size: 12px; color: var(--text); outline: none;
}
.ss-search::placeholder { color: var(--text3); }
.ss-search:focus { border-color: var(--cyan); }
.ss-search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text3); pointer-events: none; }
.ss-options { overflow-y: auto; max-height: 190px; padding: 4px; }
.ss-options::-webkit-scrollbar { width: 3px; }
.ss-options::-webkit-scrollbar-thumb { background: var(--border2); }
.ss-opt {
  padding: 9px 11px; cursor: pointer;
  font-family: var(--f-mono); font-size: 12px; color: var(--text2);
  transition: all .12s; display: flex; align-items: center; gap: 8px; border-radius: 2px;
}
.ss-opt:hover { background: rgba(0,245,255,.07); color: var(--cyan); }
.ss-opt.selected { background: rgba(0,245,255,.1); color: var(--cyan); }
.ss-check { width: 14px; height: 14px; opacity: 0; color: var(--cyan); }
.ss-opt.selected .ss-check { opacity: 1; }
.ss-empty { padding: 14px; text-align: center; font-family: var(--f-mono); font-size: 11px; color: var(--text3); }

/* ─── MODAL ─── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.8); z-index: 1000;
  backdrop-filter: blur(8px);
  align-items: center; justify-content: center; padding: 16px;
}
.modal-overlay.show { display: flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.modal {
  background: rgba(4,5,20,.98);
  border: 1px solid rgba(0,245,255,.35);
  border-radius: 4px; width: 100%; max-width: 460px;
  max-height: 90vh; overflow-y: auto;
  box-shadow: 0 0 60px rgba(0,245,255,.15), 0 20px 60px rgba(0,0,0,.8);
  animation: modalUp .25s cubic-bezier(.4,0,.2,1);
  position: relative;
}
.modal::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, var(--cyan), var(--magenta), var(--cyan));
  animation: scanSlide 3s linear infinite; background-size: 200%;
}
@keyframes scanSlide { to{ background-position: 200%; } }
@keyframes modalUp { from{opacity:0;transform:translateY(20px) scale(.97)} to{opacity:1;transform:none} }
.modal-header {
  padding: 20px 22px 16px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.modal-title {
  font-family: var(--f-hud); font-size: 13px;
  color: #fff; letter-spacing: 1.5px; text-transform: uppercase;
  display: flex; align-items: center; gap: 9px;
}
.modal-title-icon { color: var(--cyan); }
.modal-close {
  width: 30px; height: 30px; border-radius: 2px;
  border: 1px solid var(--border2); background: transparent;
  cursor: pointer; color: var(--text2); display: grid; place-items: center;
  transition: all var(--tr); font-family: var(--f-mono); font-size: 14px;
}
.modal-close:hover { background: rgba(255,34,68,.15); border-color: var(--red); color: var(--red); }
.modal-body { padding: 18px 22px; }

/* VPS PROFILES */
.vps-profiles { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; max-height: 190px; overflow-y: auto; }
.vps-profile {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 13px; border-radius: 2px;
  border: 1px solid var(--border); background: rgba(0,245,255,.03);
  cursor: pointer; transition: all var(--tr);
}
.vps-profile:hover { border-color: rgba(0,245,255,.4); background: rgba(0,245,255,.06); }
.vps-profile.active-vps { border-color: rgba(0,255,136,.4); background: rgba(0,255,136,.05); }
.vps-profile-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--text3); flex-shrink: 0; }
.vps-profile.active-vps .vps-profile-dot { background: var(--green); box-shadow: 0 0 6px var(--green); animation: pulseAnim 2s infinite; }
.vps-profile-info { flex: 1; min-width: 0; }
.vps-profile-name { font-family: var(--f-hud); font-size: 11px; color: #fff; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vps-profile-url { font-family: var(--f-mono); font-size: 10px; color: var(--text3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vps-profile-del { width: 24px; height: 24px; border: 1px solid rgba(255,34,68,.2); background: transparent; color: rgba(255,34,68,.5); border-radius: 2px; cursor: pointer; display: grid; place-items: center; flex-shrink: 0; transition: all var(--tr); font-size: 11px; }
.vps-profile-del:hover { background: rgba(255,34,68,.15); color: var(--red); }
.vps-add-title { font-family: var(--f-hud); font-size: 8px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--text3); margin-bottom: 12px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }

/* ─── HUD DECORATIONS ─── */
.hud-tag {
  font-family: var(--f-mono); font-size: 9px; color: var(--text3);
  letter-spacing: 1px; display: flex; align-items: center; gap: 6px;
}
.hud-tag::before { content: '//'; color: var(--cyan); opacity: .5; }

/* ─── LOGIN PAGE ─── */
.login-wrap {
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 20px; position: relative; z-index: 2;
}
.login-box {
  width: 100%; max-width: 420px;
  background: rgba(4,5,13,.95);
  border: 1px solid var(--border2);
  border-radius: 4px; padding: 28px 20px;
  box-shadow: 0 0 60px rgba(0,245,255,.1), 0 20px 60px rgba(0,0,0,.8);
  position: relative; overflow: hidden;
}
.login-box::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, transparent, var(--cyan), var(--magenta), var(--cyan), transparent);
}
.login-logo {
  width: 64px; height: 64px; margin: 0 auto 24px;
  clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%);
  background: linear-gradient(135deg, rgba(0,245,255,.2), rgba(0,245,255,.05));
  border: 1px solid rgba(0,245,255,.4);
  display: grid; place-items: center;
  box-shadow: 0 0 30px rgba(0,245,255,.3);
  position: relative; overflow: hidden;
}
.login-logo img {
  width: 44px; height: 44px; object-fit: contain;
}
.login-logo-text {
  font-family: var(--f-hud); font-size: 18px; font-weight: 800;
  color: var(--cyan); text-shadow: 0 0 12px var(--cyan);
}
.login-title {
  font-family: var(--f-hud);
  font-size: 22px; font-weight: 800;
  color: #fff; text-align: center;
  letter-spacing: 4px; margin-bottom: 4px;
}
.login-title span { color: var(--cyan); }
.login-subtitle {
  font-family: var(--f-mono);
  font-size: 10px; color: var(--text3);
  text-align: center; margin-bottom: 32px;
  letter-spacing: 2px;
}
.login-btn {
  width: 100%; padding: 14px;
  font-family: var(--f-hud); font-size: 12px;
  font-weight: 700; letter-spacing: 2px;
  text-transform: uppercase; cursor: pointer;
  border-radius: 2px; border: 1px solid rgba(0,245,255,.5);
  background: rgba(0,245,255,.1); color: var(--cyan);
  transition: all var(--tr); display: flex;
  align-items: center; justify-content: center; gap: 8px;
}
.login-btn:hover {
  background: rgba(0,245,255,.2);
  box-shadow: var(--glow-c); transform: translateY(-1px);
}
.login-corner {
  position: absolute; width: 20px; height: 20px;
  border-color: var(--cyan); border-style: solid; opacity: .4;
}
.login-corner.tl { top: 8px; left: 8px; border-width: 2px 0 0 2px; }
.login-corner.tr { top: 8px; right: 8px; border-width: 2px 2px 0 0; }
.login-corner.bl { bottom: 8px; left: 8px; border-width: 0 0 2px 2px; }
.login-corner.br { bottom: 8px; right: 8px; border-width: 0 2px 2px 0; }

/* SIDEBAR OVERLAY */
.sidebar-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.6); z-index: 199;
  -webkit-tap-highlight-color: transparent;
  touch-action: manipulation;
}
.sidebar-overlay.show { display: block; }

/* ─── RESPONSIVE ─── */
/* Mobile-first: los estilos base son para móvil (<768px) */

/* ════════════════════════════════════════════
   TABLET — 768px+
   Sidebar sigue siendo overlay pero más espacioso
   ════════════════════════════════════════════ */
@media (min-width: 768px) {
  :root { --sidebar-w: 280px; }

  body { font-size: 15px; }

  .sidebar {
    width: 280px;
  }

  .topbar {
    height: 56px;
    padding: 0 20px;
  }
  .topbar-title { font-size: 13px; }

  .vps-pill { max-width: 150px; }
  .user-pill-role { display: inline-flex; padding: 2px 7px; border-radius: 2px; font-family: var(--f-mono); font-size: 9px; margin-left: 4px; border: 1px solid var(--border); }

  .content { padding: 18px 16px 30px; }

  .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
  .stat-value { font-size: 26px; }

  .card-header { padding: 16px 20px; }
  .card-body { padding: 18px 16px; }

  .form-grid.cols3 { grid-template-columns: 1fr 1fr 1fr; }

  .pay-summary { grid-template-columns: 1fr 1fr 1fr; }

  .login-box { padding: 36px 32px; max-width: 440px; }
  .login-title { font-size: 26px; }

  table { min-width: 520px; }

  .modal { max-width: 500px; }
  .modal-body { padding: 20px 26px; }

  .btn-hud { padding: 11px 22px; }

  .admin-card { flex-wrap: nowrap; }
  .admin-actions { width: auto; }
  .admin-role-tag { display: inline-flex; padding: 2px 8px; border-radius: 2px; font-family: var(--f-mono); font-size: 9px; }
}

/* ════════════════════════════════════════════
   DESKTOP / LAPTOP — 1025px+
   Sidebar siempre visible, layout completo
   ════════════════════════════════════════════ */
@media (min-width: 1025px) {
  :root { --sidebar-w: 265px; }

  body { font-size: 14px; }

  /* SIDEBAR: siempre visible */
  .sidebar {
    position: fixed;
    left: 0; top: 0; bottom: 0;
    transform: translateX(0) !important;
    width: 265px;
    z-index: 50;
  }
  .sidebar.open { transform: translateX(0) !important; }

  /* Overlay oculto en desktop */
  .sidebar-overlay { display: none !important; }

  /* Botón menú oculto en desktop */
  .btn-mobile { display: none !important; }

  /* Layout con margen para sidebar fijo */
  .layout {
    margin-left: 265px;
  }

  .topbar {
    height: 58px;
    padding: 0 28px;
  }
  .topbar-title { font-size: 14px; letter-spacing: 2px; }
  .topbar-time { display: block; font-family: var(--f-mono); font-size: 11px; color: var(--text2); }

  .content { padding: 22px 28px 30px; }

  .stats-grid {
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
  }
  .stat-value { font-size: 28px; }
  .stat-label { font-size: 9px; }

  .card-header { padding: 16px 24px; }
  .card-body { padding: 20px 24px; }

  .form-grid.cols3 { grid-template-columns: 1fr 1fr 1fr; }

  .pay-summary { grid-template-columns: 1fr 1fr 1fr; }

  .vps-pill { max-width: 180px; }

  table { min-width: 600px; }
  th { padding: 12px 18px; }
  td { padding: 14px 18px; }

  .modal { max-width: 520px; }

  .btn-hud { padding: 11px 24px; font-size: 10px; }

  .login-box { padding: 40px 36px; max-width: 460px; }
  .login-title { font-size: 28px; }

  .admin-card { flex-wrap: nowrap; }
  .admin-actions { width: auto; }
}

/* ════════════════════════════════════════════
   WIDE DESKTOP — 1440px+
   Más espacio, tipografía mayor, HUD decoraciones amplias
   ════════════════════════════════════════════ */
@media (min-width: 1440px) {
  :root { --sidebar-w: 280px; }

  .sidebar { width: 280px; }
  .layout { margin-left: 280px; }

  body { font-size: 15px; }

  .topbar { height: 60px; padding: 0 36px; }
  .topbar-title { font-size: 15px; }

  .content { padding: 26px 36px 30px; max-width: 1400px; }

  .stats-grid { gap: 16px; }
  .stat-value { font-size: 32px; }
  .stat-card { padding: 20px; }

  .card-header { padding: 18px 28px; }
  .card-body { padding: 22px 28px; }
  .card-title { font-size: 12px; }

  .nav-item { padding: 13px 22px; font-size: 14px; }

  .brand-text h2 { font-size: 16px; }

  table { min-width: 700px; }

  .modal { max-width: 560px; }

  .login-box { max-width: 480px; padding: 44px 40px; }
}

/* ─── ORIENTATION & SPECIAL CASES ─── */
@media (max-width: 767px) and (orientation: landscape) {
  .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 8px; }
  .stat-value { font-size: 18px; }
  .stat-card { padding: 10px; }
  .stat-label { font-size: 7px; margin-bottom: 6px; }
  .topbar { height: 46px; }
  .content { padding: 10px 10px 20px; }
}

@media (min-width: 768px) and (max-width: 1024px) and (orientation: landscape) {
  .stats-grid { grid-template-columns: repeat(4, 1fr); }
}

/* ─── PRINT (ocultar sidebar, mostrar solo contenido) ─── */
@media print {
  .sidebar, .sidebar-overlay, .topbar, .hud-bg, #toasts { display: none !important; }
  .layout { margin-left: 0; }
  .content { padding: 20px; }
  body { background: #fff; color: #000; }
}
</style>
</head>
<body class="<?= $page === 'login' ? 'login-page' : '' ?>">

<!-- HUD BACKGROUND -->
<div class="hud-bg">
  <div class="hud-grid"></div>
  <div class="hud-scan"></div>
  <div class="hud-corner tl"></div>
  <div class="hud-corner tr"></div>
  <div class="hud-corner bl"></div>
  <div class="hud-corner br"></div>
</div>

<?php if ($page === 'login'): ?>
<!-- ══════════════════════════════════════════════════════
     LOGIN
══════════════════════════════════════════════════════ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-corner tl"></div>
    <div class="login-corner tr"></div>
    <div class="login-corner bl"></div>
    <div class="login-corner br"></div>

    <div class="login-logo">
      <?php if (file_exists(__DIR__ . '/logo.png')): ?>
        <img src="logo.png" alt="GT">
      <?php else: ?>
        <span class="login-logo-text">GT</span>
      <?php endif; ?>
    </div>
    <h1 class="login-title">GEMIN<span>TECH</span></h1>
    <p class="login-subtitle">// CONTROL HUD v2.0 — ACCESO SEGURO</p>

    <form id="loginForm" onsubmit="return false">
      <div class="field" style="margin-bottom:14px">
        <label>Identificador</label>
        <input type="text" id="loginUser" placeholder="superadmin" autocomplete="username" required>
      </div>
      <div class="field" style="margin-bottom:22px">
        <label>Clave de Acceso</label>
        <input type="password" id="loginPass" placeholder="••••••••" autocomplete="current-password" required>
      </div>
      <button type="submit" class="login-btn" id="loginBtn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        INICIAR SESION
      </button>
    </form>
  </div>
</div>
<div id="toasts"></div>

<script>
const toast = (msg, type='ok') => {
  const el = document.createElement('div');
  el.className = 'toast ' + type; el.textContent = msg;
  document.getElementById('toasts').appendChild(el);
  setTimeout(() => { el.classList.add('toast-out'); el.addEventListener('animationend', () => el.remove(), {once:true}); }, 2500);
};

document.getElementById('loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  const username = document.getElementById('loginUser').value.trim();
  const password = document.getElementById('loginPass').value;
  if (!username || !password) { toast('Ingresa usuario y contraseña','err'); return; }
  const btn = document.getElementById('loginBtn');
  btn.innerHTML = "<span class='spin'></span>&nbsp;VERIFICANDO..."; btn.disabled = true;
  try {
    const res = await fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({action:'login', username, password})});
    const data = await res.json();
    if (data.ok) { toast('// ACCESO CONCEDIDO'); setTimeout(() => window.location.href='?page=panel', 500); }
    else { toast(data.error || 'Error al iniciar sesión','err'); btn.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg> INICIAR SESION'; btn.disabled=false; }
  } catch(err) { toast('Error de conexión','err'); btn.innerHTML='INICIAR SESION'; btn.disabled=false; }
});
toast('// Default: superadmin / admin123');
</script>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════
     PANEL
══════════════════════════════════════════════════════ -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR — fuera de .layout para evitar stacking context bug -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-accent"></div>

    <div class="sidebar-header">
      <div class="brand-logo">
        <div class="brand-hex">
          <?php if (file_exists(__DIR__ . '/logo.png')): ?>
            <img src="logo.png" alt="GT">
          <?php else: ?>
            <span>GT</span>
          <?php endif; ?>
        </div>
        <div class="brand-text">
          <h2>GEMIN<span>TECH</span></h2>
          <div class="brand-sub <?= is_super() ? 'super' : '' ?>"><?= is_super() ? '// SUPER ADMIN' : '// ADMIN PANEL' ?></div>
        </div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">// Principal</div>

      <div class="nav-item active" data-section="dashboard">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="4" rx="1"/>
          <rect x="14" y="10" width="7" height="11" rx="1"/><rect x="3" y="13" width="7" height="8" rx="1"/>
        </svg>
        Dashboard
      </div>

      <div class="nav-item" data-section="users">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
        </svg>
        Usuarios
        <span class="nav-badge" id="navUserCount">0</span>
      </div>

      <div class="nav-item" data-section="create">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>
        </svg>
        Crear Usuario
      </div>

      <div class="nav-item" data-section="manage">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
        </svg>
        Gestionar
      </div>

      <div class="nav-item" data-section="monitor">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
        </svg>
        Monitor
      </div>

      <div class="nav-section-label">// Finanzas</div>
      <div class="nav-item" data-section="payments">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/>
        </svg>
        Pagos
      </div>

      <?php if (is_super()): ?>
      <div class="nav-section-label">// Super Admin</div>
      <div class="nav-item" data-section="admins">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 00-3-3.87m-4-12a4 4 0 010 7.75"/>
        </svg>
        Administradores
      </div>
      <?php endif; ?>

      <?php if (is_super()): ?>
      <div class="nav-section-label">// Config</div>
      <div class="nav-item" data-section="vpnconfig">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        VPN Config
      </div>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="pulse-dot" id="statusDot"></div>
      <span class="sidebar-status" id="statusText">// CONECTANDO...</span>
    </div>
  </aside>

<div class="layout">
  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <button class="btn-mobile" id="btnMenu">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 12h18M3 6h18M3 18h18"/>
          </svg>
        </button>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span class="topbar-title" id="topTitle">DASHBOARD</span>
          <div class="hud-tag" id="topSub">panel principal</div>
        </div>
      </div>

      <div class="topbar-right">
        <span class="topbar-time" id="topTime"></span>

        <div class="vps-pill" id="vpsPill" onclick="openVpsModal()">
          <div class="vps-pill-dot" id="vpsPillDot"></div>
          <span class="vps-pill-text" id="vpsPillText">VPS</span>
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>

        <div class="user-pill">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span><?= htmlspecialchars(current_user()['username']) ?></span>
          <span class="user-pill-role <?= is_super() ? 'super' : '' ?>"><?= strtoupper(current_user()['role']) ?></span>
        </div>

        <button class="btn-hud btn-red" style="padding:7px 13px;font-size:9px" onclick="logout()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          SALIR
        </button>
      </div>
    </div>

    <div class="content">

      <!-- ── DASHBOARD ── -->
      <div class="panel active" id="sec-dashboard">
        <div class="stats-grid">
          <div class="card stat-card" id="sc1">
            <div class="stat-label">Usuarios Totales</div>
            <div class="stat-value sv-c" id="sv1">—</div>
            <div class="stat-sub">USUARIOS SSH</div>
          </div>
          <div class="card stat-card" id="sc2">
            <div class="stat-label">API Status</div>
            <div class="stat-value sv-m" id="sv2">—</div>
            <div class="stat-sub">BACKEND VPS</div>
          </div>
          <div class="card stat-card" id="sc3">
            <div class="stat-label">Ultima Sync</div>
            <div class="stat-value sv-v" style="font-size:20px" id="sv3">—</div>
            <div class="stat-sub">TIMESTAMP</div>
          </div>
          <div class="card stat-card" id="sc4">
            <div class="stat-label">Total Pagos</div>
            <div class="stat-value sv-g" id="sv4">$0</div>
            <div class="stat-sub">RECAUDADO</div>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> ACTIVIDAD RECIENTE — PAGOS</span>
          </div>
          <div class="card-body" id="recentPayments">
            <div class="empty-state">// SIN PAGOS REGISTRADOS</div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> USUARIOS REGISTRADOS</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>#</th><th>Usuario</th><th>Expiracion</th><th>Acciones</th></tr></thead>
              <tbody id="tbodyDash"><tr><td colspan="4"><div class="empty-state"><span class="spin"></span></div></td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── USERS ── -->
      <div class="panel" id="sec-users">
        <div class="card">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> LISTA COMPLETA DE USUARIOS SSH</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th style="width:48px">#</th><th>Usuario</th><th>Expiracion</th><th>Acciones</th></tr></thead>
              <tbody id="tbody"><tr><td colspan="4"><div class="empty-state"><span class="spin"></span></div></td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── CREATE (LOGICA TELEFONO) ── -->
      <div class="panel" id="sec-create">
        <div class="card">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> CREAR NUEVO USUARIO</span>
          </div>
          <div class="card-body">
            <p style="font-family:var(--f-mono);font-size:11px;color:var(--text2);margin-bottom:16px">// Ingresa el numero de telefono. El usuario se crea con prefijo <span style="color:var(--cyan);font-weight:bold">E</span> y la contrasena es el mismo numero sin la E.</p>
            <div class="form-grid">
              <div class="field">
                <label>Numero de Telefono</label>
                <input id="cu_phone" type="tel" placeholder="0999102920" autocomplete="off" spellcheck="false"
                  oninput="updateCreatePreview()">
              </div>
              <div class="field">
                <label>Duracion de Acceso</label>
                <select id="cd" onchange="document.getElementById('demo-min-wrap').style.display=this.value==='0'?'flex':'none';updateCreatePreview()">
                  <option value="0">Demo (minutos)</option>
                  <option value="1">1 dia</option>
                  <option value="3">3 dias</option>
                  <option value="7" selected>7 dias</option>
                  <option value="15">15 dias</option>
                  <option value="30">30 dias</option>
                  <option value="60">60 dias</option>
                  <option value="90">90 dias</option>
                </select>
              </div>
              <div class="field" id="demo-min-wrap" style="display:none">
                <label>Minutos Demo</label>
                <input id="cdm" type="number" min="1" max="60" value="1" placeholder="1">
              </div>
              <div class="field field-end">
                <label>&nbsp;</label>
                <button class="btn-hud btn-cyan" id="btn-crear">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>
                  CREAR USUARIO
                </button>
              </div>
            </div>

            <!-- PREVIEW -->
            <div class="create-preview" id="createPreview">
              <div class="create-preview-title">// VISTA PREVIA — Datos de acceso</div>
              <div class="create-preview-row">
                <div class="create-preview-label">Usuario</div>
                <div class="create-preview-value user" id="previewUser">—</div>
              </div>
              <div class="create-preview-row">
                <div class="create-preview-label">Contrasena</div>
                <div class="create-preview-value pass" id="previewPass">—</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── MANAGE ── -->
      <div class="panel" id="sec-manage">
        <div class="card">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> GESTIONAR USUARIO</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field full">
                <label>Seleccionar Usuario</label>
                <div class="ss-wrap" id="ss-manage">
                  <div class="ss-trigger" id="ss-manage-trigger">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <span class="ss-placeholder" id="ss-manage-text">Buscar usuario...</span>
                    <svg class="ss-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                  </div>
                  <div class="ss-drop" id="ss-manage-dropdown">
                    <div class="ss-search-wrap">
                      <svg class="ss-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                      <input class="ss-search" id="ss-manage-search" type="text" placeholder="Escriba para buscar..." autocomplete="off">
                    </div>
                    <div class="ss-options" id="ss-manage-options"></div>
                  </div>
                  <input type="hidden" id="mu">
                </div>
              </div>

              <div class="form-divider"></div>
              <div class="form-label danger">// ZONA DE PELIGRO</div>
              <div class="full">
                <button class="btn-hud btn-red" id="btn-del" style="width:100%;justify-content:center">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                  ELIMINAR USUARIO PERMANENTEMENTE
                </button>
              </div>

              <div class="form-divider"></div>
              <div class="form-label">// RENOVAR ACCESO</div>
              <div class="field">
                <label>Nuevos Dias</label>
                <select id="md">
                  <option value="1">1 dia</option><option value="3">3 dias</option>
                  <option value="7" selected>7 dias</option><option value="15">15 dias</option>
                  <option value="30">30 dias</option>
                </select>
              </div>
              <div class="field field-end">
                <label>&nbsp;</label>
                <button class="btn-hud btn-cyan" id="btn-ren">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                  RENOVAR ACCESO
                </button>
              </div>


            </div>
          </div>
        </div>
      </div>

      <!-- ── MONITOR ── -->
      <div class="panel" id="sec-monitor">
        <div class="card">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">◉</span> CONEXIONES ACTIVAS</span>
            <button class="btn-hud btn-cyan" id="btn-ref" style="padding:7px 13px;font-size:9px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
              REFRESH
            </button>
          </div>
          <div class="card-body">
            <div class="conn-list" id="connlist">
              <div class="empty-state"><span class="spin"></span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── PAYMENTS ── -->
      <div class="panel" id="sec-payments">
        <div class="pay-summary">
          <div class="card pay-card">
            <div class="pay-label">Total Recaudado</div>
            <div class="pay-val sv-g" id="payTotal">$0.00</div>
          </div>
          <div class="card pay-card">
            <div class="pay-label">Transferencias</div>
            <div class="pay-val sv-c" id="payTransfer">$0.00</div>
          </div>
          <div class="card pay-card">
            <div class="pay-label">Efectivo</div>
            <div class="pay-val sv-m" id="payCash">$0.00</div>
          </div>
        </div>

        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> REGISTRAR PAGO</span>
          </div>
          <div class="card-body">
            <div class="form-grid cols3">
              <div class="field">
                <label>Usuario</label>
                <div class="ss-wrap" id="ss-pay">
                  <div class="ss-trigger" id="ss-pay-trigger">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <span class="ss-placeholder" id="ss-pay-text">Buscar usuario...</span>
                    <svg class="ss-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                  </div>
                  <div class="ss-drop" id="ss-pay-dropdown">
                    <div class="ss-search-wrap">
                      <svg class="ss-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                      <input class="ss-search" id="ss-pay-search" type="text" placeholder="Buscar..." autocomplete="off">
                    </div>
                    <div class="ss-options" id="ss-pay-options"></div>
                  </div>
                  <input type="hidden" id="payUser">
                </div>
              </div>
              <div class="field"><label>Monto ($)</label><input id="payAmount" type="number" step="0.01" min="0" placeholder="0.00"></div>
              <div class="field"><label>Tipo de Pago</label><select id="payType"><option value="transfer">Transferencia</option><option value="cash">Efectivo</option></select></div>
              <div class="field full"><label>Nota (opcional)</label><textarea id="payNote" rows="2" placeholder="Detalle del pago..."></textarea></div>
              <div class="full" style="display:flex;justify-content:flex-end">
                <button class="btn-hud btn-green" id="btn-pay">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                  REGISTRAR PAGO
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> HISTORIAL DE PAGOS</span>
            <button class="btn-hud btn-red" id="btn-clearPay" style="padding:6px 12px;font-size:9px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
              LIMPIAR TODO
            </button>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Fecha</th><th>Usuario</th><th>Monto</th><th>Tipo</th><th>Nota</th><th>Admin</th><th>—</th></tr></thead>
              <tbody id="payTable"><tr><td colspan="7"><div class="empty-state">// SIN PAGOS REGISTRADOS</div></td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── VPN CONFIG (SUPER ADMIN ONLY) ── -->
      <?php if (is_super()): ?>
      <div class="panel" id="sec-vpnconfig">
        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> CONFIGURACION VPN — FIREBASE</span>
            <span id="vpnStatus"><span class="spin"></span></span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field"><label>VPS Host</label><input id="vpn_host" type="text" placeholder="api.google.com"></div>
              <div class="field"><label>VPS Port</label><input id="vpn_port" type="number" placeholder="443"></div>
              <div class="field full"><label>SNI Host</label><input id="vpn_sni" type="text" placeholder="config.miclaro.smapps.mx"><small style="color:var(--text3);font-size:10px;margin-top:4px;display:block">// Server Name Indication para el bypass</small></div>
              <div class="field full"><label>Payload</label><textarea id="vpn_payload" rows="4" placeholder="GET / HTTP/1.1[crlf]Host: [host][crlf][crlf]"></textarea><small style="color:var(--text3);font-size:10px;margin-top:4px;display:block">// Usa [crlf] para saltos de linea</small></div>
              <div class="field"><label>SOCKS Port</label><input id="vpn_socks" type="number" placeholder="1080"></div>
              <div class="field"><label>UDPGW Port</label><input id="vpn_udpgw" type="number" placeholder="7300"></div>
              <div class="full" style="display:flex;gap:10px;justify-content:flex-end;padding-top:6px">
                <button class="btn-hud btn-cyan" id="btn-vpn-reload" style="padding:9px 16px">RECARGAR</button>
                <button class="btn-hud btn-green" id="btn-vpn-save" style="padding:9px 16px">GUARDAR Y APLICAR</button>
              </div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><span class="ct-accent">ℹ</span> INFORMACION</span></div>
          <div class="card-body" style="font-family:var(--f-mono);font-size:12px;color:var(--text2);line-height:1.8">
            <p>// Los cambios se aplican <span style="color:var(--green)">automaticamente</span> en la app GeminTech VPN al guardar.</p>
            <p style="margin-top:6px">// Base de datos: <span style="color:var(--cyan)">Firebase Realtime Database</span></p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── ADMINS (SUPER ONLY) ── -->
      <?php if (is_super()): ?>
      <div class="panel" id="sec-admins">
        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> CREAR NUEVO ADMINISTRADOR</span>
            <span class="admin-role-tag super-tag">SUPER ADMIN ONLY</span>
          </div>
          <div class="card-body">
            <p style="font-family:var(--f-mono);font-size:11px;color:var(--text2);margin-bottom:16px">// Los administradores pueden vender cuentas SSH a usuarios.</p>
            <div class="form-grid">
              <div class="field"><label>Username</label><input id="adm_user" type="text" placeholder="admin_nuevo" autocomplete="off" spellcheck="false"></div>
              <div class="field"><label>Contrasena</label><input id="adm_pass" type="password" placeholder="contrasena segura"></div>
              <div class="field"><label>Email</label><input id="adm_email" type="email" placeholder="admin@ejemplo.com"></div>
              <div class="field"><label>VPS URL (opcional)</label><input id="adm_vps" type="text" placeholder="http://IP:9000" spellcheck="false"></div>
              <div class="field full"><label>VPS API Key (opcional)</label><input id="adm_vpskey" type="password" placeholder="api_key_del_vps"></div>
              <div class="full" style="display:flex;justify-content:flex-end;padding-top:4px">
                <button class="btn-hud btn-magenta" id="btn-adm-crear">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>
                  CREAR ADMIN
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title"><span class="ct-accent">▸</span> ADMINISTRADORES REGISTRADOS</span>
            <button class="btn-hud btn-cyan" style="padding:7px 13px;font-size:9px" onclick="loadAdmins()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
              RECARGAR
            </button>
          </div>
          <div class="card-body" id="adminsList">
            <div class="empty-state"><span class="spin"></span> Cargando...</div>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- .content -->
  </div><!-- .main -->
</div><!-- .layout -->

<div id="toasts"></div>

<!-- ── VPS MODAL ── -->
<div class="modal-overlay" id="vpsModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">
        <svg class="modal-title-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
        CONEXION VPS
      </span>
      <button class="modal-close" onclick="closeVpsModal()">✕</button>
    </div>
    <div class="modal-body">
      <div style="font-family:var(--f-hud);font-size:8px;letter-spacing:2.5px;text-transform:uppercase;color:var(--text3);margin-bottom:10px">// Servidores Guardados</div>
      <div class="vps-profiles" id="vpsProfileList"></div>
      <div class="vps-add-title">// AGREGAR / EDITAR CONEXION</div>
      <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:14px">
        <div class="field"><label>Nombre del Servidor</label><input id="vpsName" type="text" placeholder="Ej: VPS Ecuador Principal" autocomplete="off"></div>
        <div class="field"><label>URL de la API <span style="color:var(--text3);font-size:9px">(http://IP:9000)</span></label><input id="vpsUrl" type="text" placeholder="http://172.245.184.188:9000" autocomplete="off" spellcheck="false"></div>
        <div class="field"><label>API Key</label><input id="vpsKey" type="password" placeholder="mi_api_key_secreta"></div>
      </div>
      <button class="btn-hud btn-cyan" id="btnSaveVps" style="width:100%;justify-content:center">GUARDAR Y CONECTAR</button>
    </div>
  </div>
</div>

<script>
const $ = s => document.querySelector(s), $$ = s => document.querySelectorAll(s);
const API_BASE = '';

// ── TOASTS ──
function toast(msg, type='ok') {
  const el = document.createElement('div');
  el.className='toast '+type; el.textContent=msg;
  $('#toasts').appendChild(el);
  const dismiss=()=>{el.classList.add('toast-out');setTimeout(()=>{if(el.parentNode)el.remove();},400);};
  setTimeout(dismiss,1800);
}

// ── PHONE → USER/PASS LOGIC ──
function phoneToUser(phone) {
  const clean = phone.replace(/[^0-9]/g, '');
  return 'E' + clean;
}
function phoneToPass(phone) {
  return phone.replace(/[^0-9]/g, '');
}

function updateCreatePreview() {
  const phone = $('#cu_phone').value.trim();
  const preview = $('#createPreview');
  if (phone.length >= 8) {
    preview.classList.add('show');
    $('#previewUser').textContent = phoneToUser(phone);
    $('#previewPass').textContent = phoneToPass(phone);
  } else {
    preview.classList.remove('show');
  }
}

// ── VPS ──
const VPS_KEY='gemintech_vps_profiles', VPS_ACTIVE='gemintech_active_vps';
const vpsGetProfiles=()=>{try{return JSON.parse(localStorage.getItem(VPS_KEY)||'[]');}catch{return[];}};
const vpsSaveProfiles=arr=>localStorage.setItem(VPS_KEY,JSON.stringify(arr));
const vpsGetActive=()=>{try{return JSON.parse(localStorage.getItem(VPS_ACTIVE)||'null');}catch{return null;}};
const vpsSetActive=p=>{localStorage.setItem(VPS_ACTIVE,JSON.stringify(p));updateVpsPill(p);};

function updateVpsPill(p){
  const dot=$('#vpsPillDot'),txt=$('#vpsPillText');
  if(p&&p.url){txt.textContent=p.name||p.url.replace(/^https?:\/\//,'').split(':')[0];dot.classList.add('on');}
  else{txt.textContent='Config VPS';dot.classList.remove('on');}
}
function renderVpsProfiles(){
  const profiles=vpsGetProfiles(),active=vpsGetActive(),list=$('#vpsProfileList');
  if(!profiles.length){list.innerHTML='<div class="empty-state" style="min-height:50px">// SIN SERVIDORES — AGREGA UNO ABAJO</div>';return;}
  list.innerHTML=profiles.map((p,i)=>{
    const on=active&&active.url===p.url;
    return`<div class="vps-profile${on?' active-vps':''}" onclick="vpsConnect(${i})">
      <div class="vps-profile-dot"></div>
      <div class="vps-profile-info">
        <div class="vps-profile-name">${p.name||'VPS '+(i+1)}</div>
        <div class="vps-profile-url">${p.url}</div>
      </div>
      ${on?'<span style="font-family:var(--f-mono);font-size:9px;color:var(--green);white-space:nowrap">● ACTIVO</span>':''}
      <button class="vps-profile-del" onclick="vpsDelete(event,${i})" title="Eliminar">✕</button>
    </div>`;
  }).join('');
}
function vpsConnect(i){
  const p=vpsGetProfiles()[i];if(!p)return;
  vpsSetActive(p);renderVpsProfiles();closeVpsModal();
  toast('// Conectado: '+(p.name||p.url),'ok');
  loadStatus();loadUsers();
}
function vpsDelete(e,i){
  e.stopPropagation();
  const profiles=vpsGetProfiles(),active=vpsGetActive();
  if(active&&active.url===profiles[i].url){localStorage.removeItem(VPS_ACTIVE);updateVpsPill(null);}
  profiles.splice(i,1);vpsSaveProfiles(profiles);renderVpsProfiles();
  toast('// Servidor eliminado');
}
$('#btnSaveVps').addEventListener('click',()=>{
  const name=$('#vpsName').value.trim(),url=$('#vpsUrl').value.trim(),key=$('#vpsKey').value.trim();
  if(!url){toast('// Ingresa la URL de la API','err');return;}
  if(!key){toast('// Ingresa la API Key','err');return;}
  const cleanUrl=url.startsWith('http')?url:'http://'+url;
  const profiles=vpsGetProfiles();
  const idx=profiles.findIndex(p=>p.url===cleanUrl);
  const profile={name:name||cleanUrl,url:cleanUrl,key};
  idx>=0?profiles[idx]=profile:profiles.push(profile);
  vpsSaveProfiles(profiles);vpsSetActive(profile);renderVpsProfiles();
  $('#vpsName').value='';$('#vpsUrl').value='';$('#vpsKey').value='';
  closeVpsModal();
  toast('// Conectado: '+profile.name,'ok');
  loadStatus();loadUsers();
});
function openVpsModal(){renderVpsProfiles();const active=vpsGetActive();if(active&&!$('#vpsUrl').value){$('#vpsUrl').value=active.url;$('#vpsKey').value=active.key||'';}$('#vpsModal').classList.add('show');}
function closeVpsModal(){$('#vpsModal').classList.remove('show');}
$('#vpsModal').addEventListener('click',e=>{if(e.target===$('#vpsModal'))closeVpsModal();});

const _initVps=vpsGetActive();
if(_initVps)updateVpsPill(_initVps);
else{
  const def={name:'VPS Principal',url:'<?= rtrim(GeminConfig::$api_base, '/') ?>',key:'<?= GeminConfig::$api_key ?>'};
  vpsSaveProfiles([def]);vpsSetActive(def);
}

// ── API ENGINE ──
async function api(action,body={}){
  const p=new URLSearchParams();
  p.append('action',action);
  Object.entries(body).forEach(([k,v])=>p.append(k,v));
  const headers={'Content-Type':'application/x-www-form-urlencoded'};
  const active=vpsGetActive();
  if(active&&active.url){headers['x-vps-target']=active.url;if(active.key)headers['x-vps-key']=active.key;}
  try{const r=await fetch(API_BASE,{method:'POST',headers,body:p});return await r.json();}
  catch(e){return{error:e.message};}
}

// ── SEARCHABLE SELECT ──
class SearchableSelect {
  constructor(prefix){
    this.prefix=prefix;
    this.trigger=$(`#${prefix}-trigger`);
    this.dropdown=$(`#${prefix}-dropdown`);
    this.searchInput=$(`#${prefix}-search`);
    this.optionsEl=$(`#${prefix}-options`);
    this.textEl=$(`#${prefix}-text`);
    this.items=[];this.selected='';
    this.trigger.addEventListener('click',()=>this.toggle());
    this.searchInput.addEventListener('input',()=>this.filter());
    this.searchInput.addEventListener('keydown',e=>{
      if(e.key==='Escape')this.close();
      if(e.key==='Enter'){const f=this.optionsEl.querySelector('.ss-opt:not([style*="display: none"])');if(f)f.click();}
    });
    document.addEventListener('click',e=>{if(!e.target.closest(`#${prefix}`))this.close();});
  }
  toggle(){
    const open=this.dropdown.classList.contains('show');
    $$('.ss-drop').forEach(d=>d.classList.remove('show'));
    $$('.ss-trigger').forEach(t=>t.classList.remove('open'));
    if(!open){this.dropdown.classList.add('show');this.trigger.classList.add('open');this.searchInput.value='';this.filter();setTimeout(()=>this.searchInput.focus(),50);}
    else this.close();
  }
  close(){this.dropdown.classList.remove('show');this.trigger.classList.remove('open');}
  setItems(arr){this.items=arr;this.render();}
  render(){
    if(!this.items.length){this.optionsEl.innerHTML='<div class="ss-empty">// SIN USUARIOS</div>';return;}
    this.optionsEl.innerHTML=this.items.map(u=>`
      <div class="ss-opt${this.selected===u?' selected':''}" data-value="${u}">
        <svg class="ss-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        ${u}
      </div>`).join('');
    this.optionsEl.querySelectorAll('.ss-opt').forEach(o=>{o.addEventListener('click',()=>this.select(o.dataset.value));});
  }
  filter(){
    const q=this.searchInput.value.toLowerCase();let count=0;
    this.optionsEl.querySelectorAll('.ss-opt').forEach(o=>{
      const m=o.dataset.value.toLowerCase().includes(q);
      o.style.display=m?'':'none';if(m)count++;
    });
    let empty=this.optionsEl.querySelector('.ss-empty');
    if(count===0&&!empty){empty=document.createElement('div');empty.className='ss-empty';empty.textContent='// SIN RESULTADOS';this.optionsEl.appendChild(empty);}
    else if(count>0&&empty)empty.remove();
  }
  select(val){
    this.selected=val;
    this.textEl.textContent=val;this.textEl.classList.remove('ss-placeholder');
    const hidden=this.trigger.parentElement.querySelector('input[type="hidden"]');
    if(hidden)hidden.value=val;
    this.render();this.close();
  }
  getValue(){return this.selected;}
  reset(){this.selected='';this.textEl.textContent='Buscar usuario...';this.textEl.classList.add('ss-placeholder');const hidden=this.trigger.parentElement.querySelector('input[type="hidden"]');if(hidden)hidden.value='';}
}

const ssManage=new SearchableSelect('ss-manage');
const ssPay=new SearchableSelect('ss-pay');

// ── LOGOUT & MENU ──
async function logout(){if(!confirm('// ¿Cerrar sesion?'))return;await api('logout');window.location.href='?page=login';}

// ── RESPONSIVE HELPERS ──
function isDesktop(){return window.innerWidth>=1025;}
function isTablet(){return window.innerWidth>=768&&window.innerWidth<1025;}

function openSidebar(){
  if(isDesktop()) return; // En desktop siempre visible, no necesita abrirse
  $('#sidebar').classList.add('open');
  $('#sidebarOverlay').classList.add('show');
  document.body.style.overflow='hidden';
}
function closeSidebar(){
  if(isDesktop()) return; // En desktop nunca se cierra
  $('#sidebar').classList.remove('open');
  $('#sidebarOverlay').classList.remove('show');
  document.body.style.overflow='';
}
function toggleSidebar(){
  if(isDesktop()) return;
  if($('#sidebar').classList.contains('open')) closeSidebar();
  else openSidebar();
}

// Manejar resize: al pasar a desktop, limpiar estados mobile
function handleResize(){
  if(isDesktop()){
    $('#sidebar').classList.remove('open');
    $('#sidebarOverlay').classList.remove('show');
    document.body.style.overflow='';
  }
}
window.addEventListener('resize',handleResize);

$('#btnMenu').addEventListener('click',toggleSidebar);
$('#sidebarOverlay').addEventListener('click',closeSidebar);

// Cerrar sidebar con swipe izquierda (solo mobile/tablet)
let swipeStartX=0, swipeStartY=0;
document.addEventListener('touchstart',e=>{swipeStartX=e.touches[0].clientX;swipeStartY=e.touches[0].clientY;},{passive:true});
document.addEventListener('touchend',e=>{
  if(isDesktop()) return;
  const dx=e.changedTouches[0].clientX-swipeStartX;
  const dy=Math.abs(e.changedTouches[0].clientY-swipeStartY);
  if(dy>60) return; // fue scroll vertical, ignorar
  if(dx<-60&&$('#sidebar').classList.contains('open')) closeSidebar();
  if(dx>80&&!$('#sidebar').classList.contains('open')&&swipeStartX<30) openSidebar();
},{passive:true});

function updateClock(){$('#topTime').textContent=new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
setInterval(updateClock,1000);updateClock();

// ── NAVIGATION ──
const sectionTitles={dashboard:'DASHBOARD',users:'USUARIOS',create:'CREAR USUARIO',manage:'GESTIONAR',monitor:'MONITOR',payments:'PAGOS',vpnconfig:'VPN CONFIG',admins:'ADMINISTRADORES'};
const sectionSubs={dashboard:'panel principal',users:'lista de usuarios SSH',create:'nuevo acceso SSH',manage:'modificar usuario',monitor:'conexiones activas',payments:'finanzas y registros',vpnconfig:'configuracion Firebase',admins:'gestion de admins'};
$$('.nav-item').forEach(n=>{
  n.addEventListener('click',()=>{
    $$('.nav-item').forEach(x=>x.classList.remove('active'));n.classList.add('active');
    $$('.panel').forEach(x=>x.classList.remove('active'));
    const sec=n.dataset.section;$('#sec-'+sec).classList.add('active');
    $('#topTitle').textContent=sectionTitles[sec]||sec;
    $('#topSub').textContent=sectionSubs[sec]||'';
    if(sec==='monitor')loadMonitor();
    if(sec==='manage')loadManageSel();
    if(sec==='users'||sec==='dashboard')loadUsers();
    if(sec==='payments'){loadPayUserSel();renderPayments();}
    if(sec==='vpnconfig')loadVpnConfig();
    if(sec==='admins')loadAdmins();
    closeSidebar();
  });
});
// ── DASHBOARD & USERS ──
async function loadStatus(){
  const d=await api('status');
  if(d.api_status){
    $('#statusDot').classList.add('on');
    $('#statusText').textContent='// ONLINE';
    $('#sv1').textContent=d.total_usuarios??'—';
    $('#sv2').textContent=d.api_status;
    $('#sv3').textContent=new Date(d.timestamp).toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    ['sc1','sc2','sc3','sc4'].forEach(id=>$('#'+id).classList.add('loaded'));
    $('#navUserCount').textContent=d.total_usuarios??0;
  } else {
    $('#statusText').textContent='// ERROR';
    $('#statusDot').classList.remove('on');
  }
}

let userInterval;
const demoVals={};
async function loadUsers(){
  const d=await api('listar');
  if(userInterval)clearInterval(userInterval);
  const render=(tb)=>{
    if(!d.usuarios?.length){tb.innerHTML='<tr><td colspan="4"><div class="empty-state">// SIN USUARIOS</div></td></tr>';return;}
    const now=Math.floor(Date.now()/1000);
    let hasActiveDemo=false;
    tb.innerHTML=d.usuarios.map((u,i)=>{
      let expDisplay=u.expiration,isExpired=false,diasRestantes='';
      if(u.expiration.startsWith('ts:')){
        const endTs=parseInt(u.expiration.split(':')[1]);
        const left=endTs-now;
        if(left<=0){expDisplay=`<span style="color:var(--red);font-family:var(--f-mono);font-size:11px">▸ EXPIRADO</span>`;isExpired=true;}
        else{
          hasActiveDemo=true;
          const diasF=Math.floor(left/86400);
          if(diasF>=1){
            const styleD=diasF<=2?`class="time-low"`:`style="color:var(--amber)"`;
            diasRestantes=`<span ${styleD} style="font-family:var(--f-mono);font-size:10px;margin-left:6px">${diasF}d rest.</span>`;
          }
          const m=Math.floor((left%86400)/60),s=String(left%60).padStart(2,'0');
          const style=left<300?`class="time-low"`:`style="color:var(--cyan)"`;
          expDisplay=`<span ${style} style="font-family:var(--f-mono);font-weight:bold">⏱ ${m}:${s}</span>${diasRestantes}`;
        }
      } else if(u.expiration==='Expirado'){
        expDisplay=`<span style="color:var(--red);font-family:var(--f-mono);font-size:11px">▸ EXPIRADO</span>`;isExpired=true;
      } else {
        const expDate=new Date(u.expiration.replace(/-/g,'/'));
        if(!isNaN(expDate.getTime())){
          const leftMs=expDate.getTime()-Date.now();
          const leftDays=Math.ceil(leftMs/(1000*60*60*24));
          if(leftDays<=0){expDisplay=`<span style="color:var(--red);font-family:var(--f-mono);font-size:11px">▸ EXPIRADO</span>`;isExpired=true;}
          else{
            const styleD=leftDays<=2?`class="time-low"`:(leftDays<=5?`style="color:var(--amber)"`:`style="color:var(--cyan)"`);
            expDisplay=`<span style="font-family:var(--f-mono);font-size:12px;color:var(--text2)">${u.expiration}</span> <span ${styleD} style="font-family:var(--f-mono);font-size:10px;font-weight:bold">${leftDays}d rest.</span>`;
          }
        }
      }
      const renewBtns=isExpired
        ?`<button class="btn-xs ext" onclick="qRen('${u.username}',3)">+3d</button><button class="btn-xs ext" onclick="qRen('${u.username}',7)">+7d</button>`
        :`<button class="btn-xs ren" onclick="qRen('${u.username}',3)">+3d</button><button class="btn-xs ren" onclick="qRen('${u.username}',7)">+7d</button>`;
      const dvVal=demoVals[u.username]||1;
      const demoInput=`<input type="number" min="1" max="60" value="${dvVal}" style="width:38px;padding:3px 5px;font-size:11px;border-radius:2px;border:1px solid rgba(155,93,229,.4);background:rgba(155,93,229,.08);color:var(--text);text-align:center;font-family:var(--f-mono)" id="dm_${u.username}" onchange="demoVals['${u.username}']=parseInt(this.value)||1"><button class="btn-xs demo" onclick="qDemo('${u.username}')">⏱</button>`;
      return`<tr class="${isExpired?'expired-row':''}"><td class="td-idx">${String(i+1).padStart(2,'0')}</td><td><span class="td-user">${u.username}</span></td><td style="font-size:12px">${expDisplay}</td><td><div class="td-actions">${renewBtns}${demoInput}<button class="btn-xs del" onclick="qDel('${u.username}')">DEL</button></div></td></tr>`;
    }).join('');
    if(hasActiveDemo)userInterval=setTimeout(loadUsers,1000);
  };
  render($('#tbodyDash'));
  render($('#tbody'));
}

// ── ACTIONS ──
async function qDel(u){if(!confirm('// ¿Eliminar "'+u+'"?'))return;const d=await api('eliminar',{username:u});d.ok?toast('// Eliminado: '+u):toast(d.detail||'Error','err');loadUsers();loadStatus();}
async function qRen(u,dias){const d=await api('renovar',{username:u,dias});if(d.ok){toast('// '+u+' +'+dias+'d renovado');loadUsers();}else toast(d.detail||'Error','err');}
async function qDemo(u){const el=$('#dm_'+u);const m=parseInt(el?el.value:1)||1;const d=await api('renovar',{username:u,dias:1,minutos:m});if(d.ok){toast(`// ${u} demo ${m}min activado`);loadUsers();}else toast(d.detail||'Error','err');}

// ── CREAR USUARIO (LOGICA TELEFONO) ──
$('#btn-crear').addEventListener('click',async()=>{
  const phone=$('#cu_phone').value.trim();
  if(!phone||phone.length<8){toast('// Ingresa un numero de telefono valido (min 8 digitos)','err');return;}

  const username='E'+phone.replace(/[^0-9]/g,'');
  const password=phone.replace(/[^0-9]/g,'');

  let dias=$('#cd').value,minutos=0;
  if(dias==='0'){minutos=parseInt($('#cdm').value)||1;dias=1;}

  const btn=$('#btn-crear');btn.innerHTML="<span class='spin'></span>&nbsp;CREANDO...";btn.disabled=true;
  const d=await api('crear',{username,password,dias,minutos});
  btn.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg> CREAR USUARIO';btn.disabled=false;

  if(d.ok){
    const msg=minutos>0?`// Demo creado: ${username} (${minutos} min)`:`// Creado: ${username} — expira ${d.expira||dias+'d'}`;
    toast(msg);
    toast(`// Contrasena: ${password}`,'ok');
    $('#cu_phone').value='';
    $('#createPreview').classList.remove('show');
    await loadUsers();loadStatus();
  } else toast(d.detail||JSON.stringify(d),'err');
});

// ── MANAGE ──
async function loadManageSel(){const d=await api('listar');ssManage.setItems((d.usuarios||[]).map(u=>u.username));}
$('#btn-del').addEventListener('click',async()=>{const u=ssManage.getValue();if(!u){toast('// Selecciona un usuario','err');return;}if(!confirm('// ¿Eliminar "'+u+'"? Irreversible.'))return;const d=await api('eliminar',{username:u});d.ok?toast('// Eliminado: '+u):toast(d.detail||'Error','err');ssManage.reset();loadManageSel();loadUsers();loadStatus();});
$('#btn-ren').addEventListener('click',async()=>{const u=ssManage.getValue(),dias=$('#md').value;if(!u){toast('// Selecciona un usuario','err');return;}const d=await api('renovar',{username:u,dias});d.ok?toast('// '+u+' renovado '+dias+' dia(s)'):toast(d.detail||'Error','err');});


// ── MONITOR ──
async function loadMonitor(){
  const cl=$('#connlist');cl.innerHTML=`<div class="empty-state"><span class="spin"></span></div>`;
  const d=await api('monitor');
  const list=(d.conexiones||[]).filter(c=>c.trim());
  if(!list.length){cl.innerHTML=`<div class="empty-state">// SIN CONEXIONES ACTIVAS</div>`;return;}
  cl.innerHTML=list.map(c=>`<div class="conn-item"><div class="conn-dot"></div>${c}</div>`).join('');
}
$('#btn-ref').addEventListener('click',loadMonitor);

// ── PAYMENTS ──
let cachedPayments=[];
async function loadPayments(){const d=await api('pagos_listar');cachedPayments=d.pagos||[];return cachedPayments;}
async function loadPayUserSel(){const d=await api('listar');ssPay.setItems((d.usuarios||[]).map(u=>u.username));}

function updatePayStats(){
  const pays=cachedPayments;
  const total=pays.reduce((s,p)=>s+p.amount,0);
  const transfers=pays.filter(p=>p.type==='transfer').reduce((s,p)=>s+p.amount,0);
  const cash=pays.filter(p=>p.type==='cash').reduce((s,p)=>s+p.amount,0);
  $('#payTotal').textContent='$'+total.toFixed(2);
  $('#payTransfer').textContent='$'+transfers.toFixed(2);
  $('#payCash').textContent='$'+cash.toFixed(2);
  $('#sv4').textContent='$'+total.toFixed(2);
  const recent=pays.slice(-5).reverse();
  const rDiv=$('#recentPayments');
  if(!recent.length){rDiv.innerHTML='<div class="empty-state">// SIN PAGOS REGISTRADOS</div>';return;}
  rDiv.innerHTML=recent.map(p=>`<div style="display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--border)">
    <span class="pay-badge ${p.type}">${p.type==='transfer'?'TRANSFER':'CASH'}</span>
    <span style="font-family:var(--f-mono);color:var(--text);font-size:13px">${p.user}</span>
    <span style="margin-left:auto;font-family:var(--f-hud);color:var(--green);font-size:14px">$${p.amount.toFixed(2)}</span>
    <span style="font-family:var(--f-mono);font-size:10px;color:var(--text3)">${new Date(p.date).toLocaleDateString('es')}</span>
  </div>`).join('');
}

async function renderPayments(){
  await loadPayments();
  const pays=cachedPayments,tb=$('#payTable');
  if(!pays.length){tb.innerHTML='<tr><td colspan="7"><div class="empty-state">// SIN PAGOS</div></td></tr>';updatePayStats();return;}
  tb.innerHTML=pays.slice().reverse().map((p,i)=>`<tr>
    <td class="td-idx" style="font-family:var(--f-mono);font-size:11px">${new Date(p.date).toLocaleDateString('es',{day:'2-digit',month:'short'})}<br><span style="font-size:9px;color:var(--text3)">${new Date(p.date).toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit'})}</span></td>
    <td class="td-user">${p.user}</td>
    <td style="color:var(--green);font-family:var(--f-hud);font-size:14px">$${p.amount.toFixed(2)}</td>
    <td><span class="pay-badge ${p.type}">${p.type==='transfer'?'TRF':'CASH'}</span></td>
    <td style="color:var(--text2);font-size:12px;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${p.note||'—'}</td>
    <td style="font-family:var(--f-mono);font-size:10px;color:var(--text3)">${p.admin||'—'}</td>
    <td><button class="btn-xs del" onclick="delPay(${pays.length-1-i})">✕</button></td>
  </tr>`).join('');
  updatePayStats();
}

$('#btn-pay').addEventListener('click',async()=>{
  const user=ssPay.getValue(),amount=parseFloat($('#payAmount').value),type=$('#payType').value,note=$('#payNote').value.trim();
  if(!user||!amount){toast('// Usuario y monto requeridos','err');return;}
  const btn=$('#btn-pay');btn.innerHTML="<span class='spin'></span>&nbsp;GUARDANDO...";btn.disabled=true;
  const d=await api('pagos_agregar',{user,amount,type,note});
  btn.innerHTML='REGISTRAR PAGO';btn.disabled=false;
  if(d.ok){toast(`// Pago $${amount.toFixed(2)} registrado`);$('#payAmount').value='';$('#payNote').value='';ssPay.reset();renderPayments();}
  else toast(d.error||'Error','err');
});
async function delPay(idx){if(!confirm('// ¿Eliminar pago?'))return;const d=await api('pagos_eliminar',{idx});d.ok?(toast('// Eliminado'),renderPayments()):toast(d.error||'Error','err');}
$('#btn-clearPay').addEventListener('click',async()=>{if(!confirm('// ¿Eliminar TODOS los pagos? Irreversible.'))return;const d=await api('pagos_limpiar');d.ok?(toast('// Historial limpiado'),renderPayments()):toast('Error','err');});

// ── VPN CONFIG ──
async function loadVpnConfig(){
  $('#vpnStatus').innerHTML='<span class="spin"></span>';
  const d=await api('vpn_config_leer');
  if(d.ok&&d.config){
    const c=d.config;
    $('#vpn_host').value=c.vps_host||'';$('#vpn_port').value=c.vps_port||443;
    $('#vpn_sni').value=c.sni_host||'';$('#vpn_payload').value=c.payload||'';
    $('#vpn_socks').value=c.socks_port||1080;$('#vpn_udpgw').value=c.udpgw_port||7300;
    $('#vpnStatus').innerHTML='<span style="font-family:var(--f-mono);font-size:10px;color:var(--green)">● CONECTADO</span>';
    toast('// Config cargada');
  } else {
    $('#vpnStatus').innerHTML='<span style="font-family:var(--f-mono);font-size:10px;color:var(--red)">● ERROR</span>';
    toast(d.error||'// Error al leer config','err');
  }
}
$('#btn-vpn-save').addEventListener('click',async()=>{
  const vps_host=$('#vpn_host').value.trim(),vps_port=$('#vpn_port').value,sni_host=$('#vpn_sni').value.trim(),payload=$('#vpn_payload').value.trim(),socks_port=$('#vpn_socks').value,udpgw_port=$('#vpn_udpgw').value;
  if(!vps_host||!sni_host){toast('// VPS Host y SNI requeridos','err');return;}
  const btn=$('#btn-vpn-save');btn.innerHTML="<span class='spin'></span>&nbsp;GUARDANDO...";btn.disabled=true;
  const d=await api('vpn_config_guardar',{vps_host,vps_port,sni_host,payload,socks_port,udpgw_port});
  btn.innerHTML='GUARDAR Y APLICAR';btn.disabled=false;
  if(d.ok){toast('// Guardado en Firebase');$('#vpnStatus').innerHTML='<span style="font-family:var(--f-mono);font-size:10px;color:var(--green)">● GUARDADO</span>';}
  else toast(d.error||'Error','err');
});
$('#btn-vpn-reload').addEventListener('click',loadVpnConfig);

// ── ADMINS CRUD ──
async function loadAdmins(){
  const list=$('#adminsList');
  if(!list)return;
  list.innerHTML='<div class="empty-state"><span class="spin"></span> Cargando...</div>';
  const d=await api('admins_listar');
  if(d.error){list.innerHTML='<div class="empty-state">// Error al cargar admins</div>';return;}
  const admins=d.admins||[];
  if(!admins.length){list.innerHTML='<div class="empty-state">// NO HAY ADMINISTRADORES REGISTRADOS</div>';return;}
  list.innerHTML=admins.map(a=>{
    const initial=(a.username||'?')[0].toUpperCase();
    const created=a.created?new Date(a.created).toLocaleDateString('es',{day:'2-digit',month:'short',year:'numeric'}):'—';
    return`<div class="admin-card">
      <div class="admin-avatar">${initial}</div>
      <div class="admin-info">
        <div class="admin-name">${a.username}</div>
        <div class="admin-detail">${a.email||'Sin email'} · Creado: ${created}</div>
        ${a.vps_url?'<div class="admin-detail" style="color:var(--cyan)">VPS: '+a.vps_url.replace(/^https?:\/\//,'')+'</div>':''}
      </div>
      <span class="admin-role-tag">ADMIN</span>
      <div class="admin-actions">
        <button class="btn-xs del" onclick="deleteAdmin('${a.key}')">DEL</button>
      </div>
    </div>`;
  }).join('');
}

async function deleteAdmin(key){
  if(!confirm('// ¿Eliminar este administrador? Irreversible.'))return;
  const d=await api('admin_eliminar',{key});
  if(d.ok){toast('// Administrador eliminado');loadAdmins();}
  else toast(d.error||'Error','err');
}

$('#btn-adm-crear')?.addEventListener('click',async()=>{
  const username=$('#adm_user').value.trim();
  const password=$('#adm_pass').value;
  const email=$('#adm_email').value.trim();
  const vps_url=$('#adm_vps').value.trim();
  const vps_key=$('#adm_vpskey').value.trim();
  if(!username||!password){toast('// Username y contrasena son requeridos','err');return;}
  const btn=$('#btn-adm-crear');
  btn.innerHTML="<span class='spin'></span>&nbsp;CREANDO...";btn.disabled=true;
  const d=await api('admin_crear',{username,password,email,vps_url,vps_key});
  btn.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg> CREAR ADMIN';btn.disabled=false;
  if(d.ok){
    toast('// Admin "'+username+'" creado exitosamente');
    $('#adm_user').value='';$('#adm_pass').value='';
    $('#adm_email').value='';$('#adm_vps').value='';$('#adm_vpskey').value='';
    loadAdmins();
  }else toast(d.error||'Error','err');
});

// ── INIT ──
loadStatus();
loadUsers();
loadPayments().then(()=>updatePayStats());
if($('#sec-admins'))loadAdmins();
setInterval(loadStatus,30000);
</script>

<?php endif; ?>
</body>
</html>
