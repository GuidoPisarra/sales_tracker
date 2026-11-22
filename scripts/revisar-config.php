<?php

/**
 * Revisa la configuración del backend de Kontia y dice qué falta.
 *
 * Uso, desde la carpeta del proyecto (en el VPS: /var/www/html/sales_tracker):
 *     php scripts/revisar-config.php
 *     php scripts/revisar-config.php --sin-red    (no consulta Mercado Pago, Gemini ni Google)
 *
 * No cambia nada: solo lee las variables (.env.local.php o .env/.env.local), la base
 * y el crontab, y consulta las APIs externas con GET. Nunca muestra claves completas.
 */

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

$raiz = dirname(__DIR__);
chdir($raiz);
if (!is_file($raiz . '/vendor/autoload.php')) {
    fwrite(STDERR, "Falta vendor/. Corré primero: composer install\n");
    exit(1);
}
require $raiz . '/vendor/autoload.php';

$sinRed = in_array('--sin-red', $argv, true);

// ---------------------------------------------------------------- variables

$origen = '';
if (is_file($raiz . '/.env.local.php')) {
    $vars = (array) include $raiz . '/.env.local.php';
    $origen = '.env.local.php (los cambios en .env no cuentan hasta correr: composer dump-env prod)';
} else {
    (new Dotenv())->usePutenv(false)->loadEnv($raiz . '/.env');
    $vars = $_ENV;
    $origen = '.env + .env.local';
}
foreach (getenv() as $k => $v) {
    $vars[$k] ??= $v;
}
$v = fn (string $k): string => trim((string) ($vars[$k] ?? ''));

// ---------------------------------------------------------------- salida

$conteo = ['ok' => 0, 'aviso' => 0, 'falta' => 0];
$pendientes = [];
function titulo(string $t): void { echo "\n\033[1m== $t ==\033[0m\n"; }
function linea(string $tipo, string $texto, string $como = ''): void
{
    global $conteo, $pendientes;
    $conteo[$tipo]++;
    $marca = ['ok' => "\033[32m✔\033[0m", 'aviso' => "\033[33m!\033[0m", 'falta' => "\033[31m✘\033[0m"][$tipo];
    echo "  $marca $texto\n";
    if ($como !== '') {
        echo "      → $como\n";
    }
    if ($tipo !== 'ok') {
        $pendientes[] = ($tipo === 'falta' ? '✘ ' : '! ') . $texto;
    }
}
function oculto(string $valor): string
{
    return $valor === '' ? '' : substr($valor, 0, 6) . '…(' . strlen($valor) . ' caracteres)';
}
function requerida(string $k, string $para, bool $secreta = false): bool
{
    global $v;
    $valor = $v($k);
    if ($valor === '') {
        linea('falta', "$k vacía", "Necesaria para $para.");
        return false;
    }
    linea('ok', "$k = " . ($secreta ? oculto($valor) : $valor));
    return true;
}
$http = $sinRed ? null : HttpClient::create(['timeout' => 20]);
function pedir(string $metodo, string $url, array $opciones = []): array
{
    global $http;
    try {
        $r = $http->request($metodo, $url, $opciones);
        return [$r->getStatusCode(), $r->toArray(false)];
    } catch (\Throwable $e) {
        return [0, ['error' => $e->getMessage()]];
    }
}

echo "Revisando la configuración de Kontia en $raiz\nVariables leídas de: $origen\n";

// ---------------------------------------------------------------- básico

titulo('Básico');
$env = $v('APP_ENV');
$env === 'prod' ? linea('ok', 'APP_ENV = prod') : linea('aviso', "APP_ENV = " . ($env ?: '(vacío)'), 'En el VPS tiene que ser prod.');
$secret = $v('APP_SECRET');
if ($secret === '' || strlen($secret) < 16) {
    linea('falta', 'APP_SECRET vacío o muy corto', 'Generá uno con: php -r "echo bin2hex(random_bytes(16));"');
} else {
    linea('ok', 'APP_SECRET configurado');
}
$cors = $v('CORS_ALLOW_ORIGIN');
$cors === '' ? linea('falta', 'CORS_ALLOW_ORIGIN vacío', 'Sin esto el front de Vercel no puede llamar a la API.')
    : (str_contains($cors, 'kontia') ? linea('ok', "CORS_ALLOW_ORIGIN = $cors") : linea('aviso', "CORS_ALLOW_ORIGIN = $cors", 'No menciona kontia-app.vercel.app: revisá que el front tenga permiso.'));
foreach (['JWT_SECRET_KEY', 'JWT_PUBLIC_KEY'] as $k) {
    $ruta = str_replace('%kernel.project_dir%', $raiz, $v($k));
    $ruta !== '' && is_file($ruta) ? linea('ok', "$k existe") : linea('falta', "$k no encontrado ($ruta)", 'Generalo con: php bin/console lexik:jwt:generate-keypair');
}
requerida('JWT_PASSPHRASE', 'firmar los inicios de sesión', true);
is_writable($raiz . '/var') ? linea('ok', 'var/ se puede escribir') : linea('falta', 'var/ no se puede escribir', 'chown -R www-data:www-data var');

// ---------------------------------------------------------------- base de datos

titulo('Base de datos');
$pdo = null;
if (requerida('BD_COM', 'conectarse a MySQL') && $v('USUARIO_BD_COM') !== '') {
    try {
        $pdo = new PDO($v('BD_COM'), $v('USUARIO_BD_COM'), $v('PASSWORD_BD_COM'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        linea('ok', 'Conecta con la base');
    } catch (\Throwable $e) {
        linea('falta', 'No conecta con la base: ' . $e->getMessage(), 'Revisá BD_COM, USUARIO_BD_COM y PASSWORD_BD_COM, y que MySQL esté prendido.');
    }
}
if ($pdo) {
    $archivos = array_map(fn ($f) => basename($f, '.sql'), glob($raiz . '/migrations/*.sql') ?: []);
    try {
        $aplicadas = $pdo->query('SELECT version FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        $aplicadas = [];
    }
    $faltan = array_values(array_diff($archivos, $aplicadas));
    $faltan ? linea('falta', 'Migraciones sin correr: ' . implode(', ', $faltan), 'php bin/console app:migraciones (hacé backup de la base antes)')
        : linea('ok', 'Todas las migraciones aplicadas (' . count($archivos) . ')');
}

// ---------------------------------------------------------------- mails

titulo('Mails (verificación, recuperar clave, avisos de plan)');
$mailer = $v('MAILER_DSN');
if ($mailer === '' || str_starts_with($mailer, 'null://')) {
    linea('falta', 'MAILER_DSN vacío o null://: no sale ningún mail', 'Ej. Gmail: smtp://usuario%40gmail.com:CLAVE_DE_APLICACION@smtp.gmail.com:587');
} else {
    linea('ok', 'MAILER_DSN = ' . preg_replace('#//([^:@/]+):[^@]+@#', '//$1:****@', $mailer));
}
requerida('MAIL_FROM', 'el remitente de los mails');
$v('AVISAR_ERRORES') === '0'
    ? linea('aviso', 'Avisos de errores 500 apagados (AVISAR_ERRORES=0)', 'Con 1 (o vacío) llega un mail a ADMIN_EMAILS cuando la API falla.')
    : linea('ok', 'Avisos de errores 500 por mail a ADMIN_EMAILS: prendidos');

// ---------------------------------------------------------------- registro y SaaS

titulo('Registro, prueba gratis y panel de admin');
$front = $v('APP_URL_FRONT');
$front === '' ? linea('falta', 'APP_URL_FRONT vacío', 'URL del front (https://kontia-app.vercel.app): se usa en los links de los mails.')
    : (str_starts_with($front, 'https://') ? linea('ok', "APP_URL_FRONT = $front") : linea('aviso', "APP_URL_FRONT = $front", 'En producción debería ser https://kontia-app.vercel.app'));
requerida('GOOGLE_CLIENT_IDS', 'el botón "Continuar con Google"');
requerida('ADMIN_EMAILS', 'entrar al panel Administración de Kontia');
linea('ok', 'Prueba gratis: ' . ($v('DIAS_PRUEBA') ?: '7 (por defecto)') . ' días de ' . ($v('PLAN_PRUEBA') ?: 'pro') . '; gracia por mora: ' . ($v('DIAS_GRACIA_MORA') ?: '7') . ' días');
if ($v('TURNSTILE_SECRET') !== '' xor $v('TURNSTILE_SITE_KEY') !== '') {
    linea('falta', 'Captcha a medias: hay TURNSTILE_SECRET o TURNSTILE_SITE_KEY pero no los dos', 'Cargá las dos claves de Cloudflare Turnstile, o ninguna.');
} elseif ($v('TURNSTILE_SECRET') === '') {
    linea('aviso', 'Captcha del registro apagado (sin TURNSTILE_*)', 'Opcional, frena registros automáticos.');
} else {
    linea('ok', 'Captcha del registro activo');
}

titulo('Datos legales y soporte (términos, privacidad, pie de los mails)');
foreach (['LEGAL_TITULAR' => 'Vipi Labs', 'LEGAL_CUIT' => '', 'LEGAL_PROVINCIA' => 'Buenos Aires', 'SOPORTE_EMAIL' => '', 'SOPORTE_WHATSAPP' => ''] as $k => $defecto) {
    $v($k) !== '' ? linea('ok', "$k = " . $v($k)) : linea($defecto !== '' ? 'aviso' : 'falta', "$k vacío" . ($defecto !== '' ? " (usa \"$defecto\")" : ''), 'Se muestra en términos, privacidad y ayuda.');
}

// ---------------------------------------------------------------- Mercado Pago

titulo('Mercado Pago (suscripciones)');
$token = $v('MP_ACCESS_TOKEN');
if ($token === '') {
    linea('falta', 'MP_ACCESS_TOKEN vacío: los planes no se pueden pagar', 'Access Token de producción (APP_USR-...) de tu aplicación de Mercado Pago.');
} else {
    linea('ok', 'MP_ACCESS_TOKEN = ' . oculto($token));
    if (str_starts_with($token, 'TEST-')) {
        linea('aviso', 'El token es TEST-: modo prueba, no cobra de verdad', 'Para producción usá las credenciales de producción (APP_USR-...).');
    }
    if ($http) {
        [$estado, $yo] = pedir('GET', 'https://api.mercadopago.com/users/me', ['headers' => ['Authorization' => "Bearer $token"]]);
        if ($estado !== 200) {
            linea('falta', 'Mercado Pago rechaza el token (' . ($estado ?: 'sin conexión') . '): ' . ($yo['message'] ?? $yo['error'] ?? ''), 'Copiá de nuevo el Access Token de producción.');
        } else {
            $esPrueba = in_array('test_user', (array) ($yo['tags'] ?? []), true) || str_starts_with(strtoupper((string) ($yo['nickname'] ?? '')), 'TEST');
            $cuenta = ($yo['nickname'] ?? '?') . ' (id ' . ($yo['id'] ?? '?') . ', ' . ($yo['site_id'] ?? '?') . ')';
            if ($esPrueba || str_starts_with($token, 'TEST-')) {
                linea('aviso', "MERCADO PAGO EN MODO PRUEBA. Cuenta: $cuenta", 'Es un usuario de prueba: los pagos no son reales. En producción poné el token de tu cuenta real.');
            } else {
                linea('ok', "MERCADO PAGO EN PRODUCCIÓN. Cobra a la cuenta real: $cuenta");
            }
            [$estado, $busqueda] = pedir('GET', 'https://api.mercadopago.com/preapproval/search?limit=1', ['headers' => ['Authorization' => "Bearer $token"]]);
            if ($estado === 200) {
                linea('ok', 'Suscripciones creadas en esta cuenta de Mercado Pago: ' . (int) ($busqueda['paging']['total'] ?? 0));
            }
        }
    }
}
$secretoWebhook = $v('MP_WEBHOOK_SECRET');
$secretoWebhook !== '' ? linea('ok', 'MP_WEBHOOK_SECRET = ' . oculto($secretoWebhook))
    : linea('falta', 'MP_WEBHOOK_SECRET vacío: se rechazan los avisos de Mercado Pago', 'En tu app de MP > Webhooks, URL https://www.ventas-xpress.com.ar/api/webhooks/mercadopago, evento "Planes y suscripciones"; copiá la clave secreta.');
$volver = $v('MP_BACK_URL');
$volver === '' ? linea('falta', 'MP_BACK_URL vacío', 'https://kontia-app.vercel.app/mi-plan')
    : (str_starts_with($volver, 'https://') ? linea('ok', "MP_BACK_URL = $volver") : linea('aviso', "MP_BACK_URL = $volver", 'Mercado Pago pide https en producción: https://kontia-app.vercel.app/mi-plan'));
if ($pdo) {
    try {
        $fila = $pdo->query("SELECT COUNT(*) total, SUM(mp_preapproval_id IS NOT NULL) con_mp, SUM(estado = 'activa' AND mp_preapproval_id IS NOT NULL) pagando FROM suscripcion")->fetch(PDO::FETCH_ASSOC);
        linea('ok', sprintf('En la base: %d negocios con suscripción, %d pasaron por Mercado Pago, %d pagando hoy', $fila['total'], $fila['con_mp'], $fila['pagando']));
    } catch (\Throwable $e) {
        linea('aviso', 'No pude leer la tabla suscripcion', 'Puede faltar la migración 0002.');
    }
}

// ---------------------------------------------------------------- IA

titulo('Cargar factura con IA (Gemini)');
$gemini = $v('GEMINI_API_KEY');
$modelo = $v('GEMINI_MODEL') ?: '(el del código)';
if ($gemini === '') {
    linea('falta', 'GEMINI_API_KEY vacía: "Cargar factura" queda apagada', 'Creá una clave en aistudio.google.com.');
} else {
    linea('ok', 'GEMINI_API_KEY = ' . oculto($gemini) . "; modelo $modelo");
    if ($http && $v('GEMINI_MODEL') !== '') {
        [$estado, $info] = pedir('GET', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($v('GEMINI_MODEL')), ['headers' => ['x-goog-api-key' => $gemini]]);
        $estado === 200 ? linea('ok', 'Gemini acepta la clave y el modelo ' . $v('GEMINI_MODEL'))
            : linea('falta', 'Gemini rechaza la clave o el modelo (' . ($estado ?: 'sin conexión') . '): ' . substr((string) ($info['error']['message'] ?? $info['error'] ?? ''), 0, 120), 'Revisá GEMINI_API_KEY y GEMINI_MODEL.');
    }
}

// ---------------------------------------------------------------- fotos

titulo('Fotos de productos');
function_exists('imagewebp') ? linea('ok', 'PHP GD con WebP instalado') : linea('falta', 'Falta PHP GD con WebP', 'sudo apt install php-gd && sudo systemctl reload apache2');
$almacen = strtolower($v('FOTOS_ALMACEN') ?: 'local');
if ($almacen === 'r2') {
    foreach (['R2_ACCOUNT_ID', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET', 'FOTOS_URL_BASE'] as $k) {
        requerida($k, 'guardar fotos en Cloudflare R2', str_contains($k, 'SECRET') || str_contains($k, 'KEY_ID'));
    }
} else {
    $dir = $v('FOTOS_DIR') ?: $raiz . '/var/fotos';
    linea('ok', "Fotos en carpeta local: $dir");
    if (!is_dir($dir)) {
        linea('falta', "La carpeta $dir no existe", "sudo mkdir -p $dir && sudo chown www-data:www-data $dir");
    } elseif (function_exists('posix_getpwuid') && (posix_getpwuid((int) fileowner($dir))['name'] ?? '') !== 'www-data') {
        linea('aviso', "$dir no es de www-data: Apache puede no poder guardar fotos", "sudo chown -R www-data:www-data $dir");
    }
    $base = $v('FOTOS_URL_BASE');
    $base === '' || str_starts_with($base, '/')
        ? linea('falta', 'FOTOS_URL_BASE ' . ($base ?: 'vacío') . ': el front (en Vercel) no va a encontrar las fotos', 'FOTOS_URL_BASE=https://www.ventas-xpress.com.ar/fotos y el Alias de scripts/apache-fotos.conf')
        : linea('ok', "FOTOS_URL_BASE = $base");
}

// ---------------------------------------------------------------- cron

titulo('Tareas programadas (cron)');
$crontab = '';
foreach (['', '-u www-data '] as $quien) {
    $crontab .= (string) @shell_exec("crontab {$quien}-l 2>/dev/null");
}
foreach (['app:suscripciones:conciliar' => 'cobros de Mercado Pago y avisos de fin de prueba', 'backup-db.sh' => 'backup diario de la base', 'app:fotos:limpiar' => 'borrar fotos viejas'] as $tarea => $para) {
    str_contains($crontab, $tarea) ? linea('ok', "Cron de $tarea") : linea('falta', "No hay cron de $tarea ($para)", 'Copiá la línea de scripts/crontab.ejemplo con: crontab -e');
}

// ---------------------------------------------------------------- resumen

titulo('Resumen');
printf("  %d bien, %d avisos, %d faltantes\n", $conteo['ok'], $conteo['aviso'], $conteo['falta']);
if ($pendientes) {
    echo "\n  Para hacer:\n";
    foreach ($pendientes as $p) {
        echo "    $p\n";
    }
}
echo "\n";
exit($conteo['falta'] > 0 ? 1 : 0);
