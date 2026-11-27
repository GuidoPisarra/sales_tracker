<?php

namespace App\Repository;

use PDO;

/**
 * Datos del negocio de ejemplo ("Probar sin registrarme"). Ver DemoService.
 */
class DemoRepository extends BaseRepository
{
    /** Tablas con id_negocio que no se vacían al reiniciar (la cuenta y su historial). */
    private const SE_CONSERVAN = ['negocio', 'user', 'suscripcion', 'pago_suscripcion', 'auditoria'];

    public function usuario(string $email): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, id_negocio FROM user WHERE email = :e AND eliminado = 0');
        $stmt->execute([':e' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Crea negocio, usuario titular y suscripción sin límites. Devuelve [id_negocio, id_usuario]. */
    public function crearCuenta(string $email, string $nombreNegocio): array
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            $max = (int) $pdo->query('SELECT GREATEST(COALESCE((SELECT MAX(id_negocio) FROM negocio), 0), COALESCE((SELECT MAX(id_negocio) FROM suscripcion), 0))')->fetchColumn();
            $idNegocio = $max + 1;
            $plan = (int) $pdo->query("SELECT id FROM plan_suscripcion WHERE codigo = 'premium' LIMIT 1")->fetchColumn() ?: null;
            $pdo->prepare('INSERT INTO negocio (id_negocio, sucursal, nombre, id_plan) VALUES (?, 1, ?, ?)')
                ->execute([$idNegocio, $nombreNegocio, $plan]);
            $pdo->prepare(
                "INSERT INTO user (name, email, password, role, id_negocio, sucursal, eliminado, asistente_ia, email_verificado)
                 VALUES ('Tienda de ejemplo', ?, ?, '[\"ROLE_TITULAR\"]', ?, 1, 0, 0, 1)"
            )->execute([$email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $idNegocio]);
            $idUsuario = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO suscripcion (id_negocio, id_plan, estado, exento_limites) VALUES (?, ?, 'activa', 1)")
                ->execute([$idNegocio, $plan]);
            $pdo->commit();
            return [$idNegocio, $idUsuario];
        } catch (\Throwable $th) {
            $pdo->rollBack();
            throw $th;
        }
    }

    /** Borra todo lo que cargaron en el negocio de ejemplo y deja la cuenta como recién creada. */
    public function vaciar(int $idNegocio, int $idUsuario): void
    {
        $pdo = $this->get_bbdd();
        $tablas = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'id_negocio'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            // Lo que cuelga de otras tablas del negocio va primero.
            $pdo->prepare('DELETE FROM conteo_stock_item WHERE id_conteo IN (SELECT id FROM conteo_stock WHERE id_negocio = ?)')->execute([$idNegocio]);
            $pdo->prepare('DELETE FROM pagos WHERE id_persona IN (SELECT id FROM clientes WHERE id_negocio = ?)')->execute([$idNegocio]);
            $pdo->prepare('DELETE FROM usuario_preferencia WHERE id_usuario IN (SELECT id FROM user WHERE id_negocio = ?)')->execute([$idNegocio]);
            foreach ($tablas as $tabla) {
                if (!in_array($tabla, self::SE_CONSERVAN, true)) {
                    $pdo->prepare("DELETE FROM `$tabla` WHERE id_negocio = ?")->execute([$idNegocio]);
                }
            }
            // Solo queda la sucursal 1 y el usuario de ejemplo.
            $pdo->prepare('DELETE FROM negocio WHERE id_negocio = ? AND sucursal <> 1')->execute([$idNegocio]);
            $pdo->prepare("UPDATE negocio SET nombre = 'Tienda de ejemplo', domicilio = NULL, telefono = NULL WHERE id_negocio = ?")->execute([$idNegocio]);
            $pdo->prepare('DELETE FROM user WHERE id_negocio = ? AND id <> ?')->execute([$idNegocio, $idUsuario]);
            $pdo->commit();
        } catch (\Throwable $th) {
            $pdo->rollBack();
            throw $th;
        }
    }

    public function insertar(string $sql, array $params): int
    {
        $pdo = $this->get_bbdd();
        $pdo->prepare($sql)->execute($params);
        return (int) $pdo->lastInsertId();
    }

    public function transaccion(callable $accion): void
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            $accion();
            $pdo->commit();
        } catch (\Throwable $th) {
            $pdo->rollBack();
            throw $th;
        }
    }
}
