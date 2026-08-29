<?php

namespace App\Controller;

use App\Service\NotificacionesService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/notificaciones')]
class NotificacionesController extends BaseController
{
    #[Route('/{id}/leida', name: 'app_notificacion_marcar_leida', methods: ['PATCH'])]
    public function marcarLeida(int $id, NotificacionesService $notificaciones_service): JsonResponse
    {
        $propietario = $notificaciones_service->obtenerPropietario($id);
        if ($propietario === null) {
            return $this->respuesta(404, [], ['Notificación no encontrada'], 404);
        }

        $idNegocioUsuario = $this->idNegocioUsuarioActual();
        $idUsuario = $this->idUsuarioActual();
        $esBroadcast = (int) $propietario['usuario_id'] === 0;
        $esPropia = (int) $propietario['usuario_id'] === $idUsuario;

        // Solo se puede marcar como leída una notificación del propio negocio,
        // y que sea puntual del usuario o un broadcast (usuario_id = 0).
        if ((int) $propietario['id_negocio'] !== $idNegocioUsuario || (!$esBroadcast && !$esPropia)) {
            return $this->respuesta(403, [], ['No tiene permisos sobre esta notificación.'], 403);
        }

        try {
            $ok = $notificaciones_service->marcarLeida($id);
            if (!$ok) {
                return $this->respuesta(400, [], ['No se pudo marcar la notificación como leída'], 400);
            }
            return $this->respuesta(200, ['OK' => 'OK'], []);
        } catch (\Throwable $th) {
            return $this->respuesta(500, [], ['Error al marcar la notificación como leída'], 500);
        }
    }
}
