<?php

namespace App\Controller;

use App\Service\EmpleadosService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/api/empleados")
 */
class EmpleadosController extends BaseController
{

  public function __construct() {}

  /**
   * @Route("/{id_negocio}", name="app_empleados_list", methods={"GET"})
   */
  public function listar(int $id_negocio, EmpleadosService $empleadosService): JsonResponse
  {
    try {
      $empleados = $empleadosService->listarPorNegocio($id_negocio);
      return $this->respuesta(200, $empleados, []);
    } catch (\Throwable $e) {
      return $this->respuesta(
        500,
        [],
        ['Error al obtener empleados']
      );
    }
  }

  /**
   * @Route("/{id_employee}", name="app_delete_employee", methods={"DELETE"})
   */
  public function delete_employee(int $id_employee, EmpleadosService $empleadosService): JsonResponse
  {
    try {
      $empleados = $empleadosService->delete_employee($id_employee);
      return $this->respuesta(200, [$empleados], []);
    } catch (\Throwable $e) {
      return $this->respuesta(
        500,
        [],
        ['Error al obtener empleados']
      );
    }
  }
}
