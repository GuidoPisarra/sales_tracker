<?php

namespace App\Repository;

use PDO;

/** Datos fiscales, tickets de acceso y comprobantes electrónicos de ARCA. */
class FacturaRepository extends BaseRepository
{
    public function fiscal(int $idNegocio): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM negocio_fiscal WHERE id_negocio = :n');
        $stmt->execute([':n' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Guarda algunos campos de los datos fiscales (crea la fila si no existe). */
    public function guardarFiscal(int $idNegocio, array $campos): void
    {
        if (!$campos) {
            return;
        }
        $columnas = array_keys($campos);
        $sql = 'INSERT INTO negocio_fiscal (id_negocio, ' . implode(', ', $columnas) . ') VALUES (:id_negocio, :'
            . implode(', :', $columnas) . ') ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $columnas));
        $params = [':id_negocio' => $idNegocio];
        foreach ($campos as $c => $v) {
            $params[':' . $c] = $v;
        }
        $this->get_bbdd()->prepare($sql)->execute($params);
    }

    public function token(int $idNegocio, string $entorno, string $servicio): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT token, sign, vence FROM arca_token
            WHERE id_negocio = :n AND entorno = :e AND servicio = :s AND vence > DATE_ADD(NOW(), INTERVAL 10 MINUTE)');
        $stmt->execute([':n' => $idNegocio, ':e' => $entorno, ':s' => $servicio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function guardarToken(int $idNegocio, string $entorno, string $servicio, array $t): void
    {
        $this->get_bbdd()->prepare('INSERT INTO arca_token (id_negocio, entorno, servicio, token, sign, vence)
            VALUES (:n, :e, :s, :t, :g, :v) ON DUPLICATE KEY UPDATE token = VALUES(token), sign = VALUES(sign), vence = VALUES(vence)')
            ->execute([':n' => $idNegocio, ':e' => $entorno, ':s' => $servicio, ':t' => $t['token'], ':g' => $t['sign'], ':v' => $t['vence']]);
    }

    public function borrarTokens(int $idNegocio): void
    {
        $this->get_bbdd()->prepare('DELETE FROM arca_token WHERE id_negocio = :n')->execute([':n' => $idNegocio]);
    }

    /** Total (no anulado) de una venta y su fecha. null si no existe o no es del negocio. */
    public function venta(int $idNegocio, int $idSale): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT SUM(quantity * price) AS total, MIN(sale_product_date) AS fecha,
                MAX(active) AS anulada, MAX(LOWER(COALESCE(type_payment, ""))) AS forma, COUNT(*) AS lineas
            FROM sales_product WHERE id_negocio = :n AND id_sale = :v');
        $stmt->execute([':n' => $idNegocio, ':v' => $idSale]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$f || (int) $f['lineas'] === 0) {
            return null;
        }
        return ['total' => round((float) $f['total'], 2), 'fecha' => $f['fecha'], 'anulada' => (int) $f['anulada'] === 1, 'forma' => $f['forma']];
    }

    public function crear(array $f): int
    {
        $columnas = array_keys($f);
        $this->get_bbdd()->prepare('INSERT INTO factura (' . implode(', ', $columnas) . ') VALUES (:' . implode(', :', $columnas) . ')')
            ->execute(array_combine(array_map(fn ($c) => ':' . $c, $columnas), array_values($f)));
        return (int) $this->get_bbdd()->lastInsertId();
    }

    public function actualizar(int $id, array $campos): void
    {
        $sets = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($campos)));
        $params = [':id' => $id];
        foreach ($campos as $c => $v) {
            $params[':' . $c] = $v;
        }
        $this->get_bbdd()->prepare("UPDATE factura SET $sets WHERE id = :id")->execute($params);
    }

    public function porId(int $idNegocio, int $id): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM factura WHERE id = :id AND id_negocio = :n');
        $stmt->execute([':id' => $id, ':n' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function porIdSinNegocio(int $id): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM factura WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Comprobantes de una venta (factura y, si se anuló, su nota de crédito), el más nuevo primero. */
    public function deVenta(int $idNegocio, int $idSale): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM factura WHERE id_negocio = :n AND id_sale = :v ORDER BY id DESC');
        $stmt->execute([':n' => $idNegocio, ':v' => $idSale]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Último comprobante de cada venta pedida: [id_sale => fila]. */
    public function ultimasDeVentas(int $idNegocio, array $idsVenta): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsVenta), fn ($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->get_bbdd()->prepare("SELECT id, id_sale, tipo_cbte, punto_venta, numero, estado, cae, importe_total, error
            FROM factura WHERE id_negocio = ? AND id_sale IN ($marcas) ORDER BY id");
        $stmt->execute(array_merge([$idNegocio], $ids));
        $resultado = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
            $resultado[(int) $f['id_sale']] = $f; // Queda la última (orden por id).
        }
        return $resultado;
    }

    /** Pendientes de todos los negocios, para el reintento automático. */
    public function pendientes(int $limite = 50): array
    {
        $stmt = $this->get_bbdd()->prepare("SELECT id, id_negocio FROM factura WHERE estado = 'pendiente' AND intentos < 30
            ORDER BY id LIMIT " . max(1, min($limite, 500)));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function lista(int $idNegocio, ?string $desde, ?string $hasta, ?string $estado, int $limite = 200): array
    {
        $sql = 'SELECT id, id_sale, tipo_cbte, punto_venta, numero, fecha, doc_tipo, doc_nro, receptor_nombre, importe_total,
                    estado, cae, cae_vence, error, id_factura_asociada, entorno, creado
                FROM factura WHERE id_negocio = :n';
        $params = [':n' => $idNegocio];
        if ($desde && $hasta) {
            $sql .= ' AND creado >= :desde AND creado < DATE_ADD(:hasta, INTERVAL 1 DAY)';
            $params[':desde'] = $desde;
            $params[':hasta'] = $hasta;
        }
        if ($estado) {
            $sql .= ' AND estado = :e';
            $params[':e'] = $estado;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min($limite, 1000));
        $stmt = $this->get_bbdd()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Candado por punto de venta: dos cajas no piden el mismo número a la vez. */
    public function bloquear(int $idNegocio, int $puntoVenta): bool
    {
        $stmt = $this->get_bbdd()->prepare('SELECT GET_LOCK(:k, 20)');
        $stmt->execute([':k' => "kontia_arca_{$idNegocio}_{$puntoVenta}"]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function desbloquear(int $idNegocio, int $puntoVenta): void
    {
        $stmt = $this->get_bbdd()->prepare('SELECT RELEASE_LOCK(:k)');
        $stmt->execute([':k' => "kontia_arca_{$idNegocio}_{$puntoVenta}"]);
    }
}
