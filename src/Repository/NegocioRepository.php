<?php

namespace App\Repository;

use App\DTO\Expenses\AddExpenseDTO;
use App\DTO\Expenses\ExpensesDTO;
use App\DTO\Negocio\EliminarNegocioDTO;
use App\DTO\Negocio\NegocioDTO;
use PDO;

class NegocioRepository extends BaseRepository
{

    public function list_negocios(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT id,id_negocio,nombre, sucursal, domicilio, telefono FROM negocio WHERE id_negocio = :id_negocio');
        $query->bindParam(':id_negocio', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $negocios = $query->fetchAll();

        if (!$negocios) {
            return null;
        }

        return $negocios;
    }

    public function add_negocio(NegocioDTO $dto): ?bool
    {
        $query = $this->get_bbdd()->prepare('INSERT INTO negocio 
        (id_negocio, sucursal, nombre, domicilio, telefono)
        VALUES (:id_negocio, :sucursal, :nombre, :domicilio, :telefono)');

        $newNegocio = $dto->to_array();
        $query->bindParam(':id_negocio', $newNegocio["id_negocio"]);
        $query->bindParam(':sucursal', $newNegocio["sucursal"]);
        $query->bindParam(':nombre', $newNegocio["nombre"]);
        $query->bindParam(':domicilio', $newNegocio["domicilio"]);
        $query->bindParam(':telefono', $newNegocio["telefono"]);


        $response = $query->execute();
        return $response;
    }

    public function delete_negocio(EliminarNegocioDTO $dto): ?bool
    {
        $query = $this->get_bbdd()->prepare('DELETE FROM negocio WHERE id_negocio= :id_negocio AND sucursal = :sucursal');

        $newNegocio = $dto->to_array();
        $query->bindParam(':id_negocio', $newNegocio["id_negocio"]);
        $query->bindParam(':sucursal', $newNegocio["sucursal"]);

        $response = $query->execute();
        return $response;
    }
}
