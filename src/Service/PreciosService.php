<?php

namespace App\Service;

use App\Repository\PreciosRepository;

class PreciosService
{
    private const POR_PAGINA = 10;

    private $rep_precios;
    private $dolarService;
    private $inflacionService;

    public function __construct(PreciosRepository $rep_precios, DolarService $dolarService, InflacionService $inflacionService)
    {
        $this->rep_precios = $rep_precios;
        $this->dolarService = $dolarService;
        $this->inflacionService = $inflacionService;
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

            $salePrice = (float) $producto['sale_price'];
            $costPrice = (float) $producto['cost_price'];
            // Usa el cost_price de HOY como aproximación del margen que tenía al momento de la
            // última actualización (no guardamos histórico de costo).
            $margenActual = $costPrice > 0 ? round(($salePrice - $costPrice) / $costPrice, 4) : null;

            $inflacionAcumulada = $this->inflacionService->obtenerInflacionAcumulada($fechaActualizacion);
            $inflacionAcumulada = $inflacionAcumulada !== null ? round($inflacionAcumulada, 4) : null;

            $margenAbsorbido = ($margenActual !== null && $inflacionAcumulada !== null)
                ? $inflacionAcumulada > $margenActual
                : null;

            $resultado[] = [
                'id' => (int) $producto['id'],
                'code' => $producto['code'],
                'description' => $producto['description'],
                'sale_price' => $salePrice,
                'fecha_actualizado' => $producto['fecha_actualizado'],
                'dolar_actualizacion' => $dolarActualizacion,
                'dolar_hoy' => $dolarHoy,
                'precio_sugerido' => $precioSugerido,
                'inflacion_acumulada' => $inflacionAcumulada,
                'margen_actual' => $margenActual,
                'margen_absorbido' => $margenAbsorbido,
            ];
        }

        return ['total' => $total, 'productos' => $resultado];
    }
}
