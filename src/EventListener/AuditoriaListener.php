<?php

namespace App\EventListener;

use App\Service\AuditoriaService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Registra en la tabla auditoria las acciones sensibles que terminaron bien,
 * sin tener que tocar cada controller. Solo guarda ids y parámetros de la ruta,
 * nunca el body completo (puede traer claves).
 */
class AuditoriaListener implements EventSubscriberInterface
{
    private const ACCIONES = [
        'app_products_price_percent' => 'aumento_precios_masivo',
        'app_products_price_percent_token' => 'aumento_precios_masivo',
        'app_products_del' => 'borrado_producto',
        'app_delete_salesProduct' => 'anulacion_venta',
        'app_type_payment_salesProduct' => 'cambio_medio_pago',
        'app_anular_pago' => 'anulacion_pago_ctacte',
        'app_anular_venta_cta_cte' => 'anulacion_venta_ctacte',
        'app_empleado_crear' => 'alta_empleado',
        'app_empleado_actualizar' => 'cambio_empleado',
        'app_delete_employee' => 'baja_empleado',
        'app_add_negocio' => 'alta_sucursal',
        'app_delete_negocio' => 'baja_sucursal',
    ];

    private AuditoriaService $auditoria;

    public function __construct(AuditoriaService $auditoria)
    {
        $this->auditoria = $auditoria;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['alResponder', -10]];
    }

    public function alResponder(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $ruta = (string) $request->attributes->get('_route');
        if (!$event->isMainRequest() || !isset(self::ACCIONES[$ruta]) || !$event->getResponse()->isSuccessful()) {
            return;
        }
        $detalle = array_filter(
            $request->attributes->get('_route_params', []),
            fn ($v, $k) => !str_starts_with((string) $k, '_'),
            ARRAY_FILTER_USE_BOTH
        );
        $body = json_decode((string) $request->getContent(), true);
        if (is_array($body)) {
            foreach (['id', 'id_sale', 'idSale', 'sucursal', 'typePayment', 'email'] as $clave) {
                if (isset($body[$clave]) && is_scalar($body[$clave])) {
                    $detalle[$clave] = $body[$clave];
                }
            }
        }
        $this->auditoria->registrar(self::ACCIONES[$ruta], $detalle);
    }
}
