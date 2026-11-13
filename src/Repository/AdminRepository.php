<?php

namespace App\Repository;

use PDO;

/**
 * Consultas del panel de administración de Kontia (dueños del SaaS, no de un
 * comercio). Cruzan todos los negocios: solo las usa AdminController, que
 * exige que el email del usuario esté en ADMIN_EMAILS.
 */
class AdminRepository extends BaseRepository
{
    /** Estados guardados en suscripcion.estado. */
    public const ESTADOS = ['prueba', 'activa', 'mora', 'suspendida', 'cancelada'];

    /**
     * @return array[] un registro por negocio con plan, estado, titular y último pago
     */
    public function negocios(string $buscar = '', string $estado = '', int $limite = 200): array
    {
        $where = [];
        $params = [];
        if ($buscar !== '') {
            $where[] = '(n.nombre LIKE :q OR t.email LIKE :q OR n.id_negocio = :qid)';
            $params[':q'] = '%' . $buscar . '%';
            $params[':qid'] = ctype_digit($buscar) ? (int) $buscar : -1;
        }
        if ($estado !== '') {
            $where[] = 's.estado = :estado';
            $params[':estado'] = $estado;
        }
        $sql = "SELECT n.id_negocio, n.nombre, n.sucursales,
                       s.estado, s.prueba_hasta, s.periodo_hasta, s.en_mora_desde, s.cancelada_en,
                       s.exento_limites, s.creada_en, (s.mp_preapproval_id IS NOT NULL) AS con_debito,
                       p.codigo AS plan, p.nombre AS plan_nombre, p.precio_mensual,
                       t.email AS email_titular, t.ultimo_ingreso,
                       (SELECT MAX(ps.fecha) FROM pago_suscripcion ps WHERE ps.id_negocio = n.id_negocio AND ps.estado = 'approved') AS ultimo_pago,
                       (SELECT COUNT(*) FROM product pr WHERE pr.id_negocio = n.id_negocio AND pr.activo = 0) AS productos,
                       (SELECT COUNT(*) FROM user uu WHERE uu.id_negocio = n.id_negocio AND (uu.eliminado = 0 OR uu.eliminado IS NULL)) AS usuarios
                FROM (SELECT id_negocio, MIN(nombre) AS nombre, COUNT(*) AS sucursales FROM negocio GROUP BY id_negocio) n
                LEFT JOIN suscripcion s ON s.id_negocio = n.id_negocio
                LEFT JOIN plan_suscripcion p ON p.id = s.id_plan
                LEFT JOIN (
                    SELECT id_negocio, MIN(email) AS email, MAX(ultimo_ingreso) AS ultimo_ingreso
                    FROM user WHERE role LIKE '%ROLE_TITULAR%' AND (eliminado = 0 OR eliminado IS NULL)
                    GROUP BY id_negocio
                ) t ON t.id_negocio = n.id_negocio"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY s.creada_en DESC, n.id_negocio DESC LIMIT ' . max(1, min($limite, 1000));
        $stmt = $this->get_bbdd()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Métricas generales para la cabecera del panel. */
    public function resumen(): array
    {
        $pdo = $this->get_bbdd();
        $uno = fn (string $sql) => $pdo->query($sql)->fetchColumn();

        $porEstado = [];
        foreach ($pdo->query('SELECT estado, COUNT(*) c FROM suscripcion GROUP BY estado')->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $porEstado[$f['estado']] = (int) $f['c'];
        }
        $porPlan = [];
        foreach ($pdo->query(
            "SELECT p.codigo, COUNT(*) c FROM suscripcion s JOIN plan_suscripcion p ON p.id = s.id_plan
             WHERE s.estado = 'activa' AND s.exento_limites = 0 GROUP BY p.codigo"
        )->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $porPlan[$f['codigo']] = (int) $f['c'];
        }

        return [
            'negocios' => (int) $uno('SELECT COUNT(DISTINCT id_negocio) FROM negocio'),
            'por_estado' => $porEstado,
            'pagos_por_plan' => $porPlan,
            'exentos' => (int) $uno('SELECT COUNT(*) FROM suscripcion WHERE exento_limites = 1'),
            // Ingreso mensual recurrente: suscripciones pagas activas (no exentas) por su precio.
            'ingreso_mensual' => (float) $uno(
                "SELECT COALESCE(SUM(p.precio_mensual), 0) FROM suscripcion s JOIN plan_suscripcion p ON p.id = s.id_plan
                 WHERE s.estado IN ('activa', 'mora') AND s.exento_limites = 0 AND s.mp_preapproval_id IS NOT NULL"
            ),
            'cobrado_mes' => (float) $uno(
                "SELECT COALESCE(SUM(monto), 0) FROM pago_suscripcion
                 WHERE estado = 'approved' AND fecha >= DATE_FORMAT(NOW(), '%Y-%m-01')"
            ),
            'altas_7_dias' => (int) $uno("SELECT COUNT(*) FROM auditoria WHERE accion = 'registro' AND creado_en >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'altas_30_dias' => (int) $uno("SELECT COUNT(*) FROM auditoria WHERE accion = 'registro' AND creado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
            // De los que se registraron solos, cuántos llegaron a pagar alguna vez.
            'registrados_total' => (int) $uno("SELECT COUNT(DISTINCT id_negocio) FROM auditoria WHERE accion = 'registro'"),
            'registrados_que_pagaron' => (int) $uno(
                "SELECT COUNT(DISTINCT a.id_negocio) FROM auditoria a
                 JOIN pago_suscripcion ps ON ps.id_negocio = a.id_negocio AND ps.estado = 'approved'
                 WHERE a.accion = 'registro'"
            ),
            'bajas_30_dias' => (int) $uno("SELECT COUNT(*) FROM suscripcion WHERE cancelada_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
            'solicitudes_pendientes' => (int) $uno(
                "SELECT COUNT(*) FROM auditoria WHERE accion IN ('solicitud_arrepentimiento', 'solicitud_baja')
                 AND creado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ),
        ];
    }

    /** @return array[] */
    public function pagos(int $idNegocio): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT mp_id, monto, estado, fecha FROM pago_suscripcion WHERE id_negocio = :n ORDER BY fecha DESC LIMIT 50');
        $stmt->execute([':n' => $idNegocio]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array[] últimos eventos de auditoría del negocio */
    public function actividad(int $idNegocio, int $limite = 30): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT accion, detalle, creado_en FROM auditoria WHERE id_negocio = :n ORDER BY id DESC LIMIT :l');
        $stmt->bindValue(':n', $idNegocio, PDO::PARAM_INT);
        $stmt->bindValue(':l', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array[] pedidos de arrepentimiento y de baja hechos desde la web pública */
    public function solicitudes(int $limite = 100): array
    {
        $stmt = $this->get_bbdd()->prepare(
            "SELECT id, id_negocio, accion, detalle, creado_en FROM auditoria
             WHERE accion IN ('solicitud_arrepentimiento', 'solicitud_baja')
             ORDER BY id DESC LIMIT :l"
        );
        $stmt->bindValue(':l', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function ($f) {
            $f['detalle'] = $f['detalle'] ? json_decode($f['detalle'], true) : null;
            return $f;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function idNegocioPorEmailTitular(string $email): ?int
    {
        $stmt = $this->get_bbdd()->prepare(
            "SELECT id_negocio FROM user WHERE email = :e AND role LIKE '%ROLE_TITULAR%' AND (eliminado = 0 OR eliminado IS NULL) LIMIT 1"
        );
        $stmt->execute([':e' => $email]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function existeNegocio(int $idNegocio): bool
    {
        $stmt = $this->get_bbdd()->prepare('SELECT 1 FROM negocio WHERE id_negocio = :n LIMIT 1');
        $stmt->execute([':n' => $idNegocio]);
        return (bool) $stmt->fetchColumn();
    }
}
