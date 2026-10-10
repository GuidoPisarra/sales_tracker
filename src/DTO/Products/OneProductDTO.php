<?php

namespace App\DTO\Products;


class OneProductDTO
{
    protected $code;
    protected $idNegocio;
    protected $sucursal = null;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['code'] = $this->getCode();
        $resultado['id_negocio'] = $this->getIdNegocio();
        $resultado['sucursal'] = $this->getSucursal();
        return $resultado;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setIdNegocio(string $id_negocio): void
    {
        $this->idNegocio = $id_negocio;
    }

    public function getIdNegocio(): string
    {
        return $this->idNegocio;
    }

    public function setSucursal(?int $sucursal): void
    {
        $this->sucursal = $sucursal;
    }

    public function getSucursal(): ?int
    {
        return $this->sucursal;
    }
}
