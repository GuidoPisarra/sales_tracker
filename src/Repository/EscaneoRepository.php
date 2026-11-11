<?php

namespace App\Repository;

use PDO;

/**
 * Sesiones de "Escanear con el celular" (ver EscaneoService) y los códigos
 * que manda el celular. Las fechas se comparan con NOW() de la base, así no
 * dependen de la zona horaria de PHP.
 */
class EscaneoRepository extends BaseRepository
{
    private const COLUMNAS = 'id, id_negocio, sucursal, id_usuario, modo, dispositivo, creada, vence_vinculo, vinculada_en, vence_en, cerrada_en,
        (vence_vinculo > NOW()) AS vinculo_vigente, (vence_en IS NOT NULL AND vence_en > NOW()) AS sesion_vigente';

    public function crear(int $idNegocio, int $sucursal, int $idUsuario, string $tokenHash, int $minutosVinculo, string $modo = 'productos'): int
    {
        $pdo = $this->get_bbdd();
        $pdo->prepare('INSERT INTO escaneo_sesion (id_negocio, sucursal, id_usuario, token_hash, vence_vinculo, modo)
            VALUES (:n, :s, :u, :t, NOW() + INTERVAL :m MINUTE, :modo)')
            ->execute([':n' => $idNegocio, ':s' => $sucursal, ':u' => $idUsuario, ':t' => $tokenHash, ':m' => $minutosVinculo, ':modo' => $modo]);
        return (int) $pdo->lastInsertId();
    }

    /** La sesión, solo si es del usuario que la pide (la PC). */
    public function delUsuario(int $id, int $idUsuario): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS . ' FROM escaneo_sesion WHERE id = :id AND id_usuario = :u');
        $stmt->execute([':id' => $id, ':u' => $idUsuario]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** La sesión del celular, por el hash de su clave. */
    public function porClave(string $claveHash): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS . ' FROM escaneo_sesion WHERE clave_hash = :c');
        $stmt->execute([':c' => $claveHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Canjea el token del QR por la clave del celular. Es un solo UPDATE
     * condicional: si dos celulares escanean el mismo QR, gana uno solo.
     */
    public function vincular(string $tokenHash, string $claveHash, ?string $dispositivo, int $minutosSesion): bool
    {
        $stmt = $this->get_bbdd()->prepare('UPDATE escaneo_sesion
            SET clave_hash = :c, vinculada_en = NOW(), vence_en = NOW() + INTERVAL :m MINUTE, dispositivo = :d
            WHERE token_hash = :t AND vinculada_en IS NULL AND cerrada_en IS NULL AND vence_vinculo > NOW()');
        $stmt->execute([':c' => $claveHash, ':m' => $minutosSesion, ':d' => $dispositivo, ':t' => $tokenHash]);
        return $stmt->rowCount() === 1;
    }

    public function cerrar(int $id): void
    {
        $this->get_bbdd()->prepare('UPDATE escaneo_sesion SET cerrada_en = COALESCE(cerrada_en, NOW()) WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /** Guarda un código si la sesión no llegó al máximo. Devuelve false si llegó. */
    public function agregarCodigo(int $idSesion, string $codigo, int $maximo): bool
    {
        $stmt = $this->get_bbdd()->prepare('INSERT INTO escaneo_codigo (id_sesion, codigo)
            SELECT :s, :c FROM DUAL WHERE (SELECT COUNT(*) FROM escaneo_codigo WHERE id_sesion = :s2) < :max');
        $stmt->execute([':s' => $idSesion, ':c' => $codigo, ':s2' => $idSesion, ':max' => $maximo]);
        return $stmt->rowCount() === 1;
    }

    /** Códigos que la PC todavía no levantó (id mayor al último que vio). */
    public function codigosDesde(int $idSesion, int $desde): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id, codigo FROM escaneo_codigo WHERE id_sesion = :s AND id > :d ORDER BY id LIMIT 200');
        $stmt->execute([':s' => $idSesion, ':d' => $desde]);
        return array_map(fn ($f) => ['id' => (int) $f['id'], 'codigo' => $f['codigo']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function cantidadCodigos(int $idSesion): int
    {
        $stmt = $this->get_bbdd()->prepare('SELECT COUNT(*) FROM escaneo_codigo WHERE id_sesion = :s');
        $stmt->execute([':s' => $idSesion]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Producto activo de la sucursal por código, código de barras o código
     * interno (el mismo criterio que usa Caja), para mostrarlo en el celular.
     */
    public function producto(int $idNegocio, int $sucursal, string $codigo): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT description, sale_price AS salePrice, quantity FROM product
            WHERE id_negocio = :n AND sucursal = :s AND activo = 0 AND (code = :c1 OR barcode = :c2 OR codigo_interno = :c3)
            ORDER BY (code = :c4) DESC LIMIT 1');
        $stmt->execute([':n' => $idNegocio, ':s' => $sucursal, ':c1' => $codigo, ':c2' => $codigo, ':c3' => $codigo, ':c4' => $codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ? ['description' => $fila['description'], 'salePrice' => (float) $fila['salePrice'], 'quantity' => (int) $fila['quantity']] : null;
    }

    /** Guarda una foto del celular si la sesión no llegó al máximo. Devuelve su id o null. */
    public function agregarFoto(int $idSesion, string $mime, string $datos, int $maximo): ?int
    {
        $pdo = $this->get_bbdd();
        $stmt = $pdo->prepare('INSERT INTO escaneo_foto (id_sesion, mime, datos)
            SELECT :s, :m, :d FROM DUAL WHERE (SELECT COUNT(*) FROM escaneo_foto WHERE id_sesion = :s2) < :max');
        $stmt->bindValue(':s', $idSesion, PDO::PARAM_INT);
        $stmt->bindValue(':m', $mime);
        $stmt->bindValue(':d', $datos, PDO::PARAM_LOB);
        $stmt->bindValue(':s2', $idSesion, PDO::PARAM_INT);
        $stmt->bindValue(':max', $maximo, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() === 1 ? (int) $pdo->lastInsertId() : null;
    }

    /** @return int[] ids de las fotos de la sesión */
    public function fotos(int $idSesion): array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT id FROM escaneo_foto WHERE id_sesion = :s ORDER BY id');
        $stmt->execute([':s' => $idSesion]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Una foto, solo si la sesión es del usuario. */
    public function fotoDelUsuario(int $idSesion, int $idFoto, int $idUsuario): ?array
    {
        $stmt = $this->get_bbdd()->prepare('SELECT f.mime, f.datos FROM escaneo_foto f JOIN escaneo_sesion s ON s.id = f.id_sesion
            WHERE f.id = :f AND s.id = :s AND s.id_usuario = :u');
        $stmt->execute([':f' => $idFoto, ':s' => $idSesion, ':u' => $idUsuario]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ? ['mime' => $fila['mime'], 'datos' => (string) $fila['datos']] : null;
    }

    /** Borra las sesiones (y sus códigos) viejas. */
    public function purgar(int $horas): void
    {
        $pdo = $this->get_bbdd();
        $pdo->prepare('DELETE c FROM escaneo_codigo c JOIN escaneo_sesion s ON s.id = c.id_sesion WHERE s.creada < NOW() - INTERVAL :h HOUR')
            ->execute([':h' => $horas]);
        $pdo->prepare('DELETE f FROM escaneo_foto f JOIN escaneo_sesion s ON s.id = f.id_sesion WHERE s.creada < NOW() - INTERVAL :h HOUR')
            ->execute([':h' => $horas]);
        $pdo->prepare('DELETE FROM escaneo_sesion WHERE creada < NOW() - INTERVAL :h HOUR')->execute([':h' => $horas]);
    }
}
