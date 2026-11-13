<?php

namespace App\Repository;

use PDO;

class SuscripcionRepository extends BaseRepository
{
    public function porNegocio(int $idNegocio): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM suscripcion WHERE id_negocio = :n');
        $stmt->execute([':n' => $idNegocio]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function porPreapproval(string $id): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT * FROM suscripcion WHERE mp_preapproval_id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array[] */
    public function conMercadoPago(): array
    {
        return $this->get_bbdd()->query("SELECT * FROM suscripcion WHERE mp_preapproval_id IS NOT NULL AND estado <> 'cancelada'")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pruebas que vencen en los próximos $dias días y todavía no recibieron el aviso.
     *
     * @return array[] con el email del titular
     */
    public function pruebasPorVencer(int $dias): array
    {
        $stmt = $this->get_bbdd()->prepare(
            "SELECT s.id_negocio, s.prueba_hasta, MIN(u.email) AS email
             FROM suscripcion s
             JOIN user u ON u.id_negocio = s.id_negocio AND u.role LIKE '%ROLE_TITULAR%' AND (u.eliminado = 0 OR u.eliminado IS NULL)
             WHERE s.estado = 'prueba' AND s.aviso_prueba_enviado = 0
               AND s.prueba_hasta BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :dias DAY)
             GROUP BY s.id_negocio, s.prueba_hasta"
        );
        $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function emailTitular(int $idNegocio): ?string
    {
        $stmt = $this->get_bbdd()->prepare(
            "SELECT email FROM user WHERE id_negocio = :n AND role LIKE '%ROLE_TITULAR%' AND (eliminado = 0 OR eliminado IS NULL) ORDER BY id LIMIT 1"
        );
        $stmt->execute([':n' => $idNegocio]);
        $email = $stmt->fetchColumn();
        return $email === false ? null : $email;
    }

    public function marcarAvisoPrueba(int $idNegocio): void
    {
        $this->get_bbdd()->prepare('UPDATE suscripcion SET aviso_prueba_enviado = 1 WHERE id_negocio = :n')->execute([':n' => $idNegocio]);
    }

    public function actualizar(int $idNegocio, array $campos): void
    {
        $permitidos = ['id_plan', 'estado', 'prueba_hasta', 'periodo_hasta', 'en_mora_desde', 'cancelada_en', 'mp_preapproval_id', 'id_plan_pendiente', 'exento_limites', 'aviso_prueba_enviado'];
        $sets = [];
        $params = [':n' => $idNegocio];
        foreach ($campos as $campo => $valor) {
            if (!in_array($campo, $permitidos, true)) {
                throw new \InvalidArgumentException('Campo no permitido: ' . $campo);
            }
            $sets[] = "$campo = :$campo";
            $params[":$campo"] = $valor;
        }
        if (!$sets) {
            return;
        }
        $this->get_bbdd()->prepare('UPDATE suscripcion SET ' . implode(', ', $sets) . ' WHERE id_negocio = :n')->execute($params);
        if (isset($campos['id_plan'])) {
            // negocio.id_plan se mantiene igual para el código viejo que lo lee.
            $this->get_bbdd()->prepare('UPDATE negocio SET id_plan = :p WHERE id_negocio = :n')->execute([':p' => $campos['id_plan'], ':n' => $idNegocio]);
        }
    }

    public function registrarPago(int $idNegocio, string $mpId, float $monto, string $estado, string $fecha, array $detalle): void
    {
        $this->get_bbdd()->prepare(
            'INSERT INTO pago_suscripcion (id_negocio, mp_id, monto, estado, fecha, detalle)
             VALUES (:n, :mp, :m, :e, :f, :d)
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), monto = VALUES(monto), detalle = VALUES(detalle)'
        )->execute([':n' => $idNegocio, ':mp' => $mpId, ':m' => $monto, ':e' => $estado, ':f' => $fecha, ':d' => json_encode($detalle)]);
    }

    public function pagos(int $idNegocio, int $limite = 24): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT mp_id, monto, estado, fecha FROM pago_suscripcion WHERE id_negocio = :n ORDER BY fecha DESC LIMIT :l');
        $stmt->bindValue(':n', $idNegocio, PDO::PARAM_INT);
        $stmt->bindValue(':l', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Registra un evento de webhook. Devuelve false si ya se había procesado.
     */
    public function registrarEvento(string $proveedor, string $eventoId): bool
    {
        $stmt = $this->get_bbdd()->prepare('INSERT IGNORE INTO webhook_evento (proveedor, evento_id) VALUES (:p, :e)');
        $stmt->execute([':p' => $proveedor, ':e' => $eventoId]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{sucursales:int, usuarios:int, productos:int, mensajes_ia:int} */
    public function uso(int $idNegocio): array
    {
        $pdo = $this->get_bbdd();
        $contar = function (string $sql) use ($pdo, $idNegocio): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':n' => $idNegocio]);
            return (int) $stmt->fetchColumn();
        };
        return [
            'sucursales' => $contar('SELECT COUNT(*) FROM negocio WHERE id_negocio = :n'),
            'usuarios' => $contar('SELECT COUNT(*) FROM user WHERE id_negocio = :n AND (eliminado = 0 OR eliminado IS NULL)'),
            'productos' => $contar('SELECT COUNT(*) FROM product WHERE id_negocio = :n AND activo = 0'),
            'mensajes_ia' => $contar("SELECT COALESCE(SUM(mensajes), 0) FROM uso_ia WHERE id_negocio = :n AND periodo = DATE_FORMAT(NOW(), '%Y-%m')"),
        ];
    }

    public function sumarMensajeIa(int $idNegocio): void
    {
        $this->get_bbdd()->prepare(
            "INSERT INTO uso_ia (id_negocio, periodo, mensajes) VALUES (:n, DATE_FORMAT(NOW(), '%Y-%m'), 1)
             ON DUPLICATE KEY UPDATE mensajes = mensajes + 1"
        )->execute([':n' => $idNegocio]);
    }
}
