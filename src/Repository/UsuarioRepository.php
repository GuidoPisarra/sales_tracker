<?php

namespace App\Repository;

use App\DTO\Usuario\RegistrarUsuarioDTO;
use App\Model\Usuario;
use PDO;
use Symfony\Component\Security\Core\User\UserInterface;

class UsuarioRepository extends BaseRepository
{

    public function buscar_usuario(RegistrarUsuarioDTO $user): ?Usuario
    {
        $usuario = $user->to_array();

        $query = $this->get_bbdd()->prepare('SELECT * FROM user WHERE email = :email');

        $query->bindParam(':email', $usuario['email']);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_CLASS, 'App\Model\Usuario');
        $usuario = $query->fetch();

        if (!$usuario) {
            return null;
        }
        return $usuario;
    }


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

    public function registrar_usuario(RegistrarUsuarioDTO $user): void
    {
        $user = $user->to_array();
        $id = 2;
        $query = $this->get_bbdd()->prepare("INSERT INTO user  (email, name, password, role, id_negocio) VALUES (:email, :nombre, :password, :role, :idNegocio)");
        $role = json_encode(["ROLE_USER"]);
        $query->bindParam(':email', $user['email']);
        $query->bindParam(':nombre', $user['nombre']);
        $query->bindParam(':password', $user['password']);
        $query->bindParam(':role', $role);
        $query->bindParam(':idNegocio', $id);
        $res = $query->execute();
    }

    public function buscar_rol_id_usuario(string $user): array
    {


        $query = $this->get_bbdd()->prepare('SELECT id, role, id_negocio,sucursal FROM user WHERE email = :email');
        $query->bindParam(':email', $user);
        $query->execute();
        $resultados = $query->fetchAll(PDO::FETCH_ASSOC);

        if (count($resultados) > 0) {
            return $resultados[0];
        }
        return $resultados;
    }
}
