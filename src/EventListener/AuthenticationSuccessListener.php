<?php

namespace App\EventListener;

use App\Service\AppLogs;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Service\NotificacionesService;
use App\Service\PlanService;
use App\Service\PreciosService;
use App\Service\ServicioUsuario;
use Symfony\Component\HttpFoundation\JsonResponse;

class AuthenticationSuccessListener
{
    private const ROL_ADMIN = 'ROLE_TITULAR';

    private $requestStack;
    private $servicioUsuario;
    private $logs;
    private $notificacionesService;
    private $planService;
    private $preciosService;

    public function __construct(RequestStack $requestStack, ServicioUsuario $servicio_usuario, AppLogs $logs, NotificacionesService $notificaciones_service, PlanService $plan_service, PreciosService $precios_service)
    {
        $this->requestStack = $requestStack;
        $this->servicioUsuario = $servicio_usuario;
        $this->logs =  $logs;
        $this->notificacionesService = $notificaciones_service;
        $this->planService = $plan_service;
        $this->preciosService = $precios_service;
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

        // Con PDO en modo excepción, un error en algo accesorio (último
        // ingreso, notificaciones, plan, conteo de precios) ya no puede dejar a
        // nadie sin poder entrar: se registra en el log y se sigue.
        $this->intentar('actualizar último ingreso', fn () => $this->servicioUsuario->actualizarUltimoIngreso($user->get_email()));

        $notificaciones = $this->intentar('obtener notificaciones', fn () => $this->notificacionesService->obtenerPorUsuario(
            (int) $datos_usuario['id'],
            (int) $datos_usuario['id_negocio']
        ), []);

        $plan = $this->intentar('obtener plan', fn () => $this->planService->obtenerPorNegocio((int) $datos_usuario['id_negocio']));

        // El conteo de precios desactualizados solo tiene sentido para quien puede hacer algo
        // con ese dato (dueño/admin del negocio) — para un empleado queda en null.
        $roles = json_decode($datos_usuario['role'] ?? '', true);
        $esAdmin = is_array($roles) && in_array(self::ROL_ADMIN, $roles, true);
        $preciosDesactualizados = $esAdmin
            ? $this->intentar('contar precios desactualizados', fn () => $this->preciosService->contarPreciosDesactualizados((int) $datos_usuario['id_negocio']))
            : null;

        $data = [
            'token' => $data['token'],
            'id' => $datos_usuario['id'],
            'rol' => $datos_usuario['role'],
            'id_negocio' => $datos_usuario['id_negocio'],
            'sucursal' => $datos_usuario['sucursal'],
            'nombre' => $datos_usuario['name'],
            'notificaciones' => $notificaciones,
            'plan' => $plan,
            'asistente_ia' => (bool) $datos_usuario['asistente_ia'],
            'precios_desactualizados' => $preciosDesactualizados
        ];

        $event->setData($data);
    }

    /**
     * Ejecuta un paso accesorio del login. Si falla devuelve $siFalla y deja
     * el detalle en el log del servidor.
     */
    private function intentar(string $paso, callable $accion, mixed $siFalla = null): mixed
    {
        try {
            return $accion();
        } catch (\Throwable $th) {
            error_log('Login: no se pudo ' . $paso . ': ' . $th->getMessage());
            return $siFalla;
        }
    }
}
