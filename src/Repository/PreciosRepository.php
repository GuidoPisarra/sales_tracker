<?php

namespace App\Repository;

use PDO;

class PreciosRepository extends BaseRepository
{
    /**
     * Productos activos de un negocio cuyo precio no se actualiza hace más de $fechaLimite.
     * Los más viejos primero (los que más urge revisar), paginado en bloques de $limite.
     */
    public function obtenerDesactualizados(int $idNegocio, string $fechaLimite, int $limite, int $offset): array
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT id, code, description, sale_price, fecha_actualizado
            FROM product
            WHERE activo = 0 AND id_negocio = :id_negocio AND fecha_actualizado < :fecha_limite AND quantity > 0
            ORDER BY fecha_actualizado ASC
            LIMIT :limite OFFSET :offset'
        );
        $query->bindParam(':id_negocio', $idNegocio);
        $query->bindParam(':fecha_limite', $fechaLimite);
        $query->bindValue(':limite', $limite, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);

        return $query->fetchAll() ?: [];
    }

    /**
     * Total de productos desactualizados (sin paginar) — para que el front sepa cuándo dejar
     * de pedir más páginas en el infinite scroll.
     */
    public function contarDesactualizados(int $idNegocio, string $fechaLimite): int
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT COUNT(*) AS total
            FROM product
            WHERE activo = 0 AND id_negocio = :id_negocio AND fecha_actualizado < :fecha_limite AND quantity > 0'
        );
        $query->bindParam(':id_negocio', $idNegocio);
        $query->bindParam(':fecha_limite', $fechaLimite);
        $query->execute();

        return (int) $query->fetch(PDO::FETCH_ASSOC)['total'];
    }
}
