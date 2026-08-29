<?php

namespace App\Repository;

use PDO;

class NotificacionesRepository extends BaseRepository
{
    public function obtenerPorUsuario(int $idUsuario, int $idNegocio): array
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT n.titulo, n.mensaje, n.tipo, n.leida, n.created_at
            FROM notificaciones n
            LEFT JOIN negocio ne ON ne.id_negocio = :id_negocio
            WHERE (n.usuario_id = :usuario_id OR n.usuario_id = 0)'
        );
        $query->bindParam(':id_negocio', $idNegocio);
        $query->bindParam(':usuario_id', $idUsuario);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);

        return $query->fetchAll() ?: [];
    }
}
