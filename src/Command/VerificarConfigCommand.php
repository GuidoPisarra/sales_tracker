<?php

namespace App\Command;

use App\Repository\BaseRepository;
use App\Service\Entorno;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Revisa toda la configuración del backend y dice qué falta: variables del .env,
 * base y migraciones, claves JWT, mail, Google, Mercado Pago (y si cobra de verdad
 * o es de prueba), datos legales y fotos. No cambia nada.
 * Uso: APP_ENV=prod php bin/console app:config:verificar [--sin-red]
 */
class VerificarConfigCommand extends Command
{
    protected static $defaultName = 'app:config:verificar';
    protected static $defaultDescription = 'Dice qué falta configurar en el backend (y si Mercado Pago está en producción)';

    private const MP_API = 'https://api.mercadopago.com';

    private BaseRepository $repo;
    private HttpClientInterface $http;
    private string $projectDir;
    private OutputInterface $out;
    private int $errores = 0;
    private int $avisos = 0;
    private bool $produccion = false;

    public function __construct(BaseRepository $repo, HttpClientInterface $http, string $projectDir)
    {
        parent::__construct();
        $this->repo = $repo;
        $this->http = $http;
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this->addOption('sin-red', null, InputOption::VALUE_NONE, 'No consulta Mercado Pago (solo revisa variables)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->produccion = Entorno::get('APP_ENV') === 'prod';

        $this->entorno();
        $this->baseDeDatos();
        $this->jwt();
        $this->mail();
        $this->registro();
        $this->mercadoPago(!$input->getOption('sin-red'));
        $this->legales();
        $this->fotos();
        $this->otros();

        $output->writeln('');
        if ($this->errores === 0 && $this->avisos === 0) {
            $output->writeln('<info>Todo configurado.</info>');
        } else {
            $output->writeln(sprintf('<comment>%d para corregir, %d avisos.</comment>', $this->errores, $this->avisos));
        }
        return $this->errores === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function entorno(): void
    {
        $this->titulo('Entorno');
        $env = Entorno::get('APP_ENV');
        $this->produccion
            ? $this->ok('APP_ENV = prod')
            : $this->aviso("APP_ENV = $env (en el servidor tiene que ser prod)");
        $secreto = Entorno::get('APP_SECRET');
        in_array($secreto, ['', 'cambiar'], true)
            ? $this->error('APP_SECRET vacío o con el valor de ejemplo')
            : $this->ok('APP_SECRET cargado');
        $this->url('APP_URL_FRONT', true);
        $cors = Entorno::get('CORS_ALLOW_ORIGIN');
        if ($cors === '') {
            $this->error('Falta CORS_ALLOW_ORIGIN');
        } elseif ($cors === '*' && $this->produccion) {
            $this->aviso('CORS_ALLOW_ORIGIN = * (en producción conviene la expresión de .env.dist)');
        } else {
            $this->ok('CORS_ALLOW_ORIGIN cargado');
        }
    }

    private function baseDeDatos(): void
    {
        $this->titulo('Base de datos');
        try {
            $pdo = $this->repo->get_bbdd();
            $this->ok('Conecta con ' . preg_replace('/;?charset=[^;]*/', '', Entorno::get('BD_COM')));
        } catch (\Throwable $th) {
            $this->error('No conecta: revisá BD_COM, USUARIO_BD_COM y PASSWORD_BD_COM');
            return;
        }
        try {
            $aplicadas = $pdo->query('SELECT version FROM migraciones')->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $th) {
            $aplicadas = [];
        }
        $archivos = array_map(fn ($f) => basename($f, '.sql'), glob($this->projectDir . '/migrations/*.sql') ?: []);
        $pendientes = array_values(array_diff($archivos, $aplicadas));
        $pendientes
            ? $this->error('Migraciones pendientes: ' . implode(', ', $pendientes) . ' (php bin/console app:migraciones)')
            : $this->ok('Migraciones al día (' . count($archivos) . ')');

        $codigo = Entorno::get('PLAN_PRUEBA', 'pro');
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM plan_suscripcion WHERE codigo = :c');
            $stmt->execute([':c' => $codigo]);
            (int) $stmt->fetchColumn() > 0
                ? $this->ok("PLAN_PRUEBA = $codigo existe")
                : $this->error("PLAN_PRUEBA = $codigo no existe en plan_suscripcion");
        } catch (\Throwable $th) {
            $this->error('No se pudo leer plan_suscripcion');
        }
    }

    private function jwt(): void
    {
        $this->titulo('JWT (login)');
        $privada = str_replace('%kernel.project_dir%', $this->projectDir, Entorno::get('JWT_SECRET_KEY'));
        $publica = str_replace('%kernel.project_dir%', $this->projectDir, Entorno::get('JWT_PUBLIC_KEY'));
        if (!is_readable($privada) || !is_readable($publica)) {
            $this->error('Faltan las claves JWT (php bin/console lexik:jwt:generate-keypair)');
            return;
        }
        openssl_pkey_get_private((string) file_get_contents($privada), Entorno::get('JWT_PASSPHRASE')) !== false
            ? $this->ok('Claves JWT presentes y JWT_PASSPHRASE correcta')
            : $this->error('JWT_PASSPHRASE no abre la clave privada');
    }

    private function mail(): void
    {
        $this->titulo('Mail');
        $dsn = Entorno::get('MAILER_DSN', 'null://null');
        str_starts_with($dsn, 'null://')
            ? $this->error('MAILER_DSN vacío: no salen los mails de verificación, recuperar clave ni avisos')
            : $this->ok('MAILER_DSN cargado (' . preg_replace('#//[^@]*@#', '//***@', $dsn) . ')');
        $this->requerida('MAIL_FROM');
        Entorno::lista('ADMIN_EMAILS')
            ? $this->ok('ADMIN_EMAILS = ' . implode(', ', Entorno::lista('ADMIN_EMAILS')))
            : $this->error('Falta ADMIN_EMAILS (avisos internos y panel /admin)');
    }

    private function registro(): void
    {
        $this->titulo('Registro y prueba gratis');
        Entorno::lista('GOOGLE_CLIENT_IDS')
            ? $this->ok('GOOGLE_CLIENT_IDS = ' . implode(', ', Entorno::lista('GOOGLE_CLIENT_IDS')))
            : $this->error('Falta GOOGLE_CLIENT_IDS (no aparece el botón de Google)');
        $sitio = Entorno::get('TURNSTILE_SITE_KEY');
        $secreto = Entorno::get('TURNSTILE_SECRET');
        if ($sitio === '' && $secreto === '') {
            $this->aviso('Sin captcha (TURNSTILE_*): opcional, el registro igual tiene límite por IP');
        } elseif ($sitio === '' || $secreto === '') {
            $this->error('Captcha a medias: TURNSTILE_SITE_KEY y TURNSTILE_SECRET van juntos');
        } else {
            $this->ok('Captcha Turnstile cargado');
        }
        $this->ok(sprintf('DIAS_PRUEBA = %d, DIAS_GRACIA_MORA = %d', Entorno::int('DIAS_PRUEBA', 7), Entorno::int('DIAS_GRACIA_MORA', 7)));
    }

    private function mercadoPago(bool $conRed): void
    {
        $this->titulo('Mercado Pago');
        $token = Entorno::get('MP_ACCESS_TOKEN');
        $token === ''
            ? $this->error('Falta MP_ACCESS_TOKEN (no se puede pagar ningún plan)')
            : $this->ok('MP_ACCESS_TOKEN = ' . substr($token, 0, 12) . '…');
        Entorno::get('MP_WEBHOOK_SECRET') === ''
            ? $this->error('Falta MP_WEBHOOK_SECRET (los avisos de pago se rechazan)')
            : $this->ok('MP_WEBHOOK_SECRET cargado');
        $this->url('MP_BACK_URL', false);
        if ($token === '') {
            return;
        }
        if (!$conRed) {
            $this->aviso('Sin consultar Mercado Pago (--sin-red)');
            return;
        }

        try {
            $cuenta = $this->mp('/users/me');
        } catch (\Throwable $th) {
            $this->error('Mercado Pago rechazó el Access Token: ' . $th->getMessage());
            return;
        }
        $nick = (string) ($cuenta['nickname'] ?? '?');
        $this->ok(sprintf('Cuenta: %s (id %s, %s, %s)', $nick, $cuenta['id'] ?? '?', $cuenta['email'] ?? 'sin email', $cuenta['site_id'] ?? '?'));

        $motivos = [];
        if (str_starts_with($token, 'TEST-')) {
            $motivos[] = 'el token es TEST-';
        }
        if (in_array('test_user', $cuenta['tags'] ?? [], true) || stripos($nick, 'TESTUSER') === 0 || str_ends_with((string) ($cuenta['email'] ?? ''), '@testuser.com')) {
            $motivos[] = 'la cuenta es un usuario de prueba';
        }
        if ($motivos) {
            $this->aviso('Modo PRUEBA (' . implode(' y ', $motivos) . '): no cobra de verdad');
        } else {
            $this->ok('Modo PRODUCCIÓN: los pagos son reales');
        }

        try {
            $pagos = $this->mp('/v1/payments/search?' . http_build_query([
                'range' => 'date_created', 'begin_date' => 'NOW-30DAYS', 'end_date' => 'NOW', 'limit' => 100,
            ]))['results'] ?? [];
            // live_mode no sirve para distinguir: los pagos entre usuarios de prueba también vienen en true.
            $aprobados = count(array_filter($pagos, fn ($p) => ($p['status'] ?? '') === 'approved'));
            $this->ok(sprintf('Pagos recibidos en los últimos 30 días: %d (%d aprobados)', count($pagos), $aprobados));
        } catch (\Throwable $th) {
            $this->aviso('No se pudieron leer los pagos recientes: ' . $th->getMessage());
        }
    }

    private function legales(): void
    {
        $this->titulo('Datos legales y soporte');
        foreach (['LEGAL_TITULAR', 'LEGAL_CUIT', 'LEGAL_PROVINCIA', 'SOPORTE_EMAIL', 'SOPORTE_WHATSAPP'] as $variable) {
            Entorno::get($variable) === ''
                ? $this->aviso("Falta $variable (Términos, Privacidad y Ayuda quedan incompletos)")
                : $this->ok("$variable = " . Entorno::get($variable));
        }
    }

    private function fotos(): void
    {
        $this->titulo('Fotos de productos');
        if (!function_exists('gd_info') || empty(gd_info()['WebP Support'])) {
            $this->error('PHP sin GD con WebP (sudo apt install php8.x-gd)');
        } else {
            $this->ok('GD con WebP');
        }
        $almacen = strtolower(Entorno::get('FOTOS_ALMACEN', 'local'));
        if ($almacen === 'r2') {
            foreach (['R2_ACCOUNT_ID', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET'] as $variable) {
                $this->requerida($variable, true);
            }
        } else {
            $dir = Entorno::get('FOTOS_DIR', $this->projectDir . '/var/fotos');
            if (!is_dir($dir)) {
                $this->error("FOTOS_DIR = $dir no existe");
            } elseif (!is_writable($dir)) {
                // Corriendo como otro usuario puede no tener permiso aunque Apache sí.
                $this->aviso("FOTOS_DIR = $dir existe, pero este usuario no puede escribir (tiene que poder www-data)");
            } else {
                $this->ok("FOTOS_DIR = $dir");
            }
        }
        $base = Entorno::get('FOTOS_URL_BASE', '/fotos');
        str_starts_with($base, 'http')
            ? $this->ok("FOTOS_URL_BASE = $base")
            : $this->error("FOTOS_URL_BASE = $base es relativa: el front (otro dominio) no carga las fotos");
        $limite = ini_get('upload_max_filesize');
        $this->ok("upload_max_filesize = $limite en este PHP (el de Apache tiene que permitir 10M)");
    }

    private function otros(): void
    {
        $this->titulo('Opcionales');
        $opcionales = [
            'GEMINI_API_KEY' => 'lectura de facturas con IA',
            'AGENTE_IA_URL' => 'asistente de IA',
            'DOLAR_API_URL' => 'cotización del dólar',
            'IPC_API_URL' => 'inflación',
            'LOGS' => 'carpeta de logs de la app',
        ];
        foreach ($opcionales as $variable => $para) {
            Entorno::get($variable) === ''
                ? $this->aviso("Falta $variable ($para)")
                : $this->ok("$variable cargado");
        }
        Entorno::get('AVISAR_ERRORES') === '1'
            ? $this->ok('AVISAR_ERRORES = 1')
            : $this->aviso('AVISAR_ERRORES apagado: los errores 500 no avisan por mail');
    }

    private function mp(string $ruta): array
    {
        $r = $this->http->request('GET', self::MP_API . $ruta, [
            'headers' => ['Authorization' => 'Bearer ' . Entorno::get('MP_ACCESS_TOKEN')],
            'timeout' => 20,
        ]);
        $datos = $r->toArray(false);
        if ($r->getStatusCode() !== 200) {
            throw new \RuntimeException($r->getStatusCode() . ' ' . ($datos['message'] ?? ''));
        }
        return $datos;
    }

    private function url(string $variable, bool $obligatoria): void
    {
        $valor = Entorno::get($variable);
        if ($valor === '') {
            $obligatoria ? $this->error("Falta $variable") : $this->aviso("Falta $variable (se usa APP_URL_FRONT/mi-plan)");
        } elseif ($this->produccion && preg_match('#^http://|localhost|127\.0\.0\.1#', $valor)) {
            $this->error("$variable = $valor apunta a local en producción");
        } else {
            $this->ok("$variable = $valor");
        }
    }

    private function requerida(string $variable, bool $secreta = false): void
    {
        $valor = Entorno::get($variable);
        $valor === ''
            ? $this->error("Falta $variable")
            : $this->ok($variable . ' = ' . ($secreta ? substr($valor, 0, 6) . '…' : $valor));
    }

    private function titulo(string $texto): void
    {
        $this->out->writeln('');
        $this->out->writeln("<options=bold>$texto</>");
    }

    private function ok(string $texto): void
    {
        $this->out->writeln("  <info>✓</info> $texto");
    }

    private function aviso(string $texto): void
    {
        $this->avisos++;
        $this->out->writeln("  <comment>!</comment> $texto");
    }

    private function error(string $texto): void
    {
        $this->errores++;
        $this->out->writeln("  <error>✗</error> $texto");
    }
}
