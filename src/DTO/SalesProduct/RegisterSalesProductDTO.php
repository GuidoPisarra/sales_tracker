<?php

namespace App\DTO\SalesProduct;


class RegisterSalesProductDTO
{
    protected $idSaleProduct;


    public function to_array(): array
    {
        $resultado = [];
        $resultado['idSaleProduct'] = $this->getIdSaleProduct();

        return $resultado;
    }

    public function setIdSaleProduct(int $idSaleProduct): void
    {
        $this->idSaleProduct = $idSaleProduct;
    }

    public function getIdSaleProduct(): int
    {
        return $this->idSaleProduct;
    }
}
