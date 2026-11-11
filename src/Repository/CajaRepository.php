<?php

namespace App\Repository;

use PDO;

/**
 * Apertura y cierre de caja (opcional por negocio). La configuración del negocio
 * (negocio_config) viene de NegocioConfigRepository.
 *
 * Las horas se guardan en hora de Argentina, igual que sale_product_date, para
 * poder sumar las ventas de un turno comparando fechas directamente.
 */
class CajaRepository extends NegocioConfigRepository
{
    public function turnoAbierto(int $idNegocio, int $sucursal): ?array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT t.*, ua.name AS abierta_por_nombre FROM caja_turno t
             LEFT JOIN user ua ON ua.id = t.abierta_por
             WHERE t.id_negocio = :n AND t.sucursal = :s AND t.abierta = 1'
        );
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function turno(int $id, int $idNegocio): ?array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT t.*, ua.name AS abierta_por_nombre, uc.name AS cerrada_por_nombre FROM caja_turno t
             LEFT JOIN user ua ON ua.id = t.abierta_por
             LEFT JOIN user uc ON uc.id = t.cerrada_por
             WHERE t.id = :id AND t.id_negocio = :n'
        );
        $stmt->execute([':id' => $id, ':n' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Abre un turno. Devuelve null si ya había uno abierto en esa sucursal. */
    public function abrir(int $idNegocio, int $sucursal, int $usuario, float $montoInicial, ?string $observacion, string $ahora): ?int
    {
        try {
            $pdo = $this->get_bbdd();
            $pdo->prepare('INSERT INTO caja_turno (id_negocio, sucursal, abierta, abierta_por, apertura, monto_inicial, observacion_apertura)
                VALUES (:n, :s, 1, :u, :a, :m, :o)')
                ->execute([':n' => $idNegocio, ':s' => $sucursal, ':u' => $usuario, ':a' => $ahora, ':m' => $montoInicial, ':o' => $observacion]);
            return (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    public function agregarMovimiento(int $idTurno, int $idNegocio, string $tipo, float $monto, ?string $motivo, int $usuario, string $ahora): void
    {
        $this->get_bbdd()->prepare('INSERT INTO caja_movimiento (id_turno, id_negocio, tipo, monto, motivo, usuario, fecha)
            VALUES (:t, :n, :tipo, :m, :motivo, :u, :f)')
            ->execute([':t' => $idTurno, ':n' => $idNegocio, ':tipo' => $tipo, ':m' => $monto, ':motivo' => $motivo, ':u' => $usuario, ':f' => $ahora]);
    }

    public function movimientos(int $idTurno, int $idNegocio): array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT m.id, m.tipo, m.monto, m.motivo, m.fecha, u.name AS usuario_nombre FROM caja_movimiento m
             LEFT JOIN user u ON u.id = m.usuario
             WHERE m.id_turno = :t AND m.id_negocio = :n ORDER BY m.fecha, m.id'
        );
        $stmt->execute([':t' => $idTurno, ':n' => $idNegocio]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ventas del turno por forma de pago (ventas no anuladas de la sucursal entre la
     * apertura y el cierre, o hasta ahora si sigue abierto). Incluye las ventas hechas
     * sin conexión que llegaron después, porque se guardan con su hora real.
     */
    public function ventasPorFormaPago(int $idNegocio, int $sucursal, string $desde, string $hasta): array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT LOWER(COALESCE(type_payment, \'efectivo\')) AS forma_pago,
                    SUM(quantity * price) AS total, COUNT(DISTINCT id_sale) AS ventas
             FROM sales_product
             WHERE id_negocio = :n AND sucursal = :s AND active = 0
               AND sale_product_date >= :desde AND sale_product_date <= :hasta
             GROUP BY LOWER(COALESCE(type_payment, \'efectivo\'))'
        );
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal, ':desde' => $desde, ':hasta' => $hasta]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function cerrar(int $idTurno, int $idNegocio, int $usuario, string $ahora, float $esperado, float $contado, ?string $observacion): bool
    {
        $stmt = $this->get_bbdd()->prepare(
            'UPDATE caja_turno SET abierta = NULL, cerrada_por = :u, cierre = :c, esperado_efectivo = :e,
                contado_efectivo = :k, diferencia = :d, observacion_cierre = :o
             WHERE id = :id AND id_negocio = :n AND abierta = 1'
        );
        $stmt->execute([
            ':u' => $usuario, ':c' => $ahora, ':e' => $esperado, ':k' => $contado,
            ':d' => round($contado - $esperado, 2), ':o' => $observacion, ':id' => $idTurno, ':n' => $idNegocio,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Turnos del negocio (el más nuevo primero), opcionalmente entre dos fechas (YYYY-MM-DD). */
    public function turnos(int $idNegocio, ?string $desde, ?string $hasta, int $limite = 100): array
    {
        $sql = 'SELECT t.id, t.sucursal, t.apertura, t.cierre, t.monto_inicial, t.esperado_efectivo, t.contado_efectivo,
                    t.diferencia, t.observacion_cierre, ua.name AS abierta_por_nombre, uc.name AS cerrada_por_nombre
                FROM caja_turno t
                LEFT JOIN user ua ON ua.id = t.abierta_por
                LEFT JOIN user uc ON uc.id = t.cerrada_por
                WHERE t.id_negocio = :n';
        $params = [':n' => $idNegocio];
        if ($desde && $hasta) {
            $sql .= ' AND t.apertura >= :desde AND t.apertura < DATE_ADD(:hasta, INTERVAL 1 DAY)';
            $params[':desde'] = $desde;
            $params[':hasta'] = $hasta;
        }
        $sql .= ' ORDER BY t.apertura DESC, t.id DESC LIMIT ' . max(1, min($limite, 500));
        $stmt = $this->get_bbdd()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
