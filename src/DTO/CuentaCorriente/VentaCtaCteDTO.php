<?php

namespace App\DTO\CuentaCorriente;


class VentaCtaCteDTO
{
    protected $description;
    protected $id_product;
    protected $negocio;
    protected $price;
    protected $quantity;
    protected $subtotal;
    protected $sucursal;
    protected $typePayment;

    public function getDescription(): string
    {
        return $this->description;
    }
    public function setDescription(string $descripcion): void
    {
        $this->description = $descripcion;
    }

    public function getIdProduct(): int
    {
        return $this->id_product;
    }
    public function setIdProduct(int $id_product): void
    {
        $this->id_product = $id_product;
    }

    public function getNegocio(): int
    {
        return $this->negocio;
    }
    public function setNegocio(int $negocio): void
    {
        $this->negocio = $negocio;
    }

    public function getPrice(): float
    {
        return $this->price;
    }
    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }
    public function setSubtotal(float $subtotal): void
    {
        $this->subtotal = $subtotal;
    }

    public function getSucursal(): int
    {
        return $this->sucursal;
    }
    public function setSucursal(int $sucursal): void
    {
        $this->sucursal = $sucursal;
    }

    public function getTypePayment(): string
    {
        return $this->typePayment;
    }
    public function setTypePayment(string $typePayment): void
    {
        $this->typePayment = $typePayment;
    }


    public function to_array(): array
    {
        $respuesta = [];

        $respuesta['description'] = $this->getDescription();
        $respuesta['id_product'] = $this->getIdProduct();
        $respuesta['negocio'] = $this->getNegocio();
        $respuesta['price'] = $this->getPrice();
        $respuesta['quantity'] = $this->getQuantity();
        $respuesta['subtotal'] = $this->getSubTotal();
        $respuesta['sucursal'] = $this->getSucursal();
        $respuesta['typePayment'] = $this->getTypePayment();
        return $respuesta;
    }
}
