<?php

namespace App\Service;

use App\Repository\PreciosRepository;

class PreciosService
{
    private const POR_PAGINA = 10;

    private $rep_precios;
    private $dolarService;

    public function __construct(PreciosRepository $rep_precios, DolarService $dolarService)
    {
        $this->rep_precios = $rep_precios;
        $this->dolarService = $dolarService;
    }

    public function obtenerPreciosDesactualizados(int $idNegocio, int $page = 1): array
    {
        $timezone = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $fechaLimite = (new \DateTime('now', $timezone))->modify('-2 months')->format('Y-m-d H:i:s');

        $page = max(1, $page);
        $offset = ($page - 1) * self::POR_PAGINA;

        $total = $this->rep_precios->contarDesactualizados($idNegocio, $fechaLimite);
        $productos = $this->rep_precios->obtenerDesactualizados($idNegocio, $fechaLimite, self::POR_PAGINA, $offset);

        if (!$productos) {
            return ['total' => $total, 'productos' => []];
        }

        $dolarHoy = $this->dolarService->obtenerCotizacionHoy();

        $resultado = [];
        foreach ($productos as $producto) {
            $fechaActualizacion = (new \DateTime($producto['fecha_actualizado'], $timezone))->format('Y-m-d');
            $dolarActualizacion = $this->dolarService->obtenerCotizacionEnFecha($fechaActualizacion);

            $precioSugerido = null;
            if ($dolarHoy !== null && $dolarActualizacion !== null && $dolarActualizacion > 0) {
                $precioSugerido = round(((float) $producto['sale_price']) * ($dolarHoy / $dolarActualizacion), 2);
            }

            $resultado[] = [
                'id' => (int) $producto['id'],
                'code' => $producto['code'],
                'description' => $producto['description'],
                'sale_price' => (float) $producto['sale_price'],
                'fecha_actualizado' => $producto['fecha_actualizado'],
                'dolar_actualizacion' => $dolarActualizacion,
                'dolar_hoy' => $dolarHoy,
                'precio_sugerido' => $precioSugerido,
            ];
        }

        return ['total' => $total, 'productos' => $resultado];
    }
}
