<?php

namespace App\Service;

use App\DTO\Reportes\ReportIncomesExpensesDTO;
use App\Model\Venta;
use App\Repository\ChangeProductRepository;
use App\Repository\ReportRepository;
use Doctrine\Common\Collections\ArrayCollection;

class ReportService
{
    private const LIMITE_RECIENTES = 20;

    protected $rep_report;
    protected $rep_change;

    public function __construct(ReportRepository $rep_report, ChangeProductRepository $rep_change)
    {
        $this->rep_report = $rep_report;
        $this->rep_change = $rep_change;
    }

    public function resumenVentas(int $id_negocio, string $desde, string $hasta): array
    {
        return $this->rep_report->resumenVentas($id_negocio, $desde, $hasta);
    }

    public function report_salesProduct_recientes(int $id_negocio, int $dias)
    {
        $dias = $dias > 0 ? $dias : 15;
        $filas = $this->rep_report->report_salesProduct_recientes($id_negocio, $dias, self::LIMITE_RECIENTES);

        return array_map(function ($fila) {
            return [
                'id' => (int) $fila['id'],
                'description' => $fila['description'],
                'sale_price' => (float) $fila['sale_price'],
                'ultima_venta' => $fila['ultima_venta'],
            ];
        }, $filas);
    }

    public function report_salesProduct(int $id_negocio, ?string $desde = null, ?string $hasta = null)
    {
        $ventas = $this->rep_report->report_salesProduct($id_negocio, $desde, $hasta);
        $cambios = $this->rep_change->list_changes($id_negocio, $desde, $hasta);
        $resultado = [];
        $resultado['cambios'] = $this->agruparCambios($cambios ?? []);
        $ventasAgrupadas = [];
        foreach ($ventas as $venta) {
            $idVenta = $venta['id'];

            if (!isset($ventasAgrupadas[$idVenta])) {
                $ventasAgrupadas[$idVenta] = [];
            }

            $ventasAgrupadas[$idVenta][] = [
                "id" => $venta['id'],
                "id_product" => $venta['id_product'],
                "id_sale" => $venta['id_sale'],
                "description" => $venta['description'],
                "quantity" => $venta['quantity'],
                "amount" => $venta['amount'],
                "sale_product_date" => $venta['sale_product_date'],
                "type_payment" => $venta['type_payment']
            ];
        }

        // Convierte el arreglo de ventas agrupadas en un formato de lista
        $ventasAgrupadas = array_values($ventasAgrupadas);

        $resultado['ventas'] = $ventasAgrupadas;

        // Ahora $resultado contiene la estructura deseada con las ventas agrupadas en una lista

        return $resultado;
    }
    /**
     * Un cambio por varios productos se guarda como varias filas en "cambios" (una por unidad
     * nueva, misma fecha_cambio y mismo producto devuelto). Acá se juntan en un solo cambio con
     * el detalle en "productos_nuevos". Los campos de siempre (precio_producto_nuevo,
     * descripcion_nuevo, ...) quedan con los totales para que las versiones viejas de la app
     * lo sigan mostrando bien.
     */
    private function agruparCambios(array $filas): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            $clave = $fila['id_producto_cambio'] . '|' . $fila['fecha_cambio'];
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'id_producto_cambio' => $fila['id_producto_cambio'],
                    'precio_producto_cambio' => 0.0,
                    'id_producto_nuevo' => $fila['id_producto_nuevo'],
                    'precio_producto_nuevo' => 0.0,
                    'id_negocio' => $fila['id_negocio'],
                    'fecha_cambio' => $fila['fecha_cambio'],
                    'descripcion_cambio' => $fila['descripcion_cambio'],
                    'descripcion_nuevo' => '',
                    'productos_nuevos' => [],
                ];
            }
            $grupo = &$grupos[$clave];
            $grupo['precio_producto_cambio'] += (float) $fila['precio_producto_cambio'];
            $grupo['precio_producto_nuevo'] += (float) $fila['precio_producto_nuevo'];

            $claveProducto = $fila['id_producto_nuevo'] . '|' . (float) $fila['precio_producto_nuevo'];
            if (!isset($grupo['productos_nuevos'][$claveProducto])) {
                $grupo['productos_nuevos'][$claveProducto] = [
                    'id' => $fila['id_producto_nuevo'],
                    'descripcion' => $fila['descripcion_nuevo'],
                    'precio' => (float) $fila['precio_producto_nuevo'],
                    'cantidad' => 0,
                ];
            }
            $grupo['productos_nuevos'][$claveProducto]['cantidad']++;
            unset($grupo);
        }

        foreach ($grupos as &$grupo) {
            $grupo['productos_nuevos'] = array_values($grupo['productos_nuevos']);
            $grupo['descripcion_nuevo'] = implode(' + ', array_map(function ($p) {
                return $p['cantidad'] > 1 ? $p['cantidad'] . 'x ' . $p['descripcion'] : (string) $p['descripcion'];
            }, $grupo['productos_nuevos']));
        }
        unset($grupo);

        return array_values($grupos);
    }

    public function report_incomes_expenses(int $month, int $year, $id_negocio): ReportIncomesExpensesDTO
    {
        $expenses = $this->rep_report->report_expenses($month, $year, $id_negocio);
        $incomes = $this->rep_report->report_incomes($month, $year, $id_negocio);
        $changes = $this->rep_change->report_changes($month, $year, $id_negocio);
        if ($changes < 0) {
            $changes = $changes * (-1);
        }
        $response = new ReportIncomesExpensesDTO($incomes, $expenses,  $changes);

        return $response;
    }
}
