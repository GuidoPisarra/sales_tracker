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
        $usuarioIdNotificacion = $notificaciones_service->obtenerUsuarioId($id);
        if ($usuarioIdNotificacion === null) {
            return $this->respuesta(404, [], ['Notificación no encontrada'], 404);
        }

        $idUsuario = $this->idUsuarioActual();
        $esBroadcast = $usuarioIdNotificacion === 0;
        $esPropia = $usuarioIdNotificacion === $idUsuario;

        // Mismo criterio que obtenerPorUsuario (el listado del login): no se filtra por
        // negocio, solo tiene que ser propia del usuario o un broadcast (usuario_id = 0).
        if (!$esBroadcast && !$esPropia) {
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
