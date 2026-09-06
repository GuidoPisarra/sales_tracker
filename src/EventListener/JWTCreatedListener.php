<?php

namespace App\EventListener;

use App\Model\Usuario;
use App\Service\ServicioUsuario;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;

/**
 * Agrega al JWT los claims que hoy no trae por defecto (negocio_id, roles reales),
 * necesarios para que servicios externos (ej. el agente de IA) puedan resolver
 * negocio y permisos sin depender de nada que venga en el body de la request.
 */
class JWTCreatedListener
{
    private $servicioUsuario;

    public function __construct(ServicioUsuario $servicio_usuario)
    {
        $this->servicioUsuario = $servicio_usuario;
    }

    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof Usuario) {
            return;
        }

        $datos = $this->servicioUsuario->get_rol_id_usuario($user->get_email());
        if (!$datos) {
            return;
        }

        $payload = $event->getData();

        if (isset($datos['id_negocio']) && $datos['id_negocio'] !== null) {
            $payload['negocio_id'] = (int) $datos['id_negocio'];
        }

        $roles = json_decode($datos['role'] ?? '', true);
        $payload['roles'] = is_array($roles) && $roles ? $roles : ['ROLE_USER'];

        $event->setData($payload);
    }
}
