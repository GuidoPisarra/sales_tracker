<?php

namespace App\DTO\ChangesProduct;

use Symfony\Component\Validator\Constraints\Timezone;

class ChangeProductDTO
{
    protected $id_producto_cambio;
    protected $precio_producto_cambio;
    protected $id_producto_nuevo;
    protected $precio_producto_nuevo;
    protected $id_negocio;
    protected $fecha_cambio;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['id_producto_cambio'] = $this->getIdProductoCambio();
        $resultado['precio_producto_cambio'] = $this->getPrecioProductoCambio();
        $resultado['id_producto_nuevo'] = $this->getIdProductoNuevo();
        $resultado['precio_producto_nuevo'] = $this->getPrecioProductoNuevo();
        $resultado['id_negocio'] = $this->getIdNegocio();
        $timezone = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $dateTime = new \DateTime('now', $timezone);
        $fechaHoraArgentina = $dateTime->format('Y-m-d H:i:s');
        $resultado['fecha_cambio'] = $fechaHoraArgentina;
        return $resultado;
    }

    public function getIdProductoCambio(): int
    {
        return $this->id_producto_cambio;
    }

    public function setIdProductoCambio(int $id_cambio): void
    {
        $this->id_producto_cambio = $id_cambio;
    }

    public function getPrecioProductoCambio(): int
    {
        return $this->precio_producto_cambio;
    }

    public function setPrecioProductoCambio(float $precio_cambio): void
    {
        $this->precio_producto_cambio = $precio_cambio;
    }

    public function getIdProductoNuevo(): float
    {
        return $this->id_producto_nuevo;
    }

    public function setIdProductoNuevo(int $id_nuevo): void
    {
        $this->id_producto_nuevo = $id_nuevo;
    }

    public function getPrecioProductoNuevo(): float
    {
        return $this->precio_producto_nuevo;
    }

    public function setPrecioProductoNuevo(float $precio_nuevo): void
    {
        $this->precio_producto_nuevo = $precio_nuevo;
    }

    public function getIdNegocio(): int
    {
        return $this->id_negocio;
    }

    public function setIdNegocio(int $id_negocio): void
    {
        $this->id_negocio = $id_negocio;
    }
}
