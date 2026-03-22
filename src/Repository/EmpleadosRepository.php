<?php

namespace App\Repository;

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
}
