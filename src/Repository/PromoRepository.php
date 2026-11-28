<?php

namespace App\Repository;

use PDO;

/** Promos del negocio (2x1, porcentaje, por cantidad) y sus productos. */
class PromoRepository extends BaseRepository
{
    public function lista(int $idNegocio): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM promo WHERE id_negocio = :n ORDER BY activo DESC, id DESC');
        $stmt->execute([':n' => $idNegocio]);
        $promos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$promos) {
            return [];
        }
        $ids = array_map(fn ($p) => (int) $p['id'], $promos);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->get_bbdd()->prepare("SELECT pp.id_promo, pp.id_product FROM promo_producto pp WHERE pp.id_promo IN ($marcas)");
        $stmt->execute($ids);
        $productos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
            $productos[(int) $f['id_promo']][] = (int) $f['id_product'];
        }
        return array_map(fn ($p) => $p + ['productos' => $productos[(int) $p['id']] ?? []], $promos);
    }

    public function porId(int $idNegocio, int $id): ?array
    {
        foreach ($this->lista($idNegocio) as $p) {
            if ((int) $p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /** Crea o actualiza (si $id) la promo y reemplaza sus productos. Devuelve el id. */
    public function guardar(int $idNegocio, ?int $id, array $campos, array $productos): int
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $sets = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($campos)));
                $params = [':id' => $id, ':n' => $idNegocio];
                foreach ($campos as $c => $v) {
                    $params[":$c"] = $v;
                }
                $pdo->prepare("UPDATE promo SET $sets WHERE id = :id AND id_negocio = :n")->execute($params);
                $pdo->prepare('DELETE FROM promo_producto WHERE id_promo = :id')->execute([':id' => $id]);
            } else {
                $campos['id_negocio'] = $idNegocio;
                $columnas = array_keys($campos);
                $pdo->prepare('INSERT INTO promo (' . implode(', ', $columnas) . ') VALUES (:' . implode(', :', $columnas) . ')')
                    ->execute(array_combine(array_map(fn ($c) => ":$c", $columnas), array_values($campos)));
                $id = (int) $pdo->lastInsertId();
            }
            if ($productos) {
                // Solo productos del negocio.
                $marcas = implode(',', array_fill(0, count($productos), '?'));
                $pdo->prepare("INSERT IGNORE INTO promo_producto (id_promo, id_product)
                    SELECT ?, p.id FROM product p WHERE p.id_negocio = ? AND p.id IN ($marcas)")
                    ->execute(array_merge([$id, $idNegocio], $productos));
            }
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function borrar(int $idNegocio, int $id): bool
    {
        $stmt = $this->get_bbdd()->prepare('DELETE FROM promo WHERE id = :id AND id_negocio = :n');
        $stmt->execute([':id' => $id, ':n' => $idNegocio]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->get_bbdd()->prepare('DELETE FROM promo_producto WHERE id_promo = :id')->execute([':id' => $id]);
        return true;
    }
}
