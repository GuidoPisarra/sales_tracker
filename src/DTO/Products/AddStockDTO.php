<?php

namespace App\DTO\Products;


class AddStockDTO
{
    protected $id;
    protected $description;
    protected $costPrice;
    protected $salePrice;
    protected $quantity;
    protected $idProveedor;
    protected $code;
    protected $size;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['id'] = $this->getId();
        $resultado['description'] = $this->getDescription();
        $resultado['costPrice'] = $this->getCostPrice();
        $resultado['salePrice'] = $this->getSalePrice();
        $resultado['quantity'] = $this->getQuantity();
        $resultado['idProveedor'] = $this->getIdProveedor();
        $resultado['code'] = $this->getCode();
        $resultado['size'] = $this->getSize();

        return $resultado;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getId(): string
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
        $this->costPrice = $cost;
    }

    public function getCostPrice(): float
    {
        return $this->costPrice;
    }

    public function setSalePrice(float $salePrice): void
    {
        $this->salePrice = $salePrice;
    }

    public function getSalePrice(): float
    {
        return $this->salePrice;
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
        $this->idProveedor = $idProveedor;
    }

    public function getIdProveedor(): int
    {
        return $this->idProveedor;
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
}
