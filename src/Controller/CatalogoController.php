<?php

namespace App\Controller;

use App\Service\CatalogoService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Catálogo online (ver CatalogoService). /api/catalogo/config es del administrador;
 * /api/publico/catalogo/{slug} es pública (ver access_control en security.yaml).
 */
class CatalogoController extends BaseController
{
    #[Route('/api/catalogo/config', name: 'app_catalogo_config', methods: ['GET'])]
    public function config(CatalogoService $catalogo): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        return $this->respuesta(200, $catalogo->config((int) $this->idNegocioUsuarioActual(), $this->tenant()->sucursales()), []);
    }

    #[Route('/api/catalogo/config', name: 'app_catalogo_guardar', methods: ['POST'])]
    public function guardar(Request $request, CatalogoService $catalogo): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $d = json_decode($request->getContent(), true) ?: [];
        $r = $catalogo->guardar((int) $this->idNegocioUsuarioActual(), $d, $this->tenant()->sucursales());
        if (is_string($r)) {
            return $this->respuesta(409, [], [$r], 409);
        }
        return $this->respuesta(200, $r, []);
    }

    #[Route('/api/publico/catalogo/{slug}', name: 'app_catalogo_publico', methods: ['GET'])]
    public function publico(string $slug, Request $request, CatalogoService $catalogo, RateLimiterFactory $catalogoPublicoLimiter): JsonResponse
    {
        if (!$catalogoPublicoLimiter->create($request->getClientIp() ?? 'sin-ip')->consume()->isAccepted()) {
            return $this->respuesta(429, [], ['Demasiadas consultas. Probá de nuevo en un minuto.'], 429);
        }
        $c = $catalogo->publico($slug);
        if (!$c) {
            return $this->respuesta(404, [], ['Este catálogo no existe o no está disponible.'], 404);
        }
        return $this->respuesta(200, $c, [], 200, ['Cache-Control' => 'public, max-age=60']);
    }
}
