<?php

namespace App\Service;

use App\DTO\SalesProduct\DeleteSalesProductDTO;
use App\DTO\SalesProduct\RegisterSalesProductDTO;
use App\Repository\SalesProductRepository;

class SalesProductService
{
    /** Formas de pago que se pueden asignar al editar una venta ("Cta Cte" queda afuera). */
    public const FORMAS_PAGO_EDITABLES = ['efectivo', 'tarjeta', 'dni', 'mercadopago'];

    protected $rep_salesProduct;

    public function __construct(SalesProductRepository $rep_salesProduct)
    {
        $this->rep_salesProduct = $rep_salesProduct;
    }

    public function obtenerIdNegocioVenta(int $idSaleProduct): ?int
    {
        return $this->rep_salesProduct->obtenerIdNegocioVenta($idSaleProduct);
    }

    /**
     * @return bool|string true si se guardó, "duplicada" si esa clave ya se había registrado.
     */
    public function save_salesProduct(array $datosDto, ?string $clave = null)
    {
        return $this->rep_salesProduct->save_salesProduct($datosDto, $clave);
    }

    public function delete_salesProduct(DeleteSalesProductDTO $dto)
    {
        return $this->rep_salesProduct->delete_salesProduct($dto);
    }

    public function register_salesProduct(RegisterSalesProductDTO $dto)
    {
        return $this->rep_salesProduct->register_salesProduct($dto);
    }

    public function cambiarFormaPago(int $idSale, string $typePayment, int $idNegocio): bool
    {
        return $this->rep_salesProduct->cambiarFormaPago($idSale, $typePayment, $idNegocio);
    }
}
