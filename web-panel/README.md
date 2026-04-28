# GeminTech Web Panel

Panel administrativo PHP para gestión de usuarios SSH y VPN.
Se aloja en cualquier hosting PHP (AlwaysData, cPanel, etc.)

## Instalación en AlwaysData

1. Subir todos los archivos de esta carpeta al hosting
2. Editar `config.php` con los valores de tu VPS:

```php
define('API_BASE', 'http://TU_IP_VPS:9000');   // IP de tu VPS
define('API_KEY',  'TU_API_KEY');               // Igual que api/.env
define('FIREBASE_URL', 'https://TU_PROYECTO.firebaseio.com');
```

3. Abrir `index.php` en el navegador ✅

## Archivos

| Archivo | Descripción |
|---|---|
| `index.php` | Panel administrativo principal |
| `config.php` | ⚙️ Configuración — editar con tus datos |
| `.htaccess` | Protege pagos.json de acceso directo |

## Secciones del panel

- **Dashboard** — Estado de la API, total usuarios, pagos
- **Usuarios** — Lista con expiración en tiempo real
- **Crear** — Usuario SSH regular o Demo (por minutos)
- **Gestionar** — Eliminar, renovar, cambiar password
- **Monitor** — Conexiones SSH activas
- **Pagos** — Registro efectivo/transferencia (almacenado en pagos.json)
- **VPN Config** — Sync configuración a Firebase para app móvil

## Requisitos

- PHP 8.0+ con extensión `curl`
- VPS con API GeminTech corriendo en puerto 9000
