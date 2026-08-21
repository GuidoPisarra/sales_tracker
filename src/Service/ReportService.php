<?php

namespace App\Service;

use App\DTO\Reportes\ReportIncomesExpensesDTO;
use App\Model\Venta;
use App\Repository\ChangeProductRepository;
use App\Repository\ReportRepository;
use Doctrine\Common\Collections\ArrayCollection;

class ReportService
{
    protected $rep_report;
    protected $rep_change;

    public function __construct(ReportRepository $rep_report, ChangeProductRepository $rep_change)
    {
        $this->rep_report = $rep_report;
        $this->rep_change = $rep_change;
    }

    public function report_salesProduct(int $id_negocio)
    {
        $ventas = $this->rep_report->report_salesProduct($id_negocio);
        $cambios = $this->rep_change->list_changes($id_negocio);
        $resultado = [];
        $resultado['cambios'] = $cambios;
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

        // Convierte $resultado a JSON si es necesario
        $jsonResultado = json_encode($resultado);
        return $resultado;
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
