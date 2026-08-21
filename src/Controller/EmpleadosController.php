<?php

namespace App\Controller;

use App\DTO\EmpleadoDTO;
use App\Form\Type\Empleado\EmpleadoType;
use App\Service\EmpleadosService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/api/empleados")
 */
class EmpleadosController extends BaseController
{
  private EmpleadosService $empleadosService;

  public function __construct(EmpleadosService $empleadosService)
  {
    $this->empleadosService = $empleadosService;
  }

  /**
   * @Route("/{id_negocio}", name="app_empleados_list", methods={"GET"})
   */
  public function listar(int $id_negocio): JsonResponse
  {
    try {
      $empleados = $this->empleadosService->listarPorNegocio($id_negocio);
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
  public function delete_employee(int $id_employee): JsonResponse
  {
    try {
      $ok = $this->empleadosService->delete_employee($id_employee);
      return $this->respuesta(200, [$ok], []);
    } catch (\Throwable $e) {
      return $this->respuesta(
        500,
        [],
        ['Error al eliminar empleado']
      );
    }
  }

  /**
   * @Route("/nuevo", name="app_empleado_crear", methods={"POST"})
   */
  public function crear(Request $request): JsonResponse
  {
    try {
      $data = json_decode($request->getContent(), true) ?? [];
      $dto = new EmpleadoDTO();

      $form = $this->createForm(EmpleadoType::class, $dto);
      $form->submit($data);

      if (!$form->isValid()) {
        return $this->respuesta(400, [], ['Datos inválidos o faltantes']);
      }

      $nuevoEmpleado = $this->empleadosService->crearEmpleado($dto);

      if (!$nuevoEmpleado) {
        return $this->respuesta(500, [], ['No se pudo crear el empleado']);
      }

      return $this->respuesta(201, $nuevoEmpleado, []);
    } catch (\Throwable $e) {
      return $this->respuesta(500, [], ['Error interno al crear el empleado']);
    }
  }
  /**
   * @Route("/{id_employee}", name="app_empleado_actualizar", methods={"PATCH"})
   */
  public function actualizar(int $id_employee, Request $request): JsonResponse
  {
    try {
      $data = json_decode($request->getContent(), true) ?? [];
      $dto = new EmpleadoDTO();

      // 1. Asignamos el ID que viene en la URL directamente al DTO
      $dto->setIdEmpleado($id_employee); // o $dto->setId($id_employee);

      $form = $this->createForm(EmpleadoType::class, $dto);

      // 2. Usamos `false` como segundo parámetro para evitar que los campos ausentes en el JSON se borren (PATCH)
      $form->submit($data, false);

      if (!$form->isValid()) {
        return $this->respuesta(400, [], ['Datos inválidos o faltantes']);
      }

      // 3. Enviamos el ID y el DTO (que ahora sí incluye el ID adentro)
      $ok = $this->empleadosService->actualizarEmpleado($id_employee, $dto);

      if (!$ok) {
        return $this->respuesta(400, [], ['No se pudo actualizar el empleado']);
      }

      return $this->respuesta(200, ['Empleado actualizado correctamente'], []);
    } catch (\Throwable $e) {
      return $this->respuesta(500, [], ['Error interno al actualizar el empleado: ' . $e->getMessage()]);
    }
  }
}
