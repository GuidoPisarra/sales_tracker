<?php

namespace App\Controller;

use App\Service\AvisosService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/avisos')]
class AvisosController extends BaseController
{
    /**
     * Avisos del dashboard. El front ya llamaba a esta ruta y, como no existía,
     * el error se ignoraba en silencio.
     */
    #[Route('/{id_negocio}', name: 'app_avisos_dashboard', methods: ['GET'])]
    public function listar(int $id_negocio, AvisosService $avisos_service): JsonResponse
    {
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        try {
            $avisos = $avisos_service->obtenerAvisos($id_negocio, $this->esTitular());
            return $this->respuesta(200, $avisos, []);
        } catch (\Throwable $th) {
            error_log('app_avisos_dashboard: ' . $th->getMessage());
            return $this->respuesta(500, [], ['Error al obtener los avisos.'], 500);
        }
    }
}
