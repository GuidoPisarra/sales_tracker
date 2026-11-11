<?php

namespace App\Repository;

use PDO;

class PlanRepository extends BaseRepository
{
    private const COLUMNAS_PLAN = 'ps.id, ps.nombre, ps.codigo, ps.precio_mensual, ps.max_sucursales, ps.max_usuarios,
        ps.max_productos, ps.max_mensajes_ia, ps.cuentas_corrientes';

    /**
     * Plan del negocio. La fuente de verdad es la suscripción; negocio.id_plan
     * queda como respaldo para bases sin la migración 0002.
     */
    public function obtenerPorNegocio(int $idNegocio): ?array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT ' . self::COLUMNAS_PLAN . ' FROM suscripcion s
             JOIN plan_suscripcion ps ON ps.id = s.id_plan
             WHERE s.id_negocio = :id_negocio'
        );
        $stmt->execute([':id_negocio' => $idNegocio]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fila) {
            return $fila;
        }

        $stmt = $this->get_bbdd()->prepare(
            'SELECT ps.id, ps.nombre FROM plan_suscripcion ps
             JOIN negocio n ON n.id_plan = ps.id
             WHERE n.id_negocio = :id_negocio LIMIT 1'
        );
        $stmt->execute([':id_negocio' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function porCodigo(string $codigo): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS_PLAN . ', ps.publico FROM plan_suscripcion ps WHERE ps.codigo = :c');
        $stmt->execute([':c' => $codigo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function porId(int $id): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS_PLAN . ', ps.publico FROM plan_suscripcion ps WHERE ps.id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function publicos(): array
    {
        return $this->get_bbdd()->query(
            'SELECT ' . self::COLUMNAS_PLAN . ' FROM plan_suscripcion ps WHERE ps.publico = 1 AND ps.codigo IS NOT NULL ORDER BY ps.orden'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
