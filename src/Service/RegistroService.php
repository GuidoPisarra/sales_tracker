<?php

namespace App\Service;

use App\Repository\CuentaRepository;

/**
 * Alta self-service de un comercio: crea negocio, titular y prueba gratis,
 * y manda el mail de verificación. También recuperación de clave y Google.
 */
class RegistroService
{
    private CuentaRepository $repo;
    private MailService $mail;
    private AuditoriaService $auditoria;

    public function __construct(CuentaRepository $repo, MailService $mail, AuditoriaService $auditoria)
    {
        $this->repo = $repo;
        $this->mail = $mail;
        $this->auditoria = $auditoria;
    }

    /**
     * @return string[] errores de validación (vacío si está todo bien)
     */
    public function validar(array $d, bool $conClave = true): array
    {
        $errores = [];
        $comercio = trim((string) ($d['nombre_comercio'] ?? ''));
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));

        if (mb_strlen($comercio) < 2 || mb_strlen($comercio) > 150) {
            $errores[] = 'Ingresá el nombre del comercio.';
        }
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150) {
            $errores[] = 'Ingresá tu nombre.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El email no es válido.';
        }
        if ($conClave) {
            $clave = (string) ($d['password'] ?? '');
            if (strlen($clave) < 8 || !preg_match('/[A-Za-z]/', $clave) || !preg_match('/\d/', $clave)) {
                $errores[] = 'La clave tiene que tener al menos 8 caracteres, con letras y números.';
            }
        }
        if (empty($d['acepta_terminos'])) {
            $errores[] = 'Tenés que aceptar los términos y la política de privacidad.';
        }
        return $errores;
    }

    private function datosPrueba(): array
    {
        $plan = Entorno::get('PLAN_PRUEBA', 'pro');
        $idPlan = $this->repo->idPlanPorCodigo($plan);
        if ($idPlan === null) {
            throw new \RuntimeException('No existe el plan de prueba "' . $plan . '". ¿Corriste las migraciones?');
        }
        $hasta = (new \DateTimeImmutable())->modify('+' . Entorno::int('DIAS_PRUEBA', 7) . ' days');
        return ['id_plan' => $idPlan, 'prueba_hasta' => $hasta->format('Y-m-d 23:59:59')];
    }

    /**
     * Registro con email y clave. Lanza \DomainException con un mensaje para el usuario.
     */
    public function registrar(array $d): int
    {
        $email = strtolower(trim((string) $d['email']));
        if ($this->repo->emailExiste($email)) {
            throw new \DomainException('Ya existe una cuenta con ese email. Probá ingresar o recuperar la clave.');
        }
        $token = bin2hex(random_bytes(32));
        $nombre = trim((string) $d['nombre']);

        [$idNegocio, $idUsuario] = $this->repo->crearCuenta($this->datosPrueba() + [
            'nombre_comercio' => trim((string) $d['nombre_comercio']),
            'telefono' => isset($d['telefono']) ? trim((string) $d['telefono']) : null,
            'nombre' => $nombre,
            'email' => $email,
            'password_hash' => password_hash((string) $d['password'], PASSWORD_DEFAULT),
            'email_verificado' => false,
            'token_verificacion' => $token,
        ]);

        $this->mail->enviarVerificacion($email, $nombre, $token);
        $this->auditoria->registrar('registro', ['email' => $email, 'via' => 'email'], $idNegocio, $idUsuario);
        return $idNegocio;
    }

    /**
     * Alta con Google: el email ya viene verificado por Google, no hay clave.
     *
     * @param array{sub: string, email: string, nombre: string} $google
     */
    public function registrarConGoogle(array $google, array $d): int
    {
        if ($this->repo->emailExiste($google['email'])) {
            throw new \DomainException('Ya existe una cuenta con ese email.');
        }
        [$idNegocio, $idUsuario] = $this->repo->crearCuenta($this->datosPrueba() + [
            'nombre_comercio' => trim((string) $d['nombre_comercio']),
            'telefono' => isset($d['telefono']) ? trim((string) $d['telefono']) : null,
            'nombre' => trim((string) ($d['nombre'] ?? '')) ?: $google['nombre'],
            'email' => $google['email'],
            // Clave aleatoria imposible de adivinar: entra con Google o la cambia con "olvidé mi clave".
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'email_verificado' => true,
            'google_id' => $google['sub'],
        ]);
        $this->auditoria->registrar('registro', ['email' => $google['email'], 'via' => 'google'], $idNegocio, $idUsuario);
        $this->mail->enviarBienvenida($google['email'], trim((string) ($d['nombre'] ?? '')) ?: (string) $google['nombre']);
        return $idNegocio;
    }

    public function verificarEmail(string $token): bool
    {
        $email = $token !== '' ? $this->repo->verificarEmail($token) : null;
        if ($email !== null) {
            $this->auditoria->registrar('email_verificado', ['email' => $email]);
            $u = $this->repo->usuarioPorEmail($email);
            $this->mail->enviarBienvenida($email, (string) ($u['name'] ?? ''));
        }
        return $email !== null;
    }

    public function reenviarVerificacion(string $email): void
    {
        $u = $this->repo->usuarioPorEmail(strtolower(trim($email)));
        if (!$u || (int) $u['email_verificado'] === 1 || (int) $u['eliminado'] === 1) {
            return; // No se revela si el email existe.
        }
        $token = bin2hex(random_bytes(32));
        $this->repo->nuevoTokenVerificacion((int) $u['id'], $token);
        $this->mail->enviarVerificacion($u['email'], (string) $u['name'], $token);
    }

    public function pedirRestablecerClave(string $email): void
    {
        $u = $this->repo->usuarioPorEmail(strtolower(trim($email)));
        if (!$u || (int) $u['eliminado'] === 1) {
            return; // No se revela si el email existe.
        }
        $token = bin2hex(random_bytes(32));
        // En la base se guarda solo el hash: quien lea la tabla no puede usar el link.
        $this->repo->guardarTokenReset((int) $u['id'], hash('sha256', $token), new \DateTimeImmutable('+1 hour'));
        $this->mail->enviarRestablecerClave($u['email'], (string) $u['name'], $token);
        $this->auditoria->registrar('pedido_restablecer_clave', [], (int) $u['id_negocio'], (int) $u['id']);
    }

    public function restablecerClave(string $token, string $clave): bool
    {
        if (strlen($clave) < 8 || !preg_match('/[A-Za-z]/', $clave) || !preg_match('/\d/', $clave)) {
            throw new \DomainException('La clave tiene que tener al menos 8 caracteres, con letras y números.');
        }
        $id = $token !== '' ? $this->repo->restablecerClave(hash('sha256', $token), password_hash($clave, PASSWORD_DEFAULT)) : null;
        if ($id !== null) {
            $this->auditoria->registrar('clave_restablecida', [], null, $id);
        }
        return $id !== null;
    }
}
