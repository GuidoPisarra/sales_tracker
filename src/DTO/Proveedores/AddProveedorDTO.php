<?php

namespace App\DTO\Proveedores;


class AddProveedorDTO
{
    protected $nombre;
    protected $telefono;
    protected $calle;
    protected $numero;
    protected $ciudad;
    protected $id_negocio;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['nombre'] = $this->getNombre();
        $resultado['telefono'] = $this->getTelefono();
        $resultado['calle'] = $this->getCalle();
        $resultado['numero'] = $this->getNumero();
        $resultado['ciudad'] = $this->getCiudad();
        $resultado['id_negocio'] = $this->getIdNegocio();

        return $resultado;
    }

    public function setNombre(string $nombre): void
    {
        $this->nombre = $nombre;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setTelefono(int $telefono): void
    {
        $this->telefono = $telefono;
    }

    public function getTelefono(): ?int
    {
        return $this->telefono;
    }

    public function setCalle(string $calle): void
    {
        $this->calle = $calle;
    }

    public function getCalle(): ?string
    {
        return $this->calle;
    }

    public function setNumero(int $numero): void
    {
        $this->numero = $numero;
    }

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setCiudad(string $ciudad): void
    {
        $this->ciudad = $ciudad;
    }

    public function getCiudad(): ?string
    {
        return $this->ciudad;
    }

    public function setIdNegocio(string $id_negocio): void
    {
        $this->id_negocio = $id_negocio;
    }

    public function getIdNegocio(): ?string
    {
        return $this->id_negocio;
    }
}
