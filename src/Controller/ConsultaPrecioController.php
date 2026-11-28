<?php

namespace App\Controller;

use App\Service\ConsultaPrecioService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * "Consultá el precio" (ver ConsultaPrecioService). La configuración es del
 * administrador; /api/publico/precio/... se ve sin sesión.
 */
class ConsultaPrecioController extends BaseController
{
    #[Route('/api/consulta-precio/config', name: 'app_consulta_precio_config', methods: ['GET'])]
    public function config(ConsultaPrecioService $precios): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        return $this->respuesta(200, $precios->config((int) $this->idNegocioUsuarioActual(), $this->tenant()->sucursales()), []);
    }

    #[Route('/api/consulta-precio/config', name: 'app_consulta_precio_guardar', methods: ['POST'])]
    public function guardar(Request $request, ConsultaPrecioService $precios): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $d = json_decode($request->getContent(), true) ?: [];
        $r = $precios->guardar((int) $this->idNegocioUsuarioActual(), $d, $this->tenant()->sucursales());
        if (is_string($r)) {
            return $this->respuesta(409, [], [$r], 409);
        }
        return $this->respuesta(200, $r, []);
    }

    #[Route('/api/publico/precio/{slug}', name: 'app_consulta_precio_publico', methods: ['GET'])]
    public function publico(string $slug, Request $request, ConsultaPrecioService $precios, RateLimiterFactory $consultaPrecioLimiter): JsonResponse
    {
        if ($r = $this->limite($request, $consultaPrecioLimiter)) {
            return $r;
        }
        $c = $precios->publico($slug);
        if (!$c) {
            return $this->respuesta(404, [], ['Esta consulta de precios no existe o no está disponible.'], 404);
        }
        return $this->respuesta(200, $c, [], 200, ['Cache-Control' => 'public, max-age=60']);
    }

    #[Route('/api/publico/precio/{slug}/{codigo}', name: 'app_consulta_precio_producto', methods: ['GET'], requirements: ['codigo' => '.+'])]
    public function producto(string $slug, string $codigo, Request $request, ConsultaPrecioService $precios, RateLimiterFactory $consultaPrecioLimiter): JsonResponse
    {
        if ($r = $this->limite($request, $consultaPrecioLimiter)) {
            return $r;
        }
        $p = $precios->producto($slug, $codigo);
        if ($p === null) {
            return $this->respuesta(404, [], ['Esta consulta de precios no existe o no está disponible.'], 404);
        }
        if (!empty($p['no_encontrado'])) {
            return $this->respuesta(404, ['no_encontrado' => true], ['No encontramos ese producto. Preguntale a quien atiende.'], 404);
        }
        return $this->respuesta(200, $p, [], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/publico/precio/{slug}/carrito', name: 'app_consulta_precio_carrito', methods: ['POST'], priority: 10)]
    public function carrito(string $slug, Request $request, ConsultaPrecioService $precios, RateLimiterFactory $carritoClienteLimiter): JsonResponse
    {
        if ($r = $this->limite($request, $carritoClienteLimiter)) {
            return $r;
        }
        $d = json_decode($request->getContent(), true) ?: [];
        $r = $precios->crearCarrito($slug, is_array($d['items'] ?? null) ? $d['items'] : []);
        if ($r === null) {
            return $this->respuesta(404, [], ['Esta consulta de precios no existe o no está disponible.'], 404);
        }
        if (is_string($r)) {
            return $this->respuesta(409, [], [$r], 409);
        }
        return $this->respuesta(200, $r, []);
    }

    /** Caja (cualquier usuario del negocio) trae el carrito por código o QR. */
    #[Route('/api/carrito-cliente/{valor}', name: 'app_carrito_cliente', methods: ['GET'])]
    public function carritoCaja(string $valor, ConsultaPrecioService $precios): JsonResponse
    {
        $c = $precios->carritoParaCaja((int) $this->idNegocioUsuarioActual(), $valor);
        if (!$c) {
            return $this->respuesta(404, [], ['No hay ningún carrito con ese código. Los carritos duran ' . \App\Repository\CarritoClienteRepository::HORAS . ' horas.'], 404);
        }
        return $this->respuesta(200, $c, []);
    }

    private function limite(Request $request, RateLimiterFactory $limiter): ?JsonResponse
    {
        if (!$limiter->create($request->getClientIp() ?? 'sin-ip')->consume()->isAccepted()) {
            return $this->respuesta(429, [], ['Demasiadas consultas. Probá de nuevo en un minuto.'], 429);
        }
        return null;
    }
}
