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

  /**
   * Negocio real al que pertenece un empleado, para validar antes de tocarlo.
   */
  public function obtenerIdNegocio(int $id_employee): ?int
  {
    $query = $this->get_bbdd()->prepare('SELECT id_negocio FROM user WHERE id = :id');
    $query->bindParam(':id', $id_employee);
    $query->execute();
    $fila = $query->fetch(PDO::FETCH_ASSOC);
    return $fila ? (int) $fila['id_negocio'] : null;
  }

  /** true si el usuario es administrador (ROLE_TITULAR) y no está eliminado. */
  public function esTitularActivo(int $idUsuario): bool
  {
    $query = $this->get_bbdd()->prepare("SELECT COUNT(*) FROM user WHERE id = :id AND role LIKE '%ROLE_TITULAR%' AND (eliminado = 0 OR eliminado IS NULL)");
    $query->execute([':id' => $idUsuario]);
    return (int) $query->fetchColumn() > 0;
  }

  public function contarTitularesActivos(int $idNegocio): int
  {
    $query = $this->get_bbdd()->prepare("SELECT COUNT(*) FROM user WHERE id_negocio = :n AND role LIKE '%ROLE_TITULAR%' AND (eliminado = 0 OR eliminado IS NULL)");
    $query->execute([':n' => $idNegocio]);
    return (int) $query->fetchColumn();
  }

  public function delete_employee(int $id_employee): bool
  {
    $query = $this->get_bbdd()->prepare('UPDATE user SET eliminado = 1 WHERE  id = :id_employee');
    $query->bindParam(':id_employee', $id_employee);
    return $query->execute();
  }
  public function crearEmpleado(EmpleadoDTO $dto): ?array
  {
    $rol = self::rolBaseDeDatos($dto->getRol()) ?? '["ROLE_USER"]';
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
                SET name = :name, email = :email, role = COALESCE(:role, role)
                WHERE id = :id AND id_negocio = :id_negocio AND (eliminado = 0 OR eliminado IS NULL)';

    $query = $this->get_bbdd()->prepare($sql);
    $query->bindValue(':name', $dto->getNombre());
    $query->bindValue(':email', $dto->getEmail());
    // Si el PATCH no trae rol, se conserva el que tenía (antes lo pasaba a empleado).
    $query->bindValue(':role', self::rolBaseDeDatos($dto->getRol()));
    $query->bindValue(':id', $idEmpleado, PDO::PARAM_INT);
    $query->bindValue(':id_negocio', $dto->getIdNegocio(), PDO::PARAM_INT);

    return $query->execute();
  }

  /**
   * Traduce el rol que manda el front al formato de la tabla user.
   * El front manda "Administrador" o "Empleado"; antes el alta solo reconocía
   * "titular", así que un empleado nuevo marcado como Administrador quedaba
   * como empleado común. Se aceptan los dos nombres para el administrador.
   * Devuelve null si no vino rol.
   */
  public static function rolBaseDeDatos(?string $rol): ?string
  {
    if ($rol === null || trim($rol) === '') {
      return null;
    }
    $rol = mb_strtolower(trim($rol));
    return in_array($rol, ['administrador', 'titular'], true) ? '["ROLE_TITULAR"]' : '["ROLE_USER"]';
  }
}
