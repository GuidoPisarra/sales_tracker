<?php

namespace App\Controller;

use App\Repository\CuentaCorrienteRepository;
use App\Repository\NegocioConfigRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/** Fiado: quién debe y recordatorio por WhatsApp. */
#[Route('/api/fiado')]
class FiadoController extends BaseController
{
    public const MENSAJE_PREDETERMINADO = 'Hola {nombre}, te escribimos de {negocio}. Te recordamos que tenés un saldo pendiente de {saldo}. ¡Muchas gracias!';

    #[Route('/deudores', name: 'app_fiado_deudores', methods: ['GET'])]
    public function deudores(CuentaCorrienteRepository $cuentas, NegocioConfigRepository $config): JsonResponse
    {
        $idNegocio = $this->idNegocioUsuarioActual();
        if ($idNegocio === null) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        $zona = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $hoy = new \DateTimeImmutable('today', $zona);
        $lista = array_map(function (array $f) use ($hoy, $zona) {
            // Días sin pagar: desde el último pago, o desde la primera compra si nunca pagó.
            $referencia = $f['ultimo_pago'] ?: $f['primera'];
            $dias = null;
            if ($referencia) {
                $dias = max(0, (int) (new \DateTimeImmutable(substr($referencia, 0, 10), $zona))->diff($hoy)->days);
            }
            return [
                'id' => (int) $f['id'],
                'nombre' => trim(($f['nombre'] ?? '') . ' ' . ($f['apellido'] ?? '')),
                'telefono' => $f['telefono'] ?: null,
                'dni' => $f['dni'] ?: null,
                'saldo' => round((float) $f['saldo'], 2),
                'primera_compra' => $f['primera'],
                'ultima_compra' => $f['ultima'],
                'ultimo_pago' => $f['ultimo_pago'],
                'dias_sin_pagar' => $dias,
                'recordado_en' => $f['recordado_en'],
            ];
        }, $cuentas->deudores($idNegocio));

        $c = $config->config($idNegocio);
        return $this->respuesta(200, [
            'deudores' => $lista,
            'total' => round(array_sum(array_column($lista, 'saldo')), 2),
            'mensaje' => ($c['fiado_mensaje'] ?? null) ?: self::MENSAJE_PREDETERMINADO,
            'negocio' => ($c['ticket_titulo'] ?? null) ?: $config->nombreNegocio($idNegocio, 1),
        ], []);
    }

    #[Route('/recordado/{id}', name: 'app_fiado_recordado', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function recordado(int $id, CuentaCorrienteRepository $cuentas): JsonResponse
    {
        $idNegocio = $this->idNegocioUsuarioActual();
        if ($idNegocio === null) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        $ahora = (new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');
        if (!$cuentas->marcarRecordado($idNegocio, $id, $ahora)) {
            return $this->respuesta(404, [], ['No se encontró el cliente.'], 404);
        }
        return $this->respuesta(200, ['recordado_en' => $ahora], []);
    }
}
