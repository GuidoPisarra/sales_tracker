<?php

namespace App\Repository;

use PDO;

/** Llaves de acceso (Face ID / huella en el navegador). Ver PasskeyService. */
class PasskeyRepository extends BaseRepository
{
    public function guardarDesafio(string $desafio, ?int $idUsuario, string $rpId): string
    {
        $pdo = $this->get_bbdd();
        $pdo->exec('DELETE FROM passkey_desafio WHERE expira < NOW()');
        $id = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO passkey_desafio (id, desafio, id_usuario, rp_id, expira) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))')
            ->execute([$id, $desafio, $idUsuario, $rpId]);
        return $id;
    }

    /** Devuelve el desafío y lo borra (un solo uso). Null si no existe, venció o es de otro usuario. */
    public function tomarDesafio(string $id, ?int $idUsuario, string $rpId): ?string
    {
        $pdo = $this->get_bbdd();
        $stmt = $pdo->prepare('SELECT desafio, id_usuario, rp_id FROM passkey_desafio WHERE id = ? AND expira >= NOW()');
        $stmt->execute([$id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('DELETE FROM passkey_desafio WHERE id = ?')->execute([$id]);
        if (!$fila || $fila['rp_id'] !== $rpId || ($fila['id_usuario'] === null ? null : (int) $fila['id_usuario']) !== $idUsuario) {
            return null;
        }
        return $fila['desafio'];
    }

    public function credencialesDeUsuario(int $idUsuario, string $rpId): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT credencial FROM usuario_passkey WHERE id_usuario = ? AND rp_id = ?');
        $stmt->execute([$idUsuario, $rpId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function guardar(int $idUsuario, string $rpId, string $credencial, string $clavePublica, int $contador, string $nombre): int
    {
        $pdo = $this->get_bbdd();
        $pdo->prepare('INSERT INTO usuario_passkey (id_usuario, rp_id, credencial, clave_publica, contador, nombre) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$idUsuario, $rpId, $credencial, $clavePublica, $contador, $nombre]);
        return (int) $pdo->lastInsertId();
    }

    public function porCredencial(string $credencial, string $rpId): ?array
    {
        $stmt = $this->get_bbdd()->prepare(
            'SELECT p.id, p.id_usuario, p.clave_publica, p.contador, u.email
             FROM usuario_passkey p JOIN user u ON u.id = p.id_usuario
             WHERE p.credencial = ? AND p.rp_id = ?'
        );
        $stmt->execute([$credencial, $rpId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function marcarUso(int $id, int $contador): void
    {
        $this->get_bbdd()->prepare('UPDATE usuario_passkey SET contador = ?, usada = NOW() WHERE id = ?')->execute([$contador, $id]);
    }

    public function lista(int $idUsuario): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, nombre, rp_id, creada, usada FROM usuario_passkey WHERE id_usuario = ? ORDER BY creada DESC');
        $stmt->execute([$idUsuario]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function borrar(int $id, int $idUsuario): bool
    {
        $stmt = $this->get_bbdd()->prepare('DELETE FROM usuario_passkey WHERE id = ? AND id_usuario = ?');
        $stmt->execute([$id, $idUsuario]);
        return $stmt->rowCount() > 0;
    }
}
