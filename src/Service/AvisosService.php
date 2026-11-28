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
    /**
     * Novedades de Kontia para mostrar en Inicio. Se ven entre "desde" y "hasta"
     * (inclusive). El id es fijo: si el usuario la cierra, no vuelve a aparecer.
     * Para anunciar algo nuevo, agregar una entrada acá.
     */
    private const NOVEDADES = [
        [
            'clave' => 'catalogo_fotos',
            'desde' => '2026-11-21',
            'hasta' => '2026-12-31',
            'solo_titular' => true,
            'titulo' => 'Novedad: fotos en tu catálogo online',
            'mensaje' => 'Mejoramos el catálogo online: ahora carga rápido aunque tengas miles de productos, y podés '
                . 'agregarles fotos desde Productos → Editar. Tus clientes las ven al entrar a tu catálogo.',
            'tipo' => 'exito',
            'ruta' => '/catalogo-online',
            'texto_ruta' => 'Ver catálogo',
        ],
    ];

    private $rep_products;
    private $preciosService;
    private $rep_sales;

    public function __construct(ProductsRepository $rep_products, PreciosService $preciosService, SalesProductRepository $rep_sales, ?\App\Repository\CuentaCorrienteRepository $rep_cuentas = null)
    {
        $this->rep_cuentas = $rep_cuentas;
        $this->rep_products = $rep_products;
        $this->preciosService = $preciosService;
        $this->rep_sales = $rep_sales;
    }

    private ?\App\Repository\CuentaCorrienteRepository $rep_cuentas;

    public function obtenerAvisos(int $idNegocio, bool $esTitular): array
    {
        $avisos = [];
        $hoy = (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');

        foreach (self::NOVEDADES as $n) {
            if ($hoy < $n['desde'] || $hoy > $n['hasta'] || ($n['solo_titular'] && !$esTitular)) {
                continue;
            }
            $avisos[] = [
                'id' => $this->idAviso('novedad_' . $n['clave'], $n['desde'], 1),
                'titulo' => $n['titulo'],
                'mensaje' => $n['mensaje'],
                'tipo' => $n['tipo'],
                'ruta' => $n['ruta'],
                'texto_ruta' => $n['texto_ruta'],
            ];
        }

        // Productos en el stock mínimo o por debajo (incluye los que se quedaron sin stock).
        $reponer = $this->rep_products->contarParaReponer($idNegocio);
        if ($reponer > 0) {
            $sinStock = $this->rep_products->contarSinStock($idNegocio);
            $detalle = $sinStock > 0 ? ($sinStock === 1 ? ' 1 ya no tiene stock.' : " {$sinStock} ya no tienen stock.") : '';
            $avisos[] = [
                'id' => $this->idAviso('reponer', $hoy, $reponer),
                'titulo' => 'Productos para reponer',
                'mensaje' => ($reponer === 1
                    ? 'Hay 1 producto en el stock mínimo o por debajo.'
                    : "Hay {$reponer} productos en el stock mínimo o por debajo.") . $detalle,
                'tipo' => 'advertencia',
                'ruta' => '/reponer',
                'texto_ruta' => 'Ver lista para reponer',
            ];
        }

        // Fiados: clientes que no pagan hace más de 30 días.
        if ($this->rep_cuentas && $esTitular) {
            $limite = (new \DateTimeImmutable('today', new \DateTimeZone('America/Argentina/Buenos_Aires')))->modify('-30 days')->format('Y-m-d');
            $viejos = count(array_filter($this->rep_cuentas->deudores($idNegocio), function ($f) use ($limite) {
                $referencia = $f['ultimo_pago'] ?: $f['primera'];
                return $referencia && substr($referencia, 0, 10) < $limite
                    && (!$f['recordado_en'] || substr($f['recordado_en'], 0, 10) < $limite);
            }));
            if ($viejos > 0) {
                $avisos[] = [
                    'id' => $this->idAviso('fiado', $hoy, $viejos),
                    'titulo' => 'Fiados para cobrar',
                    'mensaje' => $viejos === 1
                        ? '1 cliente no paga hace más de 30 días.'
                        : "{$viejos} clientes no pagan hace más de 30 días.",
                    'tipo' => 'advertencia',
                    'ruta' => '/fiados',
                    'texto_ruta' => 'Recordar por WhatsApp',
                ];
            }
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
