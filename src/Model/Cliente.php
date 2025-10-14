<?php

namespace App\Model;

class Cliente
{
    protected $id;
    protected $apellido;
    protected $nombre;
    protected $dni;
    protected $id_negocio;
    protected $telefono;

    public function __construct(int $id, int $dni, string $apellido, string $nombre, int $id_negocio, string $telefono)
    {
        $this->id = $id;
        $this->dni = $dni;
        $this->apellido = $apellido;
        $this->nombre = $nombre;
        $this->id_negocio = $id_negocio;
        $this->telefono = $telefono;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getIdNegocio(): int
    {
        return $this->id_negocio;
    }

    public function setIdNegocio(int $id_negocio): void
    {
        $this->id_negocio = $id_negocio;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function getApellido(): string
    {
        return $this->apellido;
    }

    public function setApellido(string $apellido): void
    {
        $this->apellido = $apellido;
    }

    public function getTelefono(): string
    {
        return $this->telefono;
    }

    public function setTelefono(string $telefono): void
    {
        $this->telefono = $telefono;
    }

    public function getDni(): int
    {
        return $this->dni;
    }

    public function setDni(int $dni): void
    {
        $this->dni = $dni;
    }

    public function to_array(): array
    {
        $respuesta = [];

        $respuesta['id'] = $this->getId();
        $respuesta['dni'] = $this->getDni();
        $respuesta['nombre'] = $this->getNombre();
        $respuesta['apellido'] = $this->getApellido();
        $respuesta['id_negocio'] = $this->getIdNegocio();
        $respuesta['telefono'] = $this->getTelefono();

        return $respuesta;
    }
}
