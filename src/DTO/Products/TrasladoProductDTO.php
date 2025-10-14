<?php

namespace App\DTO\Products;


class TrasladoProductDTO
{
    protected $id;
    protected $description;
    protected $cost_price;
    protected $sale_price;
    protected $quantity;
    protected $id_proveedor;
    protected $id_negocio;
    protected $code;
    protected $code_canvas;
    protected $size;
    protected $activo;
    protected $sucursal;
    protected $sucursalNueva;


    public function to_array(): array
    {
        $result = [];
        $result['id'] = $this->getId();
        $result['description'] = $this->getDescription();
        $result['cost_price'] = $this->getCostPrice();
        $result['sale_price'] = $this->getSalePrice();
        $result['quantity'] = $this->getQuantity();
        $result['id_proveedor'] = $this->getIdProveedor();
        $result['id_negocio'] = $this->getIdNegocio();
        $result['code'] = $this->getCode();
        $result['code_canvas'] = $this->getCodeCanvas();
        $result['size'] = $this->getSize();
        $result['activo'] = $this->getActivo();
        $result['sucursal'] = $this->getSucursal();
        $result['sucursalNueva'] = $this->getSucursalNueva();
        return $result;
    }

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

    public function setIdNegocio(int $id_negocio): void
    {
        $this->id_negocio = $id_negocio;
    }

    public function getIdNegocio(): int
    {
        return $this->id_negocio;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCodeCanvas(string $code): void
    {
        $this->code_canvas = $code;
    }

    public function getCodeCanvas(): string
    {
        return $this->code_canvas;
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

    public function getActivo(): ?int
    {
        return $this->activo;
    }

    public function setSucursal(int $sucursal): void
    {
        $this->sucursal = $sucursal;
    }

    public function getSucursal(): int
    {
        return $this->sucursal;
    }

    public function setSucursalNueva(int $sucursalNueva): void
    {
        $this->sucursalNueva = $sucursalNueva;
    }

    public function getSucursalNueva(): int
    {
        return $this->sucursalNueva;
    }
}
