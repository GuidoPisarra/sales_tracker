<?php

namespace App\Repository;

use App\DTO\Proveedores\AddProveedorDTO;
use PDO;

class ProveedoresRepository extends BaseRepository
{

    public function list_proveedores(int $id_local): ?array
    {
        $query = $this->get_bbdd()->prepare('SELECT p.id AS id, p.nombre AS nombre
        FROM proveedores p
        WHERE p.id_negocio = :local 
        ORDER BY p.nombre ASC ');

        $activo = 0;
        $query->bindParam(':local', $id_local);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $proveedores = $query->fetchAll();

        if (!$proveedores) {
            return [];
        }

        return $proveedores;
    }

    public function add_proveedor(AddProveedorDTO $dto)
    {
        $query = $this->get_bbdd()->prepare('INSERT INTO proveedores 
        (nombre, telefono, calle, numero_calle, ciudad, id_negocio)
        VALUES (:nombre, :telefono, :calle, :numero, :ciudad, :id_negocio)');

        $newProveedor = $dto->to_array();
        $query->bindParam(':nombre', $newProveedor["nombre"]);
        $query->bindParam(':telefono', $newProveedor["telefono"]);
        $query->bindParam(':calle', $newProveedor["calle"]);
        $query->bindParam(':numero', $newProveedor["numero"]);
        $query->bindParam(':ciudad', $newProveedor["ciudad"]);
        $query->bindParam(':id_negocio', $newProveedor["id_negocio"]);

        $response = $query->execute();
        return $response;
    }
}
