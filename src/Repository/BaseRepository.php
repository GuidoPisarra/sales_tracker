<?php

namespace App\Repository;

use PDO;
use PDOException;

class BaseRepository
{
    /**
     * Una sola conexión por request, compartida por todos los repositorios.
     * Antes cada llamada a get_bbdd() abría una conexión nueva: además del costo,
     * lastInsertId() pedido con otro get_bbdd() devolvía 0 (p. ej. al crear un
     * empleado) y una consulta de otro repositorio no veía la transacción en curso.
     */
    private static ?PDO $bbdd_com = null;

    /**
     * Devuelve la conexion con base de datos comercial
     */
    public function get_bbdd(): PDO
    {
        if (self::$bbdd_com !== null) {
            return self::$bbdd_com;
        }

        try {
            // Usamos $_ENV['BD_COM'] que tiene el formato DSN correcto (mysql:host=...;dbname=...)
            $pdo = new PDO($_ENV['BD_COM'], $_ENV['USUARIO_BD_COM'], $_ENV['PASSWORD_BD_COM']);
            // Modo excepción: antes (WARNING) una consulta con error devolvía false
            // y, donde nadie revisaba el resultado, el error pasaba en silencio
            // (respuesta "OK" sin haber guardado nada). Ahora corta la operación:
            // las transacciones hacen rollBack en su catch y los controllers
            // responden con su mensaje genérico de error.
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            // Antes se hacía die() con el mensaje del error, que puede incluir host y
            // usuario de la base. Ahora el detalle va al log del servidor y el
            // controller responde con su mensaje genérico.
            error_log('Error al conectar con la base de datos: ' . $e->getMessage());
            throw new \RuntimeException('No se pudo conectar con la base de datos.');
        }

        self::$bbdd_com = $pdo;
        return self::$bbdd_com;
    }
}
