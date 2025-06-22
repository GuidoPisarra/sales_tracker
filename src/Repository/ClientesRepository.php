<?php

namespace App\Repository;

use PDO;

class ClientesRepository extends BaseRepository
{

    public function list_clientes(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT id id,dni dni, apellido apellido,nombre nombre, telefono telefono, id_negocio, id_negocio  FROM clientes WHERE id_negocio = :id_negocio');
        $query->bindParam(':id_negocio', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $clientes = $query->fetchAll();

        if (!$clientes) {
            return [];
        }

        return $clientes;
    }
}
