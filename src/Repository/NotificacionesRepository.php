<?php

namespace App\Repository;

use PDO;

class NotificacionesRepository extends BaseRepository
{
    public function obtenerPorUsuario(int $idUsuario, int $idNegocio): array
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT n.id, n.titulo, n.mensaje, n.tipo, n.leida, n.created_at
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

    /**
     * usuario_id de una notificación, para validar antes de marcarla leída.
     * Mismo criterio que obtenerPorUsuario: no se filtra por negocio, solo por usuario_id
     * (propia o broadcast con usuario_id = 0).
     */
    public function obtenerUsuarioId(int $idNotificacion): ?int
    {
        $query = $this->get_bbdd()->prepare('SELECT usuario_id FROM notificaciones WHERE id = :id');
        $query->bindParam(':id', $idNotificacion);
        $query->execute();
        $fila = $query->fetch(PDO::FETCH_ASSOC);
        return $fila ? (int) $fila['usuario_id'] : null;
    }

    public function marcarLeida(int $idNotificacion): bool
    {
        $query = $this->get_bbdd()->prepare('UPDATE notificaciones SET leida = 1 WHERE id = :id');
        $query->bindParam(':id', $idNotificacion);
        return $query->execute();
    }
}
