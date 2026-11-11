<?php

namespace App\Controller;

use App\Service\CajaService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/** Apertura y cierre de caja (ver CajaService). */
#[Route('/api/caja')]
class CajaController extends BaseController
{
    private function datos(Request $request): array
    {
        return json_decode($request->getContent(), true) ?: [];
    }

    /** Sucursal pedida (o la del usuario), validada contra el usuario. */
    private function sucursal($valor, ?JsonResponse &$error): int
    {
        $sucursal = is_numeric($valor) ? (int) $valor : (int) ($this->getUser()?->get_sucursal() ?: 1);
        $error = $this->sucursalPermitida($sucursal);
        return $sucursal;
    }

    private function resultado($r, int $estadoOk = 200): JsonResponse
    {
        if (is_string($r)) {
            return $this->respuesta(409, [], [$r], 409);
        }
        return $this->respuesta($estadoOk, $r, [], $estadoOk);
    }

    #[Route('/estado', name: 'app_caja_estado', methods: ['GET'])]
    public function estado(Request $request, CajaService $caja): JsonResponse
    {
        $sucursal = $this->sucursal($request->query->get('sucursal'), $error);
        if ($error) {
            return $error;
        }
        return $this->respuesta(200, $caja->estado((int) $this->idNegocioUsuarioActual(), $sucursal), []);
    }

    /** Activa o desactiva la apertura y cierre de caja (solo administrador). */
    #[Route('/config', name: 'app_caja_config', methods: ['POST'])]
    public function config(Request $request, CajaService $caja): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $caja->activar((int) $this->idNegocioUsuarioActual(), !empty($this->datos($request)['activa']));
        return $this->respuesta(200, ['activa' => $caja->activa((int) $this->idNegocioUsuarioActual())], []);
    }

    #[Route('/abrir', name: 'app_caja_abrir', methods: ['POST'])]
    public function abrir(Request $request, CajaService $caja): JsonResponse
    {
        $d = $this->datos($request);
        $sucursal = $this->sucursal($d['sucursal'] ?? null, $error);
        if ($error) {
            return $error;
        }
        if (!is_numeric($d['monto_inicial'] ?? null)) {
            return $this->respuesta(400, [], ['Falta el monto inicial.'], 400);
        }
        return $this->resultado($caja->abrir((int) $this->idNegocioUsuarioActual(), $sucursal, (int) $this->idUsuarioActual(),
            (float) $d['monto_inicial'], $d['observacion'] ?? null), 201);
    }

    #[Route('/movimiento', name: 'app_caja_movimiento', methods: ['POST'])]
    public function movimiento(Request $request, CajaService $caja): JsonResponse
    {
        $d = $this->datos($request);
        $sucursal = $this->sucursal($d['sucursal'] ?? null, $error);
        if ($error) {
            return $error;
        }
        if (!is_numeric($d['monto'] ?? null)) {
            return $this->respuesta(400, [], ['Falta el monto.'], 400);
        }
        return $this->resultado($caja->movimiento((int) $this->idNegocioUsuarioActual(), $sucursal, (int) $this->idUsuarioActual(),
            (string) ($d['tipo'] ?? ''), (float) $d['monto'], $d['motivo'] ?? null));
    }

    #[Route('/cerrar', name: 'app_caja_cerrar', methods: ['POST'])]
    public function cerrar(Request $request, CajaService $caja): JsonResponse
    {
        $d = $this->datos($request);
        $sucursal = $this->sucursal($d['sucursal'] ?? null, $error);
        if ($error) {
            return $error;
        }
        if (!is_numeric($d['contado'] ?? null)) {
            return $this->respuesta(400, [], ['Falta cuánto efectivo contaste.'], 400);
        }
        return $this->resultado($caja->cerrar((int) $this->idNegocioUsuarioActual(), $sucursal, (int) $this->idUsuarioActual(),
            (float) $d['contado'], $d['observacion'] ?? null));
    }

    /** Historial de turnos (solo administrador). ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD */
    #[Route('/turnos', name: 'app_caja_turnos', methods: ['GET'])]
    public function turnos(Request $request, CajaService $caja): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $fecha = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        return $this->respuesta(200, $caja->turnos((int) $this->idNegocioUsuarioActual(),
            $fecha($request->query->get('desde')), $fecha($request->query->get('hasta'))), []);
    }

    #[Route('/turnos/{id}', name: 'app_caja_turno', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function turno(int $id, CajaService $caja): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $turno = $caja->turno($id, (int) $this->idNegocioUsuarioActual());
        return $turno ? $this->respuesta(200, $turno, []) : $this->respuesta(404, [], ['No se encontró ese turno.'], 404);
    }
}
