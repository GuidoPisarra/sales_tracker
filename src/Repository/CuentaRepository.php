<?php

namespace App\Repository;

use PDO;

/**
 * Altas de cuentas (negocio + titular + suscripción), verificación de email,
 * recuperación de clave y vínculo con Google.
 */
class CuentaRepository extends BaseRepository
{
    public function emailExiste(string $email): bool
    {
        $stmt = $this->get_bbdd()->prepare('SELECT 1 FROM user WHERE email = :email');
        $stmt->execute([':email' => $email]);
        return (bool) $stmt->fetchColumn();
    }

    public function idPlanPorCodigo(string $codigo): ?int
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id FROM plan_suscripcion WHERE codigo = :c');
        $stmt->execute([':c' => $codigo]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Crea en una sola transacción: negocio (sucursal 1), usuario titular y
     * suscripción en prueba. Devuelve [id_negocio, id_usuario].
     *
     * @return array{0: int, 1: int}
     */
    public function crearCuenta(array $datos): array
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            // id_negocio no es autoincremental: se toma el siguiente libre, con
            // bloqueo para que dos registros simultáneos no reciban el mismo.
            $maxNegocio = (int) $pdo->query('SELECT COALESCE(MAX(id_negocio), 0) FROM negocio FOR UPDATE')->fetchColumn();
            $maxSuscripcion = (int) $pdo->query('SELECT COALESCE(MAX(id_negocio), 0) FROM suscripcion FOR UPDATE')->fetchColumn();
            $idNegocio = max($maxNegocio, $maxSuscripcion) + 1;

            $pdo->prepare('INSERT INTO negocio (id_negocio, sucursal, nombre, telefono, id_plan) VALUES (:n, 1, :nombre, :tel, :plan)')
                ->execute([
                    ':n' => $idNegocio,
                    ':nombre' => $datos['nombre_comercio'],
                    ':tel' => $datos['telefono'] ?? null,
                    ':plan' => $datos['id_plan'],
                ]);

            $pdo->prepare(
                'INSERT INTO user (name, email, password, role, id_negocio, sucursal, eliminado, asistente_ia,
                                   email_verificado, token_verificacion, google_id, acepto_terminos_en, creado_en)
                 VALUES (:name, :email, :password, :role, :n, 1, 0, 0, :verif, :token, :google, NOW(), NOW())'
            )->execute([
                ':name' => $datos['nombre'],
                ':email' => $datos['email'],
                ':password' => $datos['password_hash'],
                ':role' => '["ROLE_TITULAR"]',
                ':n' => $idNegocio,
                ':verif' => $datos['email_verificado'] ? 1 : 0,
                ':token' => $datos['token_verificacion'] ?? null,
                ':google' => $datos['google_id'] ?? null,
            ]);
            $idUsuario = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO suscripcion (id_negocio, id_plan, estado, prueba_hasta) VALUES (:n, :plan, :estado, :hasta)'
            )->execute([
                ':n' => $idNegocio,
                ':plan' => $datos['id_plan'],
                ':estado' => 'prueba',
                ':hasta' => $datos['prueba_hasta'],
            ]);

            $pdo->commit();
            return [$idNegocio, $idUsuario];
        } catch (\Throwable $th) {
            $pdo->rollBack();
            throw $th;
        }
    }

    public function usuarioPorEmail(string $email): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, name, email, id_negocio, email_verificado, google_id, eliminado FROM user WHERE email = :email');
        $stmt->execute([':email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function usuarioPorGoogleId(string $googleId): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, name, email, id_negocio, eliminado FROM user WHERE google_id = :g');
        $stmt->execute([':g' => $googleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Vincula la cuenta de Google y, como Google ya verificó el email, la marca verificada.
     */
    public function vincularGoogle(int $idUsuario, string $googleId): void
    {
        $this->get_bbdd()->prepare('UPDATE user SET google_id = :g, email_verificado = 1, token_verificacion = NULL WHERE id = :id')
            ->execute([':g' => $googleId, ':id' => $idUsuario]);
    }

    public function nuevoTokenVerificacion(int $idUsuario, string $token): void
    {
        $this->get_bbdd()->prepare('UPDATE user SET token_verificacion = :t WHERE id = :id')
            ->execute([':t' => $token, ':id' => $idUsuario]);
    }

    /**
     * Marca el email como verificado. Devuelve el email o null si el token no existe.
     */
    public function verificarEmail(string $token): ?string
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, email FROM user WHERE token_verificacion = :t');
        $stmt->execute([':t' => $token]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            return null;
        }
        $this->get_bbdd()->prepare('UPDATE user SET email_verificado = 1, token_verificacion = NULL WHERE id = :id')
            ->execute([':id' => $fila['id']]);
        return $fila['email'];
    }

    public function guardarTokenReset(int $idUsuario, string $tokenHash, \DateTimeInterface $expira): void
    {
        $this->get_bbdd()->prepare('UPDATE user SET token_reset = :t, token_reset_expira = :e WHERE id = :id')
            ->execute([':t' => $tokenHash, ':e' => $expira->format('Y-m-d H:i:s'), ':id' => $idUsuario]);
    }

    /**
     * Cambia la clave si el token (ya hasheado) existe y no venció. Devuelve el id del usuario o null.
     */
    public function restablecerClave(string $tokenHash, string $passwordHash): ?int
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id FROM user WHERE token_reset = :t AND token_reset_expira > NOW() AND (eliminado = 0 OR eliminado IS NULL)');
        $stmt->execute([':t' => $tokenHash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        // Quien recibió el mail demostró que el email es suyo: queda verificado.
        $this->get_bbdd()->prepare('UPDATE user SET password = :p, token_reset = NULL, token_reset_expira = NULL, email_verificado = 1, token_verificacion = NULL WHERE id = :id')
            ->execute([':p' => $passwordHash, ':id' => $id]);
        return (int) $id;
    }
}
