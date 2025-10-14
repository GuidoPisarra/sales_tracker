<?php

namespace App\Model;

use Symfony\Component\Security\Core\User\UserInterface;

class Usuario implements UserInterface
{
    protected $id;
    protected $nombre;
    protected $email;
    protected $password;
    protected $roles;

    public function __construct()
    {
        $this->roles = json_encode('["ROLE_USER"]');
    }

    public function getRoles(): array
    {
        return ["ROLE_USER"];
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getSalt(): string
    {
        return '';
    }

    public function eraseCredentials(): string
    {
        return '';
    }

    public function getUserIdentifier(): ?string
    {
        return $this->email;
    }

    public function getUsername(): string
    {
        return $this->email;
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function get_nombre(): string
    {
        return $this->nombre;
    }

    public function get_email(): string
    {
        return $this->email;
    }

    public function set_password(string $password)
    {
        $this->password = $password;
    }

    public function set_nombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function set_email(string $email): void
    {
        $this->email = $email;
    }

    public function get_roles(): string
    {
        return $this->roles;
    }

    public function get_password(): ?string
    {
        return $this->password;
    }

    public function to_array(): array
    {
        $respuesta = [];

        $respuesta['id'] = $this->get_id();
        $respuesta['nombre'] = $this->get_nombre();
        $respuesta['password'] = $this->get_password();
        $respuesta['roles'] = $this->get_roles();

        return $respuesta;
    }
}
