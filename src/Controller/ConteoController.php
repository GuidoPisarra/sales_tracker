<?php

namespace App\Controller;

use App\Service\ConteoService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Conteo de stock desde el celular (ver ConteoService). Cualquiera del negocio manda
 * conteos y ve los suyos; el administrador ve todos y los aplica o descarta.
 */
#[Route('/api/conteo')]
class ConteoController extends BaseController
{
    private function resultado($r, int $estadoOk = 200): JsonResponse
    {
        if (is_string($r)) {
            return $this->respuesta(409, [], [$r], 409);
        }
        return $this->respuesta($estadoOk, $r, [], $estadoOk);
    }

    /** Empleados: solo sus propios conteos. */
    private function soloDe(): ?int
    {
        return $this->esTitular() ? null : (int) $this->idUsuarioActual();
    }

    /** {sucursal, alcance?, observacion?, aplicar?, items: [{id, contado}]} */
    #[Route('', name: 'app_conteo_crear', methods: ['POST'])]
    public function crear(Request $request, ConteoService $conteos): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        $sucursal = is_numeric($d['sucursal'] ?? null) ? (int) $d['sucursal'] : (int) ($this->getUser()?->get_sucursal() ?: 1);
        if ($error = $this->sucursalPermitida($sucursal)) {
            return $error;
        }
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        try {
            return $this->resultado($conteos->crear((int) $this->idNegocioUsuarioActual(), $sucursal, (int) $this->idUsuarioActual(),
                $this->esTitular(), $items, $d['alcance'] ?? null, $d['observacion'] ?? null, !empty($d['aplicar'])), 201);
        } catch (\Throwable $th) {
            error_log('conteo crear: ' . $th->getMessage());
            return $this->respuesta(400, [], ['No se pudo guardar el conteo.'], 400);
        }
    }

    /** ?estado=pendiente|aplicado|descartado */
    #[Route('', name: 'app_conteo_lista', methods: ['GET'])]
    public function lista(Request $request, ConteoService $conteos): JsonResponse
    {
        $estado = $request->query->get('estado');
        return $this->respuesta(200, $conteos->conteos((int) $this->idNegocioUsuarioActual(), $this->soloDe(), is_string($estado) ? $estado : null), []);
    }

    #[Route('/{id}', name: 'app_conteo_ver', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ver(int $id, ConteoService $conteos): JsonResponse
    {
        $c = $conteos->conteo($id, (int) $this->idNegocioUsuarioActual(), $this->soloDe());
        return $c ? $this->respuesta(200, $c, []) : $this->respuesta(404, [], ['No se encontró ese conteo.'], 404);
    }

    #[Route('/{id}/aplicar', name: 'app_conteo_aplicar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function aplicar(int $id, ConteoService $conteos): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        try {
            return $this->resultado($conteos->aplicar($id, (int) $this->idNegocioUsuarioActual(), (int) $this->idUsuarioActual()));
        } catch (\Throwable $th) {
            error_log('conteo aplicar: ' . $th->getMessage());
            return $this->respuesta(400, [], ['No se pudo aplicar el conteo.'], 400);
        }
    }

    #[Route('/{id}/descartar', name: 'app_conteo_descartar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function descartar(int $id, ConteoService $conteos): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        return $this->resultado($conteos->descartar($id, (int) $this->idNegocioUsuarioActual(), (int) $this->idUsuarioActual()));
    }
}
