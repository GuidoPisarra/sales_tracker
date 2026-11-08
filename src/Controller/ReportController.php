<?php

namespace App\Controller;

use App\Service\ReportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/report')]
class ReportController extends BaseController
{

    #[Route('/salesProduct', name: 'app_salesProduct_report_token', defaults: ['id_negocio' => null], methods: ['GET'])]
    #[Route('/salesProduct/{id_negocio}', name: 'app_salesProduct_report', requirements: ['id_negocio' => '\d+'], methods: ['GET'])]
    public function salesProduct_report(Request $request, ValidatorInterface $validator, ReportService $report_service, ?int $id_negocio): JsonResponse
    {
        $id_negocio ??= $this->idNegocioUsuarioActual();
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        try {
            $report_sales_product = $report_service->report_salesProduct($id_negocio);
            return $this->respuesta(200, $report_sales_product, []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/salesProduct/recientes', name: 'app_salesProduct_recientes_token', defaults: ['id_negocio' => null], methods: ['GET'])]
    #[Route('/salesProduct/recientes/{id_negocio}', name: 'app_salesProduct_recientes', requirements: ['id_negocio' => '\d+'], methods: ['GET'])]
    public function salesProduct_recientes(Request $request, ReportService $report_service, ?int $id_negocio): JsonResponse
    {
        $id_negocio ??= $this->idNegocioUsuarioActual();
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        $dias = max(1, (int) $request->query->get('dias', 15));

        try {
            $resultado = $report_service->report_salesProduct_recientes($id_negocio, $dias);
            return $this->respuesta(200, $resultado, []);
        } catch (\Throwable $th) {
            return $this->respuesta(500, [], ['Error al obtener los productos vendidos recientemente'], 500);
        }
    }

    #[Route('/incomesExpenses/{month}/{year}', name: 'app_incomes_expenses_token', defaults: ['id_negocio' => null], methods: ['GET'])]
    #[Route('/incomesExpenses/{month}/{year}/{id_negocio}', name: 'app_incomes_expenses', requirements: ['id_negocio' => '\d+'], methods: ['GET'])]
    public function incomes_expenses_report(Request $request, ValidatorInterface $validator, ReportService $report_service, int $month, int $year, ?int $id_negocio): JsonResponse
    {
        $id_negocio ??= $this->idNegocioUsuarioActual();
        if ($check = $this->soloTitular()) {
            return $check;
        }
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        try {
            $report_incomes_expenses = $report_service->report_incomes_expenses($month, $year, $id_negocio);

            return $this->respuesta(200, $report_incomes_expenses->to_array(), []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
