<?php

namespace App\Repository;

use PDO;

/** Carritos que arman las clientas desde "Consultá el precio" (tabla carrito_cliente). */
class CarritoClienteRepository extends BaseRepository
{
    /** Horas que dura un carrito sin cobrar. */
    public const HORAS = 6;

    public function crear(int $idNegocio, int $sucursal, string $codigo, string $token, array $items): void
    {
        // De paso se borran los viejos: no guardan nada que haga falta después.
        $this->get_bbdd()->prepare('DELETE FROM carrito_cliente WHERE creado_en < DATE_SUB(NOW(), INTERVAL 2 DAY)')->execute();
        $this->get_bbdd()->prepare('INSERT INTO carrito_cliente (id_negocio, sucursal, codigo, token, items, creado_en)
            VALUES (:n, :s, :c, :t, :i, NOW())')
            ->execute([':n' => $idNegocio, ':s' => $sucursal, ':c' => $codigo, ':t' => $token, ':i' => json_encode($items)]);
    }

    /** Si el código ya lo usa un carrito vigente del negocio. */
    public function codigoEnUso(int $idNegocio, string $codigo): bool
    {
        $stmt = $this->get_bbdd()->prepare('SELECT 1 FROM carrito_cliente WHERE id_negocio = :n AND codigo = :c
            AND creado_en >= DATE_SUB(NOW(), INTERVAL ' . self::HORAS . ' HOUR) LIMIT 1');
        $stmt->execute([':n' => $idNegocio, ':c' => $codigo]);
        return (bool) $stmt->fetchColumn();
    }

    /** Carrito vigente por código corto o por token (el del QR). */
    public function vigente(int $idNegocio, ?string $codigo, ?string $token): ?array
    {
        $donde = $token !== null ? 'token = :v' : 'codigo = :v';
        $stmt = $this->get_bbdd()->prepare("SELECT * FROM carrito_cliente WHERE id_negocio = :n AND $donde
            AND creado_en >= DATE_SUB(NOW(), INTERVAL " . self::HORAS . ' HOUR) ORDER BY id DESC LIMIT 1');
        $stmt->execute([':n' => $idNegocio, ':v' => $token ?? $codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ?: null;
    }

    public function marcarTomado(int $id): void
    {
        $this->get_bbdd()->prepare('UPDATE carrito_cliente SET tomado_en = NOW() WHERE id = :id AND tomado_en IS NULL')->execute([':id' => $id]);
    }

    /**
     * Productos activos de la sucursal entre los ids dados.
     * @param int[] $ids
     * @return array<int, array> por id
     */
    public function productos(int $idNegocio, int $sucursal, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->get_bbdd()->prepare("SELECT id, description, code, size, sale_price FROM product
            WHERE id_negocio = ? AND sucursal = ? AND activo = 0 AND id IN ($marcas)");
        $stmt->execute(array_merge([$idNegocio, $sucursal], array_map('intval', $ids)));
        $r = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $r[(int) $p['id']] = $p;
        }
        return $r;
    }
}
