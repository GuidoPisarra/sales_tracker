<?php

namespace App\EventListener;

use App\Service\SuscripcionService;
use App\Tenant\TenantContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Aplica el plan en un solo lugar, antes de llegar a los controllers:
 *
 * - Modo solo lectura (prueba vencida, mora pasada la gracia, cancelada): se
 *   puede consultar y exportar, pero no cargar ni vender. Responde 402.
 * - Límites del plan al crear sucursales, usuarios y productos, y al usar el
 *   asistente de IA. Responde 402 con codigo "limite_plan".
 * - Cuentas corrientes solo en los planes que las incluyen.
 *
 * El front traduce el 402 a "Mejorá tu plan". El back es la única fuente de verdad.
 */
class SuscripcionListener implements EventSubscriberInterface
{
    /** Rutas que crean un recurso con límite => recurso. */
    private const LIMITES = [
        'app_add_negocio' => 'sucursales',
        'app_empleado_crear' => 'usuarios',
        'app_products_add' => 'productos',
        'app_asistente_mensaje' => 'mensajes_ia',
    ];

    /** Prefijos que siguen andando en solo lectura (cuenta, plan, pagos, avisos). */
    private const PERMITIDAS_EN_SOLO_LECTURA = [
        '/api/usuario', '/api/registro', '/api/suscripcion', '/api/webhooks', '/api/planes', '/api/notificaciones',
    ];

    private TenantContext $tenant;
    private SuscripcionService $suscripciones;

    public function __construct(TenantContext $tenant, SuscripcionService $suscripciones)
    {
        $this->tenant = $tenant;
        $this->suscripciones = $suscripciones;
    }

    public static function getSubscribedEvents(): array
    {
        // Después del firewall (prioridad 8), así ya se sabe quién es el usuario.
        return [
            KernelEvents::REQUEST => ['alPedir', 0],
            KernelEvents::RESPONSE => ['alResponder', 0],
        ];
    }

    public function alPedir(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }
        $idNegocio = $this->tenant->idNegocio();
        if ($idNegocio === null) {
            return;
        }

        try {
            $escritura = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
            if ($escritura && !$this->permitidaEnSoloLectura($path) && $this->suscripciones->soloLectura($idNegocio)) {
                $event->setResponse($this->error('solo_lectura', null,
                    'Tu cuenta está en modo solo lectura. Elegí un plan o regularizá el pago para seguir cargando y vendiendo.'));
                return;
            }

            $ruta = (string) $request->attributes->get('_route');
            if (isset(self::LIMITES[$ruta])) {
                $recurso = self::LIMITES[$ruta];
                if ($mensaje = $this->suscripciones->excedeLimite($idNegocio, $recurso)) {
                    $event->setResponse($this->error('limite_plan', $recurso, $mensaje));
                    return;
                }
            }

            if ((str_starts_with($path, '/api/cuenta_corriente') || str_starts_with($path, '/api/clientes'))
                && !$this->suscripciones->incluyeCuentasCorrientes($idNegocio)) {
                $event->setResponse($this->error('limite_plan', 'cuentas_corrientes',
                    'Las cuentas corrientes no están incluidas en tu plan. Mejorá tu plan para usarlas.'));
            }
        } catch (\Throwable $th) {
            // Si falla el control del plan (por ejemplo, base sin migrar) no se corta el servicio.
            error_log('SuscripcionListener: ' . $th->getMessage());
        }
    }

    public function alResponder(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()
            || $event->getRequest()->attributes->get('_route') !== 'app_asistente_mensaje'
            || !$event->getResponse()->isSuccessful()) {
            return;
        }
        try {
            if ($id = $this->tenant->idNegocio()) {
                $this->suscripciones->registrarMensajeIa($id);
            }
        } catch (\Throwable $th) {
            error_log('Uso IA: ' . $th->getMessage());
        }
    }

    private function permitidaEnSoloLectura(string $path): bool
    {
        foreach (self::PERMITIDAS_EN_SOLO_LECTURA as $prefijo) {
            if (str_starts_with($path, $prefijo)) {
                return true;
            }
        }
        return false;
    }

    private function error(string $codigo, ?string $recurso, string $mensaje): JsonResponse
    {
        return new JsonResponse([
            'estado' => 402,
            'errores' => [$mensaje],
            'datos' => ['codigo' => $codigo, 'recurso' => $recurso],
        ], 402);
    }
}
