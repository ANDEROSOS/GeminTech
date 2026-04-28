<?php
/**
 * GeminTech Web Panel — Configuración
 * Editar los valores con los datos de tu VPS antes de subir al hosting.
 */
final class GeminConfig {
    // ─── API FastAPI Backend (tu VPS) ─────────────────────────
    public static string $api_base   = 'http://TU_VPS_IP:9000';  // ← Editar con tu IP
    public static string $api_key    = 'Ecuador2026_Secreto_Api';

    // ─── Firebase (config VPN para app móvil) ─────────────────
    public static string $firebase_url = 'https://videocall-71a95-default-rtdb.firebaseio.com';
    public static string $vpn_path     = '/vpn_config.json';

    // ─── Archivo local de pagos ────────────────────────────────
    public static function pagosFile(): string {
        return __DIR__ . '/pagos.json';
    }
}
