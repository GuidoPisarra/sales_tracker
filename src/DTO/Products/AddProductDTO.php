<?php

namespace App\DTO\Products;


class AddProductDTO
{
    protected $description;
    protected $costPrice;
    protected $salePrice;
    protected $quantity;
    protected $idProveedor;
    protected $code;
    protected $size;
    protected $idNegocio;
    protected $idSucursal;
    protected $barcode;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['description'] = $this->getDescription();
        $resultado['costPrice'] = $this->getCostPrice();
        $resultado['salePrice'] = $this->getSalePrice();
        $resultado['quantity'] = $this->getQuantity();
        $resultado['idProveedor'] = $this->getIdProveedor();
        $resultado['code'] = $this->getCode();
        $resultado['size'] = $this->getSize();
        $resultado['id_negocio'] = $this->getIdNegocio();
        $resultado['id_sucursal'] = $this->getIdSucursal();
        $resultado['barcode'] = $this->getBarcode();

        return $resultado;
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

    public function setIdNegocio(string $id_negocio): void
    {
        $this->idNegocio = $id_negocio;
    }

    public function getIdNegocio(): string
    {
        return $this->idNegocio;
    }

    public function setIdSucursal(string $id_sucursal): void
    {
        $this->idSucursal = $id_sucursal;
    }

    public function getIdSucursal(): string
    {
        return $this->idSucursal;
    }

    public function setBarcode(?string $barcode): void
    {
        // "" (sin código cargado) se guarda como NULL: así el UNIQUE(id_negocio, barcode)
        // no choca entre dos productos que no tienen código de barras.
        $this->barcode = ($barcode === null || $barcode === '') ? null : $barcode;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }
}
