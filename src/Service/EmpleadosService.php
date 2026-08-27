<?php

namespace App\Service;

use App\DTO\EmpleadoDTO;
use App\Model\Usuario;
use App\Repository\EmpleadosRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class EmpleadosService
{
  protected $empleadosRepository;
  protected $hasher_interface;
  protected $rep_usuario;
  protected $jwt_manager;

  public function __construct(
    EmpleadosRepository $empleadosRepository,
    UserPasswordHasherInterface $hasher_interface,
    JWTTokenManagerInterface $jwt_manager
  ) {
    $this->empleadosRepository = $empleadosRepository;
    $this->hasher_interface = $hasher_interface; // <-- ¡Faltaba esta línea!
    $this->jwt_manager = $jwt_manager;
  }

  public function obtenerIdNegocio(int $idEmpleado): ?int
  {
    return $this->empleadosRepository->obtenerIdNegocio($idEmpleado);
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

  public function crearEmpleado(EmpleadoDTO $dto): ?array
  {
    $user = new Usuario();
    $passwordHasheada = $this->hasher_interface->hashPassword($user, $dto->getPassword());
    $dto->setPassword($passwordHasheada);
    return $this->empleadosRepository->crearEmpleado($dto);
  }
  public function actualizarEmpleado(int $idEmpleado, EmpleadoDTO $dto): bool
  {
    return $this->empleadosRepository->actualizarEmpleado($idEmpleado, $dto);
  }

  public function encriptar_contraseña(UserInterface $usuario): string
  {
    $password = $this->get_hasher_interface()->hashPassword($usuario, $usuario->getPassword());
    return $password;
  }

  protected function get_hasher_interface(): UserPasswordHasherInterface
  {
    return $this->hasher_interface;
  }
}
