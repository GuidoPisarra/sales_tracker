<?php

namespace App\Repository;

use PDO;

/** Conteos de stock (ver ConteoService). */
class ConteoRepository extends BaseRepository
{
    /**
     * Productos activos del negocio en esa sucursal, por id.
     *
     * @return array<int, array> id => fila
     */
    public function productos(int $idNegocio, int $sucursal, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->get_bbdd()->prepare(
            "SELECT id, description, code, quantity, cost_price, sale_price FROM product
             WHERE id_negocio = ? AND sucursal = ? AND activo = 0 AND id IN ($marcas)"
        );
        $stmt->execute(array_merge([$idNegocio, $sucursal], $ids));
        $porId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $porId[(int) $p['id']] = $p;
        }
        return $porId;
    }

    /** Guarda el conteo con sus renglones. Devuelve el id. */
    public function crear(int $idNegocio, int $sucursal, int $usuario, ?string $alcance, ?string $observacion, array $items, string $ahora): int
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO conteo_stock (id_negocio, sucursal, estado, alcance, observacion, creado_por, creado)
                 VALUES (?, ?, "pendiente", ?, ?, ?, ?)'
            )->execute([$idNegocio, $sucursal, $alcance, $observacion, $usuario, $ahora]);
            $id = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare(
                'INSERT INTO conteo_stock_item (id_conteo, id_producto, descripcion, codigo, sistema, contado, costo, precio)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $i) {
                $stmt->execute([$id, $i['id_producto'], $i['descripcion'], $i['codigo'], $i['sistema'], $i['contado'], $i['costo'], $i['precio']]);
            }
            $pdo->commit();
            return $id;
        } catch (\Throwable $th) {
            $pdo->rollBack();
            throw $th;
        }
    }

    public function conteo(int $id, int $idNegocio): ?array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT c.*, uc.name AS creado_por_nombre, ur.name AS resuelto_por_nombre FROM conteo_stock c
             LEFT JOIN user uc ON uc.id = c.creado_por
             LEFT JOIN user ur ON ur.id = c.resuelto_por
             WHERE c.id = :id AND c.id_negocio = :n'
        );
        $stmt->execute([':id' => $id, ':n' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Últimos conteos del negocio (o solo los de un usuario), con su resumen. */
    public function conteos(int $idNegocio, ?int $soloDe, ?string $estado, int $limite = 50): array
    {
        $where = 'c.id_negocio = ?';
        $params = [$idNegocio];
        if ($soloDe !== null) {
            $where .= ' AND c.creado_por = ?';
            $params[] = $soloDe;
        }
        if ($estado !== null) {
            $where .= ' AND c.estado = ?';
            $params[] = $estado;
        }
        $stmt = $this->get_bbdd()->prepare(
            "SELECT c.*, uc.name AS creado_por_nombre, ur.name AS resuelto_por_nombre FROM conteo_stock c
             LEFT JOIN user uc ON uc.id = c.creado_por
             LEFT JOIN user ur ON ur.id = c.resuelto_por
             WHERE $where ORDER BY c.creado DESC, c.id DESC LIMIT " . max(1, min(200, $limite))
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array[]> id_conteo => renglones */
    public function items(array $idsConteo): array
    {
        if (!$idsConteo) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($idsConteo), '?'));
        $stmt = $this->get_bbdd()->prepare("SELECT * FROM conteo_stock_item WHERE id_conteo IN ($marcas) ORDER BY id");
        $stmt->execute(array_values($idsConteo));
        $porConteo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $porConteo[(int) $i['id_conteo']][] = $i;
        }
        return $porConteo;
    }

    /**
     * Aplica las diferencias al stock y marca el conteo como aplicado, todo o nada.
     * Devuelve false si el conteo ya no estaba pendiente.
     */
    public function aplicar(int $id, int $idNegocio, int $usuario, string $ahora): bool
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            $marcar = $pdo->prepare(
                'UPDATE conteo_stock SET estado = "aplicado", resuelto_por = ?, resuelto = ?
                 WHERE id = ? AND id_negocio = ? AND estado = "pendiente"'
            );
            $marcar->execute([$usuario, $ahora, $id, $idNegocio]);
            if ($marcar->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }
            $sucursal = $pdo->prepare('SELECT sucursal FROM conteo_stock WHERE id = ?');
            $sucursal->execute([$id]);
            $suc = (int) $sucursal->fetchColumn();
            $stock = $pdo->prepare(
                'UPDATE product SET quantity = GREATEST(0, quantity + ?)
                 WHERE id = ? AND id_negocio = ? AND sucursal = ? AND activo = 0'
            );
            foreach ($this->items([$id])[$id] ?? [] as $i) {
                $diferencia = (int) $i['contado'] - (int) $i['sistema'];
                if ($diferencia !== 0) {
                    $stock->execute([$diferencia, (int) $i['id_producto'], $idNegocio, $suc]);
                }
            }
            $pdo->commit();
            return true;
        } catch (\Throwable $th) {
            $pdo->rollBack();
            throw $th;
        }
    }

    public function descartar(int $id, int $idNegocio, int $usuario, string $ahora): bool
    {
        $stmt = $this->get_bbdd()->prepare(
            'UPDATE conteo_stock SET estado = "descartado", resuelto_por = ?, resuelto = ?
             WHERE id = ? AND id_negocio = ? AND estado = "pendiente"'
        );
        $stmt->execute([$usuario, $ahora, $id, $idNegocio]);
        return $stmt->rowCount() > 0;
    }
}
