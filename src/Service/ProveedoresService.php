<?php

namespace App\Service;

use App\DTO\Proveedores\AddProveedorDTO;
use App\Repository\ProveedoresRepository;

class ProveedoresService
{
    protected $rep_products;

    public function __construct(ProveedoresRepository $rep_products)
    {
        $this->rep_products = $rep_products;
    }

    public function list_proveedores(int $id_local)
    {
        return $this->rep_products->list_proveedores($id_local);
    }

    public function add_proveedor(AddProveedorDTO $dto)
    {
        return $this->rep_products->add_proveedor($dto);
    }
}
