<?php

namespace App\Repository;

use PDO;

/** Datos para las tarjetas "Para vos" del Inicio (ver DestacadosService). */
class DestacadosRepository extends BaseRepository
{
    /**
     * Unidades vendidas por producto desde una fecha, con el stock actual del producto.
     * Solo productos activos de esa sucursal.
     */
    public function ventasPorProducto(int $idNegocio, int $sucursal, string $desde): array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT p.id, p.description, p.size, p.quantity, SUM(sp.quantity) AS vendidas
             FROM sales_product sp
             JOIN product p ON p.id = sp.id_product AND p.id_negocio = sp.id_negocio AND p.activo = 0
             WHERE sp.id_negocio = :n AND sp.sucursal = :s AND sp.active = 0 AND sp.sale_product_date >= :desde
             GROUP BY p.id, p.description, p.size, p.quantity'
        );
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal, ':desde' => $desde]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Total vendido por día (todas las sucursales) desde una fecha: ['Y-m-d' => total]. */
    public function totalesPorDia(int $idNegocio, string $desde): array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT DATE(sale_product_date) AS dia, SUM(price * quantity) AS total
             FROM sales_product
             WHERE id_negocio = :n AND active = 0 AND sale_product_date >= :desde
             GROUP BY DATE(sale_product_date)'
        );
        $stmt->execute([':n' => $idNegocio, ':desde' => $desde]);
        $dias = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
            $dias[$f['dia']] = round((float) $f['total'], 2);
        }
        return $dias;
    }
}
