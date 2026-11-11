<?php

namespace App\Service;

use App\Model\Usuario;
use App\Repository\UsuarioRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class ServicioUsuario
{
    protected $hasher_interface;
    protected $rep_usuario;
    protected $jwt_manager;

    public function __construct(UserPasswordHasherInterface $hasher_interface, UsuarioRepository $rep_usuario, JWTTokenManagerInterface $jwt_manager)
    {
        $this->hasher_interface = $hasher_interface;
        $this->rep_usuario = $rep_usuario;
        $this->jwt_manager = $jwt_manager;
    }

    public function encriptar_contraseña(UserInterface $usuario): string
    {
        $password = $this->get_hasher_interface()->hashPassword($usuario, $usuario->getPassword());
        return $password;
    }

    protected function get_hasher_interface(): UserPasswordHasherInterface
    {
        return $this->hasher_interface;
    }

    protected function get_rep_usuario(): UsuarioRepository
    {
        return $this->rep_usuario;
    }

    protected function get_jwt_manager(): JWTTokenManagerInterface
    {
        return $this->jwt_manager;
    }

    public function get_rol_id_usuario(string $email)
    {

        $findUser = $this->rep_usuario->buscar_rol_id_usuario($email);
        if ($findUser !== null) {
            return $findUser;
        }
        return null;
    }

    public function actualizarUltimoIngreso(string $email): bool
    {
        $timezone = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $fecha = (new \DateTime('now', $timezone))->format('Y-m-d H:i:s');
        return $this->rep_usuario->actualizarUltimoIngreso($email, $fecha);
    }
}
