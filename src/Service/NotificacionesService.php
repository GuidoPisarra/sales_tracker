<?php

namespace App\Service;

use App\Repository\NotificacionesRepository;

class NotificacionesService
{
    protected $rep_notificaciones;

    public function __construct(NotificacionesRepository $rep_notificaciones)
    {
        $this->rep_notificaciones = $rep_notificaciones;
    }

    public function obtenerPorUsuario(int $idUsuario, int $idNegocio): array
    {
        return $this->rep_notificaciones->obtenerPorUsuario($idUsuario, $idNegocio);
    }
}
