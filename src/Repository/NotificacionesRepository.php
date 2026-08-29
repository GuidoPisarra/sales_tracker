<?php

namespace App\Repository;

use PDO;

class NotificacionesRepository extends BaseRepository
{
    /**
     * usuario_id = 0 en una notificación es un broadcast: se muestra a todos los
     * usuarios de ese negocio, no a uno puntual.
     */
    public function obtenerPorUsuario(int $idUsuario, int $idNegocio): array
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT n.titulo, n.mensaje, n.tipo, n.leida, n.created_at
            FROM notificaciones n
            WHERE n.id_negocio = :id_negocio
            AND (n.usuario_id = :usuario_id OR n.usuario_id = 0)'
        );
        $query->bindParam(':id_negocio', $idNegocio);
        $query->bindParam(':usuario_id', $idUsuario);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);

        return $query->fetchAll() ?: [];
    }
}
