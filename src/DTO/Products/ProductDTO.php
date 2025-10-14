<?php

namespace App\DTO\Products;

use App\Repository\ProductsRepository;

class ProductDTO
{
    protected $id;
    protected $description;
    protected $cost_price;
    protected $sale_price;
    protected $quantity;
    protected $id_proveedor;
    protected $code;
    protected $size;
    protected $activo;


    public function setId(int $id): void
    {
        $this->id = $id;
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setCostPrice(float $cost): void
    {
        $this->cost_price = $cost;
    }

    public function getCostPrice(): float
    {
        return $this->cost_price;
    }

    public function setSalePrice(float $salePrice): void
    {
        $this->sale_price = $salePrice;
    }

    public function getSalePrice(): float
    {
        return $this->sale_price;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setIdProveedor(int $idProveedor): void
    {
        $this->id_proveedor = $idProveedor;
    }

    public function getIdProveedor(): int
    {
        return $this->id_proveedor;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setSize(string $size): void
    {
        $this->size = $size;
    }

    public function getSize(): string
    {
        return $this->size;
    }

    public function setActivo(int $activo): void
    {
        $this->activo = $activo;
    }

    public function getActivo(): int
    {
        return $this->activo;
    }
}
