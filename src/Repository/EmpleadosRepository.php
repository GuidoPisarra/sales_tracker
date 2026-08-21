<?php

namespace App\Repository;

use App\DTO\EmpleadoDTO;
use PDO;

class EmpleadosRepository extends BaseRepository
{
  public function listarPorNegocio(int $idNegocio): array
  {
    $query = $this->get_bbdd()->prepare('SELECT id, email, name, role ,id_negocio, sucursal FROM user WHERE id_negocio = :id_negocio ORDER BY role ASC');
    $query->bindParam(':id_negocio', $idNegocio);
    $query->execute();
    $query->setFetchMode(PDO::FETCH_ASSOC);
    $expenses = $query->fetchAll();

    if (!$expenses) {
      return [];
    }

    return $expenses;
  }

  public function delete_employee(int $id_employee): bool
  {
    $query = $this->get_bbdd()->prepare('UPDATE user SET eliminado = 1 WHERE  id = :id_employee');
    $query->bindParam(':id_employee', $id_employee);
    return $query->execute();
  }
  public function crearEmpleado(EmpleadoDTO $dto): ?array
  {
    $rol = $dto->getRol() == 'titular' ? '["ROLE_TITULAR"]' : '["ROLE_USER"]';
    $sql = 'INSERT INTO user (name, email,password, role, id_negocio, eliminado) 
                VALUES (:name, :email,:password ,:role, :id_negocio, 0)';

    $query = $this->get_bbdd()->prepare($sql);
    $query->bindValue(':name', $dto->getNombre());
    $query->bindValue(':email', $dto->getEmail());
    $query->bindValue(':role', $rol);
    $query->bindValue(':password', $dto->getPassword());
    $query->bindValue(':id_negocio', $dto->getIdNegocio(), PDO::PARAM_INT);

    if ($query->execute()) {
      $idInsertado = (int) $this->get_bbdd()->lastInsertId();
      return [
        'id' => $idInsertado,
        'name' => $dto->getNombre(),
        'email' => $dto->getEmail(),
        'role' => $dto->getRol(),
        'id_negocio' => $dto->getIdNegocio(),
        'sucursal' => '0'
      ];
    }

    return null;
  }

  public function actualizarEmpleado(int $idEmpleado, EmpleadoDTO $dto): bool
  {
    $sql = 'UPDATE user 
                SET name = :name, email = :email, role = :role 
                WHERE id = :id AND id_negocio = :id_negocio AND (eliminado = 0 OR eliminado IS NULL)';

    $query = $this->get_bbdd()->prepare($sql);
    $query->bindValue(':name', $dto->getNombre());
    $query->bindValue(':email', $dto->getEmail());
    $query->bindValue(':role', $dto->getRol() == 'Administrador' ? '["ROLE_TITULAR"]' : '["ROLE_USER"]');
    $query->bindValue(':id', $idEmpleado, PDO::PARAM_INT);
    $query->bindValue(':id_negocio', $dto->getIdNegocio(), PDO::PARAM_INT);

    return $query->execute();
  }
}
