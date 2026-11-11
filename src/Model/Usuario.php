<?php

namespace App\Model;

use Symfony\Component\Security\Core\User\UserInterface;

// La tabla user puede tener columnas que esta clase no declara (PDO::FETCH_CLASS
// las asigna igual); sin esto PHP 8.2+ avisa por cada una.
#[\AllowDynamicProperties]
class Usuario implements UserInterface
{
    protected $id;
    protected $nombre;
    protected $email;
    protected $password;
    protected $roles;
    // Hidratados por PDO::FETCH_CLASS (SELECT * FROM user) al autenticar cada request.
    // No confundir con getRoles() (roles de Symfony Security): esto es el campo "role" de negocio.
    protected $id_negocio;
    protected $role;
    protected $sucursal;
    protected $asistente_ia;
    protected $name;
    protected $eliminado;
    protected $email_verificado;
    protected $google_id;

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

    public function getIdNegocio(): ?int
    {
        return $this->id_negocio !== null ? (int) $this->id_negocio : null;
    }

    public function get_role(): ?string
    {
        return $this->role;
    }

    public function get_sucursal(): ?string
    {
        return $this->sucursal;
    }

    public function estaEliminado(): bool
    {
        return (bool) $this->eliminado;
    }

    /**
     * Las cuentas viejas no tienen la columna o la tienen en 1 (verificadas).
     */
    public function emailVerificado(): bool
    {
        return $this->email_verificado === null || (bool) $this->email_verificado;
    }

    public function getGoogleId(): ?string
    {
        return $this->google_id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getAsistenteIa(): bool
    {
        return (bool) $this->asistente_ia;
    }

    public function to_array(): array
    {
        $respuesta = [];

        $respuesta['id'] = $this->getId();
        $respuesta['nombre'] = $this->get_nombre();
        $respuesta['password'] = $this->get_password();
        $respuesta['roles'] = $this->get_roles();

        return $respuesta;
    }
}
