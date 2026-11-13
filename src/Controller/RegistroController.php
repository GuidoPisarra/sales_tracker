<?php

namespace App\Controller;

use App\Repository\CuentaRepository;
use App\Security\UserChecker;
use App\Security\UsuariosProvider;
use App\Service\AuditoriaService;
use App\Service\CaptchaService;
use App\Service\Entorno;
use App\Service\GoogleAuthService;
use App\Service\RegistroService;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Endpoints públicos: alta self-service, verificación de email, recuperación de
 * clave e ingreso/alta con Google.
 */
class RegistroController extends BaseController
{
    private function datos(Request $request): array
    {
        return json_decode($request->getContent(), true) ?: [];
    }

    private function limiteSuperado(RateLimiterFactory $limiter, Request $request): ?JsonResponse
    {
        if (!$limiter->create($request->getClientIp() ?? 'sin-ip')->consume()->isAccepted()) {
            return $this->respuesta(429, [], ['Demasiados intentos. Esperá unos minutos y probá de nuevo.'], 429);
        }
        return null;
    }

    /**
     * Datos públicos que el front necesita para armar el formulario de registro.
     */
    #[Route('/api/registro/config', name: 'app_registro_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        return $this->respuesta(200, [
            'captcha_site_key' => Entorno::get('TURNSTILE_SITE_KEY') ?: null,
            'google_client_id' => Entorno::lista('GOOGLE_CLIENT_IDS')[0] ?? null,
            'dias_prueba' => Entorno::int('DIAS_PRUEBA', 7),
        ], []);
    }

    #[Route('/api/registro', name: 'app_registro', methods: ['POST'])]
    public function registrar(Request $request, RegistroService $registro, CaptchaService $captcha, RateLimiterFactory $registroLimiter): JsonResponse
    {
        if ($r = $this->limiteSuperado($registroLimiter, $request)) {
            return $r;
        }
        $d = $this->datos($request);
        if (!$captcha->verificar($d['captcha'] ?? null, $request->getClientIp())) {
            return $this->respuesta(400, [], ['No pudimos validar que no seas un robot. Probá de nuevo.'], 400);
        }
        if ($errores = $registro->validar($d)) {
            return $this->respuesta(400, [], $errores, 400);
        }
        try {
            $registro->registrar($d);
            return $this->respuesta(201, ['requiere_verificacion' => true], [], 201);
        } catch (\DomainException $e) {
            return $this->respuesta(409, [], [$e->getMessage()], 409);
        } catch (\Throwable $th) {
            error_log('Registro: ' . $th->getMessage());
            return $this->respuesta(500, [], ['No pudimos crear la cuenta. Probá de nuevo en unos minutos.'], 500);
        }
    }

    #[Route('/api/registro/verificar', name: 'app_registro_verificar', methods: ['POST'])]
    public function verificar(Request $request, RegistroService $registro): JsonResponse
    {
        $token = (string) ($this->datos($request)['token'] ?? '');
        if (!$registro->verificarEmail($token)) {
            return $this->respuesta(400, [], ['El link de verificación no es válido o ya se usó.'], 400);
        }
        return $this->respuesta(200, ['verificado' => true], []);
    }

    #[Route('/api/registro/reenviar', name: 'app_registro_reenviar', methods: ['POST'])]
    public function reenviar(Request $request, RegistroService $registro, RateLimiterFactory $mailsCuentaLimiter): JsonResponse
    {
        if ($r = $this->limiteSuperado($mailsCuentaLimiter, $request)) {
            return $r;
        }
        $registro->reenviarVerificacion((string) ($this->datos($request)['email'] ?? ''));
        // Misma respuesta exista o no el email, para no revelar quién tiene cuenta.
        return $this->respuesta(200, ['enviado' => true], []);
    }

    #[Route('/api/usuario/olvide-clave', name: 'app_olvide_clave', methods: ['POST'])]
    public function olvideClave(Request $request, RegistroService $registro, RateLimiterFactory $mailsCuentaLimiter): JsonResponse
    {
        if ($r = $this->limiteSuperado($mailsCuentaLimiter, $request)) {
            return $r;
        }
        $registro->pedirRestablecerClave((string) ($this->datos($request)['email'] ?? ''));
        return $this->respuesta(200, ['enviado' => true], []);
    }

    #[Route('/api/usuario/restablecer-clave', name: 'app_restablecer_clave', methods: ['POST'])]
    public function restablecerClave(Request $request, RegistroService $registro, RateLimiterFactory $mailsCuentaLimiter): JsonResponse
    {
        if ($r = $this->limiteSuperado($mailsCuentaLimiter, $request)) {
            return $r;
        }
        $d = $this->datos($request);
        try {
            if (!$registro->restablecerClave((string) ($d['token'] ?? ''), (string) ($d['password'] ?? ''))) {
                return $this->respuesta(400, [], ['El link venció o ya se usó. Pedí uno nuevo.'], 400);
            }
        } catch (\DomainException $e) {
            return $this->respuesta(400, [], [$e->getMessage()], 400);
        }
        return $this->respuesta(200, ['ok' => true], []);
    }

    /**
     * Ingreso o alta con Google. Si la persona ya tiene cuenta (por Google o por
     * el mismo email) entra directo y recibe la misma respuesta que el login.
     * Si no tiene cuenta, responde 404 con requiere_registro para que el front
     * pida el nombre del comercio, y se vuelve a llamar con esos datos.
     */
    #[Route('/api/usuario/google', name: 'app_usuario_google', methods: ['POST'])]
    public function google(
        Request $request,
        GoogleAuthService $googleAuth,
        CuentaRepository $cuentas,
        RegistroService $registro,
        UsuariosProvider $provider,
        UserChecker $checker,
        AuthenticationSuccessHandler $exito,
        AuditoriaService $auditoria,
        RateLimiterFactory $googleLimiter
    ): Response {
        if ($r = $this->limiteSuperado($googleLimiter, $request)) {
            return $r;
        }
        if (!$googleAuth->configurado()) {
            return $this->respuesta(503, [], ['El ingreso con Google no está habilitado.'], 503);
        }
        $d = $this->datos($request);
        $google = $googleAuth->verificar((string) ($d['id_token'] ?? ''));
        if ($google === null) {
            return $this->respuesta(401, [], ['No pudimos validar tu cuenta de Google.'], 401);
        }

        $existente = $cuentas->usuarioPorGoogleId($google['sub']) ?? $cuentas->usuarioPorEmail($google['email']);
        if ($existente === null) {
            if (empty($d['nombre_comercio'])) {
                return $this->respuesta(404, [
                    'requiere_registro' => true,
                    'email' => $google['email'],
                    'nombre' => $google['nombre'],
                ], ['Todavía no tenés una cuenta. Completá los datos de tu comercio.'], 404);
            }
            $d['nombre'] = $d['nombre'] ?? $google['nombre'];
            if ($errores = $registro->validar($d + ['email' => $google['email']], false)) {
                return $this->respuesta(400, [], $errores, 400);
            }
            try {
                $registro->registrarConGoogle($google, $d);
            } catch (\DomainException $e) {
                return $this->respuesta(409, [], [$e->getMessage()], 409);
            } catch (\Throwable $th) {
                error_log('Registro Google: ' . $th->getMessage());
                return $this->respuesta(500, [], ['No pudimos crear la cuenta. Probá de nuevo en unos minutos.'], 500);
            }
        } elseif ((int) $existente['eliminado'] !== 1) {
            // Mismo email que una cuenta con clave: se vincula (Google confirma que el email es suyo).
            $cuentas->vincularGoogle((int) $existente['id'], $google['sub']);
        }

        try {
            $usuario = $provider->loadUserByIdentifier($google['email']);
            $checker->checkPreAuth($usuario);
        } catch (\Throwable $th) {
            return $this->respuesta(401, [], [$th->getMessage()], 401);
        }
        $auditoria->registrar('login', ['via' => 'google'], $usuario->getIdNegocio(), $usuario->getId());
        // Misma respuesta (token + datos del usuario) que el login con clave.
        return $exito->handleAuthenticationSuccess($usuario);
    }
}
