<?php

namespace App\Repository;

use PDO;

/** Fotos de productos (tabla producto_foto, migración 0009). */
class FotosRepository extends BaseRepository
{
    private const COLUMNAS = 'id, id_producto, archivo, miniatura, ancho, alto, bytes, orden';

    /**
     * Fotos por producto del plan del negocio y el nombre del plan. Va aparte de
     * PlanRepository para que una base sin la migración 0009 no rompa el login.
     */
    public function plan(int $idNegocio): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT ps.nombre, ps.max_fotos_producto FROM suscripcion s
            JOIN plan_suscripcion ps ON ps.id = s.id_plan WHERE s.id_negocio = :n');
        $stmt->execute([':n' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Producto activo del negocio (id, sucursal), o null. */
    public function producto(int $idNegocio, int $idProducto): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, sucursal FROM product WHERE id = :id AND id_negocio = :n AND activo = 0');
        $stmt->execute([':id' => $idProducto, ':n' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function porProducto(int $idNegocio, int $idProducto): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS . ' FROM producto_foto
            WHERE id_negocio = :n AND id_producto = :p ORDER BY orden, id');
        $stmt->execute([':n' => $idNegocio, ':p' => $idProducto]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Fotos de varios productos, agrupadas por id de producto y en orden.
     * @param int[] $ids
     * @return array<int, array[]>
     */
    public function porProductos(int $idNegocio, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $resultado = [];
        foreach (array_chunk($ids, 1000) as $grupo) {
            $marcas = implode(',', array_fill(0, count($grupo), '?'));
            $stmt = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS . " FROM producto_foto
                WHERE id_negocio = ? AND id_producto IN ($marcas) ORDER BY id_producto, orden, id");
            $stmt->execute(array_merge([$idNegocio], $grupo));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $resultado[(int) $f['id_producto']][] = $f;
            }
        }
        return $resultado;
    }

    public function contar(int $idNegocio, int $idProducto): int
    {
        $stmt = $this->get_bbdd()->prepare('SELECT COUNT(*) FROM producto_foto WHERE id_negocio = :n AND id_producto = :p');
        $stmt->execute([':n' => $idNegocio, ':p' => $idProducto]);
        return (int) $stmt->fetchColumn();
    }

    public function crear(int $idNegocio, int $idProducto, string $archivo, string $miniatura, int $ancho, int $alto, int $bytes): int
    {
        $pdo = $this->get_bbdd();
        $pdo->prepare('INSERT INTO producto_foto (id_negocio, id_producto, archivo, miniatura, ancho, alto, bytes, orden, creada_en)
            SELECT :n, :p, :a, :m, :w, :h, :b, COALESCE(MAX(orden) + 1, 0), NOW() FROM producto_foto WHERE id_negocio = :n2 AND id_producto = :p2')
            ->execute([':n' => $idNegocio, ':p' => $idProducto, ':a' => $archivo, ':m' => $miniatura, ':w' => $ancho, ':h' => $alto, ':b' => $bytes, ':n2' => $idNegocio, ':p2' => $idProducto]);
        return (int) $pdo->lastInsertId();
    }

    public function una(int $idNegocio, int $idProducto, int $idFoto): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS . ' FROM producto_foto WHERE id = :id AND id_negocio = :n AND id_producto = :p');
        $stmt->execute([':id' => $idFoto, ':n' => $idNegocio, ':p' => $idProducto]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function borrar(int $idNegocio, int $idFoto): void
    {
        $this->get_bbdd()->prepare('DELETE FROM producto_foto WHERE id = :id AND id_negocio = :n')
            ->execute([':id' => $idFoto, ':n' => $idNegocio]);
    }

    /**
     * Deja las fotos en el orden de $ids (la primera es la portada). Las que no
     * vienen en la lista quedan al final, en el orden que tenían.
     * @param int[] $ids
     */
    public function reordenar(int $idNegocio, int $idProducto, array $ids): void
    {
        $actuales = array_map(fn ($f) => (int) $f['id'], $this->porProducto($idNegocio, $idProducto));
        $pedidos = array_values(array_intersect(array_unique(array_map('intval', $ids)), $actuales));
        $orden = array_merge($pedidos, array_values(array_diff($actuales, $pedidos)));
        $stmt = $this->get_bbdd()->prepare('UPDATE producto_foto SET orden = :o WHERE id = :id AND id_negocio = :n');
        foreach ($orden as $i => $id) {
            $stmt->execute([':o' => $i, ':id' => $id, ':n' => $idNegocio]);
        }
    }

    /** Cuántas filas usan el archivo (al trasladar, dos productos comparten la misma foto). */
    public function usosDeArchivo(string $archivo): int
    {
        $stmt = $this->get_bbdd()->prepare('SELECT COUNT(*) FROM producto_foto WHERE archivo = :a');
        $stmt->execute([':a' => $archivo]);
        return (int) $stmt->fetchColumn();
    }

    /** Copia las fotos de un producto a otro del mismo negocio (mismos archivos). */
    public function copiar(int $idNegocio, int $origen, int $destino): void
    {
        $this->get_bbdd()->prepare('INSERT INTO producto_foto (id_negocio, id_producto, archivo, miniatura, ancho, alto, bytes, orden, creada_en)
            SELECT id_negocio, :d, archivo, miniatura, ancho, alto, bytes, orden, NOW() FROM producto_foto
            WHERE id_negocio = :n AND id_producto = :o')
            ->execute([':d' => $destino, ':n' => $idNegocio, ':o' => $origen]);
    }

    /** Fotos cuyo producto ya no existe (o es de otro negocio). */
    public function huerfanas(): array
    {
        return $this->get_bbdd()->query('SELECT f.id, f.id_negocio, f.archivo, f.miniatura FROM producto_foto f
            LEFT JOIN product p ON p.id = f.id_producto AND p.id_negocio = f.id_negocio
            WHERE p.id IS NULL')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function delNegocio(int $idNegocio): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, id_negocio, archivo, miniatura FROM producto_foto WHERE id_negocio = :n');
        $stmt->execute([':n' => $idNegocio]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Productos que se ven en el catálogo de esa sucursal y todavía no tienen foto. */
    public function productosSinFoto(int $idNegocio, int $sucursal): int
    {
        $stmt = $this->get_bbdd()->prepare('SELECT COUNT(*) FROM product p
            WHERE p.id_negocio = :n AND p.sucursal = :s AND p.activo = 0 AND p.sale_price > 0
              AND NOT EXISTS (SELECT 1 FROM producto_foto f WHERE f.id_negocio = p.id_negocio AND f.id_producto = p.id)');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal]);
        return (int) $stmt->fetchColumn();
    }

    /** Disco usado por negocio, en bytes (contando una vez cada archivo). */
    public function bytesPorNegocio(): array
    {
        $filas = $this->get_bbdd()->query('SELECT id_negocio, SUM(bytes) AS bytes, COUNT(*) AS fotos FROM
            (SELECT id_negocio, archivo, MAX(bytes) AS bytes FROM producto_foto GROUP BY id_negocio, archivo) t
            GROUP BY id_negocio')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $r = [];
        foreach ($filas as $f) {
            $r[(int) $f['id_negocio']] = ['bytes' => (int) $f['bytes'], 'fotos' => (int) $f['fotos']];
        }
        return $r;
    }
}
