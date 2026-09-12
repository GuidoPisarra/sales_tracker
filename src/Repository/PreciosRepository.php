<?php

namespace App\Repository;

use PDO;

class PreciosRepository extends BaseRepository
{
    /**
     * Productos activos de un negocio cuyo precio no se actualiza hace más de $fechaLimite.
     */
    public function obtenerDesactualizados(int $idNegocio, string $fechaLimite): array
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT id, code, description, sale_price, fecha_actualizado
            FROM product
            WHERE activo = 0 AND id_negocio = :id_negocio AND fecha_actualizado < :fecha_limite
            ORDER BY fecha_actualizado ASC'
        );
        $query->bindParam(':id_negocio', $idNegocio);
        $query->bindParam(':fecha_limite', $fechaLimite);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);

        return $query->fetchAll() ?: [];
    }
}
