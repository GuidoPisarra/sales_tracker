<?php

namespace App\DTO\Negocio;


class NegocioDTO
{
    protected $id;
    protected $id_negocio;
    protected $sucursal;
    protected $nombre;
    protected $domicilio;
    protected $telefono;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['id'] = $this->getId();
        $resultado['id_negocio'] = $this->getIdNegocio();
        $resultado['sucursal'] = $this->getSucursal();
        $resultado['nombre'] = $this->getNombre();
        $resultado['domicilio'] = $this->getDomicilio();
        $resultado['telefono'] = $this->getTelefono();
        return $resultado;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setIdNegocio(int $id_negocio): void
    {
        $this->id_negocio = $id_negocio;
    }

    public function getIdNegocio(): int
    {
        return $this->id_negocio;
    }
    public function setSucursal(string $sucursal): void
    {
        $this->sucursal = $sucursal;
    }

    public function getSucursal(): string
    {
        return $this->sucursal;
    }

    public function setDomicilio(string $domicilio): void
    {
        $this->domicilio = $domicilio;
    }

    public function getDomicilio(): string
    {
        return $this->domicilio;
    }

    public function setTelefono(string $telefono): void
    {
        $this->telefono = $telefono;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }
}
