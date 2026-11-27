<?php

namespace App\EventListener;

use App\Service\DemoService;
use App\Tenant\TenantContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En el negocio de ejemplo se puede vender, cargar y contar, pero no tocar la
 * cuenta, el plan, los empleados, las sucursales ni usar la IA o subir fotos:
 * lo comparte todo el que prueba y se reinicia cada noche. Responde 403.
 */
class DemoListener implements EventSubscriberInterface
{
    /** Prefijos que aceptan cambios en el negocio de ejemplo. */
    private const PERMITIDAS = [
        '/api/salesProduct', '/api/changes', '/api/conteo', '/api/caja/abrir', '/api/caja/cerrar',
        '/api/caja/movimiento', '/api/expenses', '/api/preferencias', '/api/cuenta_corriente', '/api/clientes',
        '/api/proveedores', '/api/escaneo', '/api/notificaciones', '/api/publico',
        '/api/products/products', '/api/products/editOneProduct', '/api/products/rapido', '/api/products/por_ids',
    ];

    public function __construct(private TenantContext $tenant)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Después del firewall (prioridad 8), así ya se sabe quién es el usuario.
        return [KernelEvents::REQUEST => ['alPedir', 1]];
    }

    public function alPedir(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/') || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $usuario = $this->tenant->usuario();
        if (!$usuario || !DemoService::esDemo($usuario->get_email())) {
            return;
        }
        foreach (self::PERMITIDAS as $prefijo) {
            if (str_starts_with($path, $prefijo)) {
                return;
            }
        }
        $event->setResponse(new JsonResponse([
            'estado' => 403,
            'errores' => ['Esto no se puede hacer en la tienda de ejemplo. Creá tu cuenta gratis para usarlo.'],
            'datos' => ['codigo' => 'demo'],
        ], 403));
    }
}
