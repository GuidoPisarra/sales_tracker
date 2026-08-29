<?php

namespace App\EventListener;

use App\Service\AppLogs;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Service\NotificacionesService;
use App\Service\ServicioUsuario;
use Symfony\Component\HttpFoundation\JsonResponse;

class AuthenticationSuccessListener
{
    private $requestStack;
    private $servicioUsuario;
    private $logs;
    private $notificacionesService;

    public function __construct(RequestStack $requestStack, ServicioUsuario $servicio_usuario, AppLogs $logs, NotificacionesService $notificaciones_service)
    {
        $this->requestStack = $requestStack;
        $this->servicioUsuario = $servicio_usuario;
        $this->logs =  $logs;
        $this->notificacionesService = $notificaciones_service;
    }
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event)
    {
        $data = $event->getData();
        $user = $event->getUser();
        $request = $this->requestStack->getCurrentRequest();
        $body = json_decode($request->getContent());


        if (!$user instanceof UserInterface) {
            return;
        }
        $datos_usuario = $this->servicioUsuario->get_rol_id_usuario($user->get_email());

        $notificaciones = $this->notificacionesService->obtenerPorUsuario(
            (int) $datos_usuario['id'],
            (int) $datos_usuario['id_negocio']
        );

        $data = [
            'token' => $data['token'],
            'id' => $datos_usuario['id'],
            'rol' => $datos_usuario['role'],
            'id_negocio' => $datos_usuario['id_negocio'],
            'sucursal' => $datos_usuario['sucursal'],
            'nombre' => $datos_usuario['name'],
            'notificaciones' => $notificaciones
        ];

        $event->setData($data);
    }
}
