<?php

namespace App\Repository;

use App\Model\Usuario;
use PDO;
use Symfony\Component\Security\Core\User\UserInterface;

class UsuarioRepository extends BaseRepository
{


    public function buscar_usuario_email(String $user): ?Usuario
    {
        $query = $this->get_bbdd()->prepare('SELECT * FROM user WHERE email = :email');

        $query->bindParam(':email', $user);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_CLASS, 'App\Model\Usuario');
        $usuario = $query->fetch();

        if (!$usuario) {
            return null;
        }
        return $usuario;
    }

    public function buscar_rol_id_usuario(string $user): array
    {


        $query = $this->get_bbdd()->prepare('SELECT id, role, id_negocio,sucursal,name, asistente_ia FROM user WHERE email = :email AND eliminado <> 1');
        $query->bindParam(':email', $user);
        $query->execute();
        $resultados = $query->fetchAll(PDO::FETCH_ASSOC);

        if (count($resultados) > 0) {
            return $resultados[0];
        }
        return $resultados;
    }

    public function actualizarUltimoIngreso(string $email, string $fecha): bool
    {
        $query = $this->get_bbdd()->prepare('UPDATE user SET ultimo_ingreso = :fecha WHERE email = :email');
        $query->bindParam(':fecha', $fecha);
        $query->bindParam(':email', $email);
        return $query->execute();
    }
}
