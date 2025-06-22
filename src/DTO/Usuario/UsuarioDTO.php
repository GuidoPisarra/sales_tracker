<?php

namespace App\DTO\Usuario;

use App\Repository\UsuarioRepository;

class UsuarioDTO
{
    protected $nombre;
    protected $email;
    protected $password;

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
}
