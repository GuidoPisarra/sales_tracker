<?php

namespace App\Repository;

use PDO;

/** Configuración de las funciones opcionales de cada negocio (tabla negocio_config). */
class NegocioConfigRepository extends BaseRepository
{
    /** Configuración del negocio (con valores por defecto si todavía no tiene fila). */
    public function config(int $idNegocio): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM negocio_config WHERE id_negocio = :n');
        $stmt->execute([':n' => $idNegocio]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ?: [
            'id_negocio' => $idNegocio,
            'caja_activa' => 0,
            'catalogo_activo' => 0,
            'catalogo_slug' => null,
            'catalogo_titulo' => null,
            'catalogo_mensaje' => null,
            'catalogo_whatsapp' => null,
            'catalogo_sucursal' => 1,
            'catalogo_mostrar_stock' => 0,
            'catalogo_sin_stock' => 0,
            'stock_minimo_general' => 3,
            'ticket_titulo' => null,
            'ticket_direccion' => null,
            'ticket_telefono' => null,
            'ticket_pie' => null,
            'ticket_ancho' => 58,
            'fiado_mensaje' => null,
            'precios_activo' => 0,
            'precios_sucursal' => null,
        ];
    }

    /** Guarda algunos campos de la configuración (crea la fila si no existe). */
    public function guardarConfig(int $idNegocio, array $campos): void
    {
        if (!$campos) {
            return;
        }
        $columnas = array_keys($campos);
        $sql = 'INSERT INTO negocio_config (id_negocio, ' . implode(', ', $columnas) . ') VALUES (:id_negocio, :'
            . implode(', :', $columnas) . ') ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $columnas));
        $params = [':id_negocio' => $idNegocio];
        foreach ($campos as $c => $v) {
            $params[':' . $c] = $v;
        }
        $this->get_bbdd()->prepare($sql)->execute($params);
    }

    /** Negocio dueño de un slug de catálogo (activo o no), o null. */
    public function negocioDelSlug(string $slug): ?int
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id_negocio FROM negocio_config WHERE catalogo_slug = :s');
        $stmt->execute([':s' => $slug]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function nombreNegocio(int $idNegocio, int $sucursal): ?string
    {
        $stmt = $this->get_bbdd()->prepare('SELECT nombre FROM negocio WHERE id_negocio = :n ORDER BY sucursal = :s DESC, sucursal LIMIT 1');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal]);
        $n = $stmt->fetchColumn();
        return $n === false ? null : (string) $n;
    }

    /**
     * Productos para el catálogo público: activos (activo = 0) de la sucursal elegida,
     * sin costo ni proveedor. Con $soloConStock = false incluye también los sin stock.
     */
    public function productosCatalogo(int $idNegocio, int $sucursal, bool $soloConStock): array
    {
        $sql = 'SELECT id, description, code, size, sale_price, quantity FROM product
                WHERE id_negocio = :n AND sucursal = :s AND activo = 0 AND sale_price > 0';
        if ($soloConStock) {
            $sql .= ' AND quantity > 0';
        }
        $stmt = $this->get_bbdd()->prepare($sql . ' ORDER BY description LIMIT 5000');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Producto activo de la sucursal por el código que tiene la etiqueta: código de Kontia
     * (el del QR), código de barras o código interno.
     */
    public function productoPorCodigo(int $idNegocio, int $sucursal, string $codigo): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, description, code, size, sale_price, quantity FROM product
            WHERE id_negocio = :n AND sucursal = :s AND activo = 0 AND (code = :c1 OR barcode = :c2 OR codigo_interno = :c3)
            ORDER BY code = :c4 DESC, id DESC LIMIT 1');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal, ':c1' => $codigo, ':c2' => $codigo, ':c3' => $codigo, ':c4' => $codigo]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Otros productos con el mismo nombre (otros talles o colores) en la sucursal. */
    public function mismaDescripcion(int $idNegocio, int $sucursal, string $descripcion, int $sinId): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, size, sale_price, quantity FROM product
            WHERE id_negocio = :n AND sucursal = :s AND activo = 0 AND description = :d AND id <> :id AND sale_price > 0
            ORDER BY size LIMIT 30');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal, ':d' => $descripcion, ':id' => $sinId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
