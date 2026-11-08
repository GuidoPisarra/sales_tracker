<?php

namespace App\Controller;

use App\Service\PreciosService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/precios')]
class PreciosController extends BaseController
{
    #[Route('/desactualizados', name: 'app_precios_desactualizados_token', defaults: ['id_negocio' => null], methods: ['GET'])]
    #[Route('/desactualizados/{id_negocio}', name: 'app_precios_desactualizados', requirements: ['id_negocio' => '\d+'], methods: ['GET'])]
    public function desactualizados(?int $id_negocio, Request $request, PreciosService $precios_service): JsonResponse
    {
        $id_negocio ??= $this->idNegocioUsuarioActual();
        if ($check = $this->soloTitular()) {
            return $check;
        }
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        $page = max(1, (int) $request->query->get('page', 1));

        try {
            $resultado = $precios_service->obtenerPreciosDesactualizados($id_negocio, $page);
            return $this->respuesta(200, $resultado, []);
        } catch (\Throwable $th) {
            return $this->respuesta(500, [], ['Error al obtener los precios desactualizados'], 500);
        }
    }
}
