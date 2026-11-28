<?php

namespace App\Controller;

use App\Repository\NegocioConfigRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ajustes de mostrador: datos del ticket de venta y stock mínimo general.
 * Los ve cualquiera que vende (para imprimir el ticket); los cambia el administrador.
 */
#[Route('/api/mostrador')]
class MostradorController extends BaseController
{
    #[Route('/config', name: 'app_mostrador_config', methods: ['GET'])]
    public function config(NegocioConfigRepository $repo): JsonResponse
    {
        $idNegocio = (int) $this->idNegocioUsuarioActual();
        return $this->respuesta(200, $this->formato($repo, $idNegocio), []);
    }

    #[Route('/config', name: 'app_mostrador_config_guardar', methods: ['POST'])]
    public function guardar(Request $request, NegocioConfigRepository $repo): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $idNegocio = (int) $this->idNegocioUsuarioActual();
        $d = json_decode($request->getContent(), true) ?: [];
        $campos = [];
        foreach (['ticket_titulo' => 120, 'ticket_direccion' => 200, 'ticket_telefono' => 40, 'ticket_pie' => 300, 'fiado_mensaje' => 500] as $c => $max) {
            if (array_key_exists($c, $d)) {
                $v = trim((string) $d[$c]);
                $campos[$c] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }
        if (array_key_exists('ticket_ancho', $d)) {
            $campos['ticket_ancho'] = (int) $d['ticket_ancho'] === 80 ? 80 : 58;
        }
        if (array_key_exists('stock_minimo_general', $d)) {
            if (!is_numeric($d['stock_minimo_general']) || (int) $d['stock_minimo_general'] < 0) {
                return $this->respuesta(400, [], ['El stock mínimo tiene que ser un número de 0 o más.'], 400);
            }
            $campos['stock_minimo_general'] = min(100000, (int) $d['stock_minimo_general']);
        }
        $repo->guardarConfig($idNegocio, $campos);
        return $this->respuesta(200, $this->formato($repo, $idNegocio), []);
    }

    private function formato(NegocioConfigRepository $repo, int $idNegocio): array
    {
        $c = $repo->config($idNegocio);
        return [
            'nombre_negocio' => $repo->nombreNegocio($idNegocio, 1),
            'ticket_titulo' => $c['ticket_titulo'] ?? null,
            'ticket_direccion' => $c['ticket_direccion'] ?? null,
            'ticket_telefono' => $c['ticket_telefono'] ?? null,
            'ticket_pie' => $c['ticket_pie'] ?? null,
            'ticket_ancho' => (int) ($c['ticket_ancho'] ?? 58) === 80 ? 80 : 58,
            'stock_minimo_general' => (int) ($c['stock_minimo_general'] ?? 3),
            'fiado_mensaje' => ($c['fiado_mensaje'] ?? null) ?: FiadoController::MENSAJE_PREDETERMINADO,
        ];
    }
}
