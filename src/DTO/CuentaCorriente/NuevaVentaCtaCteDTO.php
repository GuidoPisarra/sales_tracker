<?php

namespace App\DTO\CuentaCorriente;

use App\DTO\CuentaCorriente\ClienteDTO;

class NuevaVentaCtaCteDTO
{
    protected $cliente;
    protected $venta;

    public function getCliente(): ?ClienteDTO
    {
        return $this->cliente;
    }

    public function setCliente(ClienteDTO $cliente): void
    {
        $this->cliente = $cliente;
    }

    public function getVenta()
    {
        return $this->venta;
    }

    public function setVenta(array $venta): void
    {
        $this->venta = $venta;
    }


    public function to_array(): array
    {
        $respuesta = [];

        $respuesta['cliente'] = $this->getCliente();
        $respuesta['venta'] = $this->getVenta();


        return $respuesta;
    }
}
