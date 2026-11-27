<?php

namespace App\Controller;

use App\Service\DestacadosService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/** Tarjetas "Para vos" del Inicio (ver DestacadosService). ?sucursal=N */
#[Route('/api/destacados')]
class DestacadosController extends BaseController
{
    #[Route('', name: 'app_destacados', methods: ['GET'])]
    public function listar(Request $request, DestacadosService $destacados): JsonResponse
    {
        $valor = $request->query->get('sucursal');
        $sucursal = is_numeric($valor) ? (int) $valor : (int) ($this->getUser()?->get_sucursal() ?: 1);
        if ($error = $this->sucursalPermitida($sucursal)) {
            return $error;
        }
        try {
            return $this->respuesta(200, $destacados->tarjetas((int) $this->idNegocioUsuarioActual(), $sucursal, $this->esTitular()), []);
        } catch (\Throwable $th) {
            error_log('destacados: ' . $th->getMessage());
            return $this->respuesta(200, [], []);
        }
    }
}
