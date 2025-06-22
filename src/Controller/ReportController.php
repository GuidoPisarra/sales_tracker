<?php

namespace App\Controller;

use App\Service\ReportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/api/report")
 */
class ReportController extends BaseController
{

    /**
     * @Route("/salesProduct/{id_negocio}", name="app_salesProduct_report", methods={"GET"})
     */
    public function salesProduct_report(Request $request, ValidatorInterface $validator, ReportService $report_service, int $id_negocio): JsonResponse
    {
        try {
            $report_sales_product = $report_service->report_salesProduct($id_negocio);
            return $this->respuesta(200, $report_sales_product, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    /**
     * @Route("/incomesExpenses/{month}/{year}/{id_negocio}", name="app_incomes_expenses", methods={"GET"})
     */
    public function incomes_expenses_report(Request $request, ValidatorInterface $validator, ReportService $report_service, int $month, int $year, int $id_negocio): JsonResponse
    {
        try {
            $report_incomes_expenses = $report_service->report_incomes_expenses($month, $year, $id_negocio);

            return $this->respuesta(200, $report_incomes_expenses->to_array(), []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
