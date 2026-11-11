<?php

namespace App\Controller;

use App\Service\EscaneoService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Escanear productos con el celular desde Caja (ver EscaneoService).
 * Las rutas de /api/escaneo/sesiones son de la PC (con token); las de
 * /api/escaneo/movil son públicas: el celular se identifica con la clave que
 * recibe al vincularse (ver access_control en security.yaml).
 */
#[Route('/api/escaneo')]
class EscaneoController extends BaseController
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

    /** La PC abre una sesión: devuelve el token para armar el QR de vinculación. */
    #[Route('/sesiones', name: 'app_escaneo_crear', methods: ['POST'])]
    public function crear(Request $request, EscaneoService $escaneo): JsonResponse
    {
        $sucursal = $this->datos($request)['sucursal'] ?? null;
        if (!is_numeric($sucursal)) {
            return $this->respuesta(400, [], ['Falta la sucursal.'], 400);
        }
        if ($check = $this->sucursalPermitida($sucursal)) {
            return $check;
        }
        try {
            $sesion = $escaneo->crear($this->idNegocioUsuarioActual(), (int) $sucursal, $this->idUsuarioActual());
            return $this->respuesta(201, $sesion, [], 201);
        } catch (\Throwable $th) {
            error_log('app_escaneo_crear: ' . $th->getMessage());
            return $this->respuesta(500, [], ['No se pudo abrir el escaneo con el celular.'], 500);
        }
    }

    /** Estado de la sesión y códigos con id mayor a ?desde= (la PC lo pide cada segundo). */
    #[Route('/sesiones/{id}', name: 'app_escaneo_estado', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function estado(int $id, Request $request, EscaneoService $escaneo): JsonResponse
    {
        $estado = $escaneo->estadoParaPc($id, $this->idUsuarioActual(), (int) $request->query->get('desde', 0));
        if ($estado === null) {
            return $this->respuesta(404, [], ['Sesión de escaneo no encontrada.'], 404);
        }
        return $this->respuesta(200, $estado, []);
    }

    #[Route('/sesiones/{id}/cerrar', name: 'app_escaneo_cerrar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cerrar(int $id, EscaneoService $escaneo): JsonResponse
    {
        if (!$escaneo->cerrar($id, $this->idUsuarioActual())) {
            return $this->respuesta(404, [], ['Sesión de escaneo no encontrada.'], 404);
        }
        return $this->respuesta(200, ['estado' => 'cerrada'], []);
    }

    /** Público: el celular canjea el token del QR por su clave. */
    #[Route('/movil/vincular', name: 'app_escaneo_vincular', methods: ['POST'])]
    public function vincular(Request $request, EscaneoService $escaneo, RateLimiterFactory $escaneoVincularLimiter): JsonResponse
    {
        if ($r = $this->limiteSuperado($escaneoVincularLimiter, $request)) {
            return $r;
        }
        $token = $this->datos($request)['token'] ?? null;
        if (!EscaneoService::conForma($token)) {
            return $this->respuesta(400, [], ['El link para conectar el celular no es válido.'], 400);
        }
        $clave = $escaneo->vincular($token, $request->headers->get('User-Agent'));
        if ($clave === null) {
            return $this->respuesta(410, [], ['Este QR ya se usó o venció. Generá uno nuevo en Caja con "Escanear con el celular".'], 410);
        }
        return $this->respuesta(200, ['clave' => $clave, 'minutos' => EscaneoService::MINUTOS_SESION], []);
    }

    /** Público: estado de la sesión visto desde el celular. */
    #[Route('/movil/estado', name: 'app_escaneo_estado_movil', methods: ['POST'])]
    public function estadoMovil(Request $request, EscaneoService $escaneo): JsonResponse
    {
        $sesion = $this->sesionDelCelular($request, $escaneo);
        if ($sesion instanceof JsonResponse) {
            return $sesion;
        }
        return $this->respuesta(200, [
            'estado' => EscaneoService::estado($sesion),
            'codigos' => $escaneo->cantidadCodigos((int) $sesion['id']),
        ], []);
    }

    /** Público: el celular manda un código leído con la cámara. */
    #[Route('/movil/codigo', name: 'app_escaneo_codigo', methods: ['POST'])]
    public function codigo(Request $request, EscaneoService $escaneo, RateLimiterFactory $escaneoCodigoLimiter): JsonResponse
    {
        if ($r = $this->limiteSuperado($escaneoCodigoLimiter, $request)) {
            return $r;
        }
        $sesion = $this->sesionDelCelular($request, $escaneo);
        if ($sesion instanceof JsonResponse) {
            return $sesion;
        }
        $estado = EscaneoService::estado($sesion);
        if ($estado !== 'vinculada') {
            $mensaje = $estado === 'cerrada' ? 'Se desconectó el celular desde Caja.' : 'La sesión venció. Volvé a conectar el celular desde Caja.';
            return $this->respuesta(410, ['estado' => $estado], [$mensaje], 410);
        }
        $codigo = trim((string) ($this->datos($request)['codigo'] ?? ''));
        if ($codigo === '' || mb_strlen($codigo) > EscaneoService::LARGO_MAXIMO_CODIGO || preg_match('/[\x00-\x1F\x7F]/', $codigo)) {
            return $this->respuesta(422, [], ['Ese código no parece de un producto.'], 422);
        }
        try {
            $r = $escaneo->agregarCodigo($sesion, $codigo);
        } catch (\Throwable $th) {
            error_log('app_escaneo_codigo: ' . $th->getMessage());
            return $this->respuesta(500, [], ['No se pudo mandar el código. Probá de nuevo.'], 500);
        }
        if (!$r['guardado']) {
            return $this->respuesta(409, [], ['Se llegó al máximo de códigos de esta sesión. Volvé a conectar el celular desde Caja.'], 409);
        }
        return $this->respuesta(200, ['codigo' => $codigo, 'producto' => $r['producto']], []);
    }

    private function sesionDelCelular(Request $request, EscaneoService $escaneo): array|JsonResponse
    {
        $clave = $this->datos($request)['clave'] ?? null;
        $sesion = EscaneoService::conForma($clave) ? $escaneo->sesionDelCelular($clave) : null;
        if (!$sesion) {
            // 403 y no 401: en el front un 401 cierra la sesión de Kontia, y el celular puede tener una abierta.
            return $this->respuesta(403, [], ['El celular no está conectado a Caja. Escaneá el QR de Caja de nuevo.'], 403);
        }
        return $sesion;
    }
}
