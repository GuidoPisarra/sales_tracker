<?php

namespace App\Service;

use App\Repository\EmpleadosRepository;

class EmpleadosService
{
  protected $empleadosRepository;

  public function __construct(EmpleadosRepository $empleadosRepository)
  {
    $this->empleadosRepository = $empleadosRepository;
  }

  public function listarPorNegocio(int $idNegocio): array
  {
    $empleados = $this->empleadosRepository->listarPorNegocio($idNegocio);

    foreach ($empleados as $key => $empleado) {
      $empleados[$key]['role']  = ($empleado['role'] === '["ROLE_USER"]') ? 'Empleado' : 'Administrador';
    }

    return $empleados;
  }

  public function delete_employee(int $id_employee): bool
  {
    return $this->empleadosRepository->delete_employee($id_employee);
  }
}
