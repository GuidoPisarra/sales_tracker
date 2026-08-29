<?php

namespace App\Repository;

use PDO;

class PlanRepository extends BaseRepository
{
    public function obtenerPorNegocio(int $idNegocio): ?array
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT ps.id, ps.nombre 
            FROM plan_suscripcion ps
            LEFT JOIN negocio n ON n.id_plan = ps.id
            WHERE n.id_negocio = :id_negocio'
        );
        $query->bindParam(':id_negocio', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $fila = $query->fetch();

        return $fila ?: null;
    }
}
