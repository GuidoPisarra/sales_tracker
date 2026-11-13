<?php

namespace App\EventListener;

use App\Service\AuditoriaService;
use App\Service\Entorno;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Monitoreo básico sin servicios externos: cuando la API corta con un error
 * no controlado (500), manda un mail a ADMIN_EMAILS con el error y la ruta.
 * El mismo error se avisa como mucho una vez por hora. AVISAR_ERRORES=0 lo apaga.
 */
#[AsEventListener(event: 'kernel.exception', priority: -10)]
class ErrorAvisoListener
{
    public function __construct(private AuditoriaService $auditoria, private FilesystemAdapter $cache)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return;
        }
        if (Entorno::get('AVISAR_ERRORES', '1') === '0' || Entorno::get('APP_ENV') === 'test') {
            return;
        }
        try {
            $clave = 'error_aviso_' . md5(get_class($e) . $e->getFile() . $e->getLine());
            $item = $this->cache->getItem($clave);
            if ($item->isHit()) {
                return;
            }
            $item->set(1)->expiresAfter(3600);
            $this->cache->save($item);
            $this->auditoria->registrar('error_api', [
                'error' => mb_substr(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 0, 500),
                'ruta' => $event->getRequest()->getMethod() . ' ' . $event->getRequest()->getPathInfo(),
            ]);
        } catch (\Throwable $th) {
            error_log('Aviso de error: ' . $th->getMessage());
        }
    }
}
