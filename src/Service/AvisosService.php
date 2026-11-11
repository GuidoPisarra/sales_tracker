<?php

namespace App\Service;

use App\Repository\ProductsRepository;
use App\Repository\SalesProductRepository;

/**
 * Avisos que se muestran arriba del dashboard (Inicio) del front.
 * Cada aviso: { id, titulo, mensaje, tipo } con tipo info|exito|advertencia|error.
 *
 * El front guarda en el dispositivo los ids que el usuario cerró, así que el id
 * incluye la fecha y el número: el aviso vuelve a aparecer al día siguiente o
 * si cambia la cantidad, pero no a cada rato una vez cerrado.
 */
class AvisosService
{
    private $rep_products;
    private $preciosService;
    private $rep_sales;

    public function __construct(ProductsRepository $rep_products, PreciosService $preciosService, SalesProductRepository $rep_sales)
    {
        $this->rep_products = $rep_products;
        $this->preciosService = $preciosService;
        $this->rep_sales = $rep_sales;
    }

    public function obtenerAvisos(int $idNegocio, bool $esTitular): array
    {
        $avisos = [];
        $hoy = (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');

        $sinStock = $this->rep_products->contarSinStock($idNegocio);
        if ($sinStock > 0) {
            $avisos[] = [
                'id' => $this->idAviso('sin_stock', $hoy, $sinStock),
                'titulo' => 'Productos sin stock',
                'mensaje' => $sinStock === 1
                    ? 'Hay 1 producto sin stock.'
                    : "Hay {$sinStock} productos sin stock.",
                'tipo' => 'advertencia',
            ];
        }

        // Ventas confirmadas sin stock (o hechas sin conexión) que dejaron el stock en 0.
        if ($esTitular) {
            $faltantes = $this->rep_sales->contarFaltantes($idNegocio);
            if ($faltantes > 0) {
                $avisos[] = [
                    'id' => $this->idAviso('stock_faltante', $hoy, $faltantes),
                    'titulo' => 'Stock a revisar',
                    'mensaje' => $faltantes === 1
                        ? 'Se vendió 1 producto que según el sistema no tenía stock. Revisalo en Stock.'
                        : "Se vendieron {$faltantes} productos que según el sistema no tenían stock. Revisalos en Stock.",
                    'tipo' => 'advertencia',
                    'ruta' => '/stock-a-revisar',
                ];
            }
        }

        // Solo el administrador puede actualizar precios, así que solo a él le sirve el aviso.
        if ($esTitular) {
            $desactualizados = $this->preciosService->contarPreciosDesactualizados($idNegocio);
            if ($desactualizados > 0) {
                $avisos[] = [
                    'id' => $this->idAviso('precios', $hoy, $desactualizados),
                    'titulo' => 'Precios para revisar',
                    'mensaje' => $desactualizados === 1
                        ? 'Hay 1 producto con el precio desactualizado.'
                        : "Hay {$desactualizados} productos con el precio desactualizado.",
                    'tipo' => 'info',
                ];
            }
        }

        return $avisos;
    }

    private function idAviso(string $tipo, string $fecha, int $cantidad): int
    {
        return crc32($tipo . '|' . $fecha . '|' . $cantidad);
    }
}
