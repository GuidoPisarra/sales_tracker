<?php

namespace App\Service;

use App\DTO\SalesProduct\DeleteSalesProductDTO;
use App\DTO\SalesProduct\RegisterSalesProductDTO;
use App\Repository\SalesProductRepository;

class SalesProductService
{
    protected $rep_salesProduct;

    public function __construct(SalesProductRepository $rep_salesProduct)
    {
        $this->rep_salesProduct = $rep_salesProduct;
    }

    public function list_salesProduct()
    {
        return $this->rep_salesProduct->list_salesProduct();
    }

    public function save_salesProduct(array $datosDto)
    {
        return $this->rep_salesProduct->save_salesProduct($datosDto);
    }

    public function delete_salesProduct(DeleteSalesProductDTO $dto)
    {
        return $this->rep_salesProduct->delete_salesProduct($dto);
    }

    public function register_salesProduct(RegisterSalesProductDTO $dto)
    {
        return $this->rep_salesProduct->register_salesProduct($dto);
    }
}
