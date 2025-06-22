<?php

namespace App\Service;

use App\DTO\Products\AddProductDTO;
use App\DTO\Products\AddStockDTO;
use App\DTO\Products\DeleteProductDTO;
use App\DTO\Products\OneProductDTO;
use App\Repository\ProductsRepository;

class ProductsService
{
    protected $rep_products;

    public function __construct(ProductsRepository $rep_products)
    {
        $this->rep_products = $rep_products;
    }

    public function list_products(int $id_local)
    {
        return $this->rep_products->list_products($id_local);
    }

    public function add_product(AddProductDTO $dto)
    {
        return $this->rep_products->add_product($dto, $this->get_fecha_hora_actual());
    }

    public function del_product(DeleteProductDTO $dto)
    {
        return $this->rep_products->del_product($dto);
    }

    public function one_product(OneProductDTO $dto)
    {
        $dto->setCode(str_replace(';', '-', $dto->getCode()));
        return $this->rep_products->one_product($dto);
    }

    public function products_price_percentage(float $percentage, int $id_negocio, int $proveedor)
    {
        $this->rep_products->actualizar_cta_cte($percentage, $id_negocio, $proveedor);
        return $this->rep_products->products_price_percentage($percentage, $id_negocio, $proveedor, $this->get_fecha_hora_actual());
    }

    public function add_stock_product(AddStockDTO $dto)
    {
        return $this->rep_products->add_products_stcok($dto, $this->get_fecha_hora_actual());
    }

    public function add_stock_product_edit(AddStockDTO $dto)
    {
        return $this->rep_products->add_products_stock_edit($dto, $this->get_fecha_hora_actual());
    }

    public function trasladar_product(array $datos_dto)
    {
        $productos = [];
        foreach ($datos_dto as $prod) {
            $producto_buscado = $this->rep_products->get_one_product_traslado($prod);
            if (!$producto_buscado) { //el producto no existe en la sucursal
                $this->rep_products->crear_producto_sucursal($prod);
                $this->rep_products->actualizar_stock_sucursal_anterior($prod); //sucursal anterior
            } else { //el producto existe en la sucursal
                $this->rep_products->actualizar_stock_sucursal_nueva($prod); //sucursal nueva                
                $this->rep_products->actualizar_stock_sucursal_anterior($prod); //sucursal anterior                
            }
            $this->rep_products->eliminar_producto_sin_stock($prod);
        }
        return true;
    }

    private function get_fecha_hora_actual(): string
    {
        $timezone = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $dateTime = new \DateTime('now', $timezone);
        $fechaHoraArgentina = $dateTime->format('Y-m-d H:i:s');
        return $fechaHoraArgentina;
    }

    public function one_product_sin_token(OneProductDTO $dto)
    {
        $dto->setCode(str_replace(';', '-', $dto->getCode()));
        return $this->rep_products->one_product_sin_token($dto);
    }
}
