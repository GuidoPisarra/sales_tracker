<?php

namespace App\Controller;

use App\Model\Usuario;
use App\Repository\PasskeyRepository;
use App\Security\UserChecker;
use App\Security\UsuariosProvider;
use App\Service\AuditoriaService;
use App\Service\PasskeyService;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ingreso con Face ID / huella desde el navegador (llaves de acceso). Para
 * activarlo hay que haber entrado con la contraseña; después se entra sin
 * escribir nada. Ver PasskeyService.
 */
class PasskeyController extends BaseController
{
    private const SIN_ORIGEN = 'El ingreso con Face ID o huella no está disponible desde acá.';

    public function __construct(private PasskeyService $passkeys, private PasskeyRepository $repo)
    {
    }

    #[Route('/api/passkey/opciones', name: 'app_passkey_opciones', methods: ['POST'])]
    public function opciones(Request $request): JsonResponse
    {
        $usuario = $this->getUser();
        $rpId = PasskeyService::rpId($request->headers->get('Origin'));
        if (!$usuario instanceof Usuario || $rpId === null) {
            return $this->respuesta(409, [], [self::SIN_ORIGEN], 409);
        }
        return $this->respuesta(200, $this->passkeys->opcionesRegistro($usuario->getId(), $usuario->get_email(), (string) $usuario->getName(), $rpId), []);
    }

    /** Body: {desafio, clientDataJSON, attestationObject, nombre} (binarios en base64url). */
    #[Route('/api/passkey', name: 'app_passkey_registrar', methods: ['POST'])]
    public function registrar(Request $request, AuditoriaService $auditoria): JsonResponse
    {
        $rpId = PasskeyService::rpId($request->headers->get('Origin'));
        if ($rpId === null) {
            return $this->respuesta(409, [], [self::SIN_ORIGEN], 409);
        }
        try {
            $id = $this->passkeys->registrar((int) $this->idUsuarioActual(), $rpId, json_decode($request->getContent(), true) ?: []);
        } catch (\RuntimeException $e) {
            return $this->respuesta(409, [], [$e->getMessage()], 409);
        }
        $auditoria->registrar('passkey_alta', ['rp_id' => $rpId], $this->idNegocioUsuarioActual(), $this->idUsuarioActual());
        return $this->respuesta(201, ['id' => $id], [], 201);
    }

    #[Route('/api/passkey', name: 'app_passkey_lista', methods: ['GET'])]
    public function lista(): JsonResponse
    {
        return $this->respuesta(200, $this->repo->lista((int) $this->idUsuarioActual()), []);
    }

    #[Route('/api/passkey/{id}', name: 'app_passkey_borrar', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function borrar(int $id): JsonResponse
    {
        if (!$this->repo->borrar($id, (int) $this->idUsuarioActual())) {
            return $this->respuesta(404, [], ['No existe.'], 404);
        }
        return $this->respuesta(200, [], []);
    }

    #[Route('/api/publico/passkey/opciones', name: 'app_passkey_opciones_ingreso', methods: ['POST'])]
    public function opcionesIngreso(Request $request, RateLimiterFactory $passkeyLimiter): JsonResponse
    {
        if (!$passkeyLimiter->create($request->getClientIp() ?? 'sin-ip')->consume()->isAccepted()) {
            return $this->respuesta(429, [], ['Demasiados intentos. Probá de nuevo en un rato.'], 429);
        }
        $rpId = PasskeyService::rpId($request->headers->get('Origin'));
        if ($rpId === null) {
            return $this->respuesta(409, [], [self::SIN_ORIGEN], 409);
        }
        return $this->respuesta(200, $this->passkeys->opcionesIngreso($rpId), []);
    }

    /** Body: {desafio, id, clientDataJSON, authenticatorData, signature}. Responde como el login. */
    #[Route('/api/publico/passkey/ingresar', name: 'app_passkey_ingresar', methods: ['POST'])]
    public function ingresar(Request $request, UsuariosProvider $provider, UserChecker $checker, AuthenticationSuccessHandler $exito, AuditoriaService $auditoria, RateLimiterFactory $passkeyLimiter): Response
    {
        if (!$passkeyLimiter->create($request->getClientIp() ?? 'sin-ip')->consume()->isAccepted()) {
            return $this->respuesta(429, [], ['Demasiados intentos. Probá de nuevo en un rato.'], 429);
        }
        $rpId = PasskeyService::rpId($request->headers->get('Origin'));
        if ($rpId === null) {
            return $this->respuesta(409, [], [self::SIN_ORIGEN], 409);
        }
        try {
            $email = $this->passkeys->ingresar($rpId, json_decode($request->getContent(), true) ?: []);
        } catch (\RuntimeException $e) {
            return $this->respuesta(401, [], [$e->getMessage()], 401);
        }
        try {
            $usuario = $provider->loadUserByIdentifier($email);
            $checker->checkPreAuth($usuario);
        } catch (\Throwable $th) {
            return $this->respuesta(401, [], [$th->getMessage()], 401);
        }
        $auditoria->registrar('login', ['via' => 'passkey'], $usuario->getIdNegocio(), $usuario->getId());
        return $exito->handleAuthenticationSuccess($usuario);
    }
}
