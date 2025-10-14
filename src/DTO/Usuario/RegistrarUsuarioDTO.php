<?php

namespace App\DTO\Usuario;

use App\Repository\UsuarioRepository;

class RegistrarUsuarioDTO
{
    protected $id;
    protected $email;
    protected $nombre;
    protected $password;
    protected $role;
    protected $token;

    public function to_array(): array
    {
        $respuesta = [];

        $respuesta['nombre'] = $this->getNombre();
        $respuesta['email'] = $this->getEmail();
        $respuesta['password'] = $this->getPassword();




        return $respuesta;
    }

    public function getId(): int
    {
        return $this->id;
    }
    public function setNombre($nombre): void
    {
        $this->nombre = $nombre;
    }

    public function setEmail($email): void
    {
        $this->email = $email;
    }

    public function setPassword($password): void
    {
        $this->password = $password;
    }

    public function setRole($role): void
    {
        $this->role = $role;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRole(): string
    {
        return $this->role;
    }
}
