<?php

namespace App\Repository;

use PDO;
use PDOException;

class BaseRepository
{
    private $bbdd_com;

    /**
     * Devuelve la conexion con base de datos comercial
     */
    public function get_bbdd(): PDO
    {

        try {
            $this->bbdd_com = new PDO($_ENV['DATABASE_URL'], $_ENV['USUARIO_BD_COM'], $_ENV['PASSWORD_BD_COM']);
            $this->bbdd_com->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        } catch (PDOException $e) {
            die("Error al conectar con la base de datos: " . $e->getMessage());
        }
        return $this->bbdd_com;
    }
}
