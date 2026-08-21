<?php

namespace App\DTO\Negocio;


class EliminarNegocioDTO
{

    protected $id_negocio;
    protected $sucursal;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['id_negocio'] = $this->getIdNegocio();
        $resultado['sucursal'] = $this->getSucursal();
        return $resultado;
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
}
