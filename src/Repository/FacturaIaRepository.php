<?php

namespace App\Repository;

use PDO;

/** Productos de la sucursal y emparejamientos aprendidos para "Cargar factura". */
class FacturaIaRepository extends BaseRepository
{
    /** Catálogo activo de la sucursal, con lo justo para emparejar. */
    public function catalogo(int $idNegocio, int $sucursal): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, description, code, barcode, codigo_interno AS codigoInterno,
                cost_price AS costPrice, sale_price AS salePrice, quantity
            FROM product WHERE id_negocio = :n AND sucursal = :s AND activo = 0');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal]);
        return array_map(fn ($p) => [
            'id' => (int) $p['id'],
            'description' => (string) $p['description'],
            'code' => $p['code'],
            'barcode' => $p['barcode'],
            'codigoInterno' => $p['codigoInterno'],
            'costPrice' => (float) $p['costPrice'],
            'salePrice' => (float) $p['salePrice'],
            'quantity' => (int) $p['quantity'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, string> texto_hash => código del producto */
    public function alias(int $idNegocio, string $proveedor): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT texto_hash, codigo_producto FROM factura_alias WHERE id_negocio = :n AND proveedor = :p');
        $stmt->execute([':n' => $idNegocio, ':p' => $proveedor]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    public function guardarAlias(int $idNegocio, string $proveedor, string $textoHash, string $codigoProducto): void
    {
        $this->get_bbdd()->prepare('INSERT INTO factura_alias (id_negocio, proveedor, texto_hash, codigo_producto)
            VALUES (:n, :p, :t, :c) ON DUPLICATE KEY UPDATE codigo_producto = VALUES(codigo_producto)')
            ->execute([':n' => $idNegocio, ':p' => $proveedor, ':t' => $textoHash, ':c' => $codigoProducto]);
    }

    public function producto(int $id, int $idNegocio, int $sucursal): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, code FROM product WHERE id = :id AND id_negocio = :n AND sucursal = :s AND activo = 0');
        $stmt->execute([':id' => $id, ':n' => $idNegocio, ':s' => $sucursal]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function sumarStock(int $id, int $cantidad, float $costo, float $venta, string $fecha): void
    {
        $this->get_bbdd()->prepare('UPDATE product SET quantity = quantity + :q, cost_price = :c, sale_price = :v, fecha_actualizado = :f WHERE id = :id')
            ->execute([':q' => $cantidad, ':c' => $costo, ':v' => $venta, ':f' => $fecha, ':id' => $id]);
    }

    public function codigoUsado(int $idNegocio, int $sucursal, string $codigo): bool
    {
        $stmt = $this->get_bbdd()->prepare('SELECT 1 FROM product WHERE id_negocio = :n AND sucursal = :s AND code = :c AND activo = 0 LIMIT 1');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal, ':c' => $codigo]);
        return (bool) $stmt->fetchColumn();
    }

    public function crear(int $idNegocio, int $sucursal, array $p, string $fecha): int
    {
        $pdo = $this->get_bbdd();
        $pdo->prepare('INSERT INTO product (description, cost_price, sale_price, quantity, code, barcode, activo, id_negocio, sucursal, fecha_actualizado)
            VALUES (:d, :c, :v, :q, :code, :b, 0, :n, :s, :f)')
            ->execute([
                ':d' => $p['descripcion'], ':c' => $p['costo'], ':v' => $p['precio_venta'], ':q' => $p['cantidad'],
                ':code' => $p['codigo'], ':b' => $p['barcode'], ':n' => $idNegocio, ':s' => $sucursal, ':f' => $fecha,
            ]);
        return (int) $pdo->lastInsertId();
    }

    public function enTransaccion(callable $fn): mixed
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $th) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $th;
        }
    }
}
