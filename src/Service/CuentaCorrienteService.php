<?php

namespace App\Service;

use App\DTO\CuentaCorriente\ClienteDTO;
use App\DTO\CuentaCorriente\NuevaVentaCtaCteDTO;
use App\DTO\CuentaCorriente\PagoDTO;
use App\DTO\SalesProduct\AddSalesProductDTO;
use App\Repository\CuentaCorrienteRepository;

class CuentaCorrienteService
{
    protected $rep_cta_cte;
    protected $service_sales_product;

    public function __construct(CuentaCorrienteRepository $rep_cta_cte, SalesProductService $service_sales_product)
    {
        $this->rep_cta_cte = $rep_cta_cte;
        $this->service_sales_product = $service_sales_product;
    }

    public function list_cuentas_corrientes(int $id_negocio)
    {
        return $this->rep_cta_cte->list_cuentas_corrientes($id_negocio);
    }

    public function list_cuentas_corrientes_con_deuda(int $id_negocio)
    {
        return $this->rep_cta_cte->list_cuentas_corrientes_con_deuda($id_negocio);
    }

    public function add_agregar_venta_cuenta_corriente(NuevaVentaCtaCteDTO $dto)
    {
        $datosDto = [];
        $lista_ventas = $dto->getVenta();
        $cliente = $dto->getCliente();
        $zonaHorariaArgentina = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $fechaArgentina = new \DateTime('now', $zonaHorariaArgentina);
        $fechaFormateada = $fechaArgentina->format('Y-m-d H:i:s');
        foreach ($lista_ventas as $venta) {
            $dto = new AddSalesProductDTO();
            $dto->setIdProduct($venta->getIdProduct());
            $dto->setSaleDay($fechaFormateada);
            $dto->setPrice($venta->getPrice());
            $dto->setQuantity($venta->getQuantity());
            $dto->setTypePayment($venta->getTypePayment());
            $dto->setIdNegocio($venta->getNegocio());
            $dto->setSucursal($venta->getSucursal());
            $dto->setIdPersona($cliente->getId());
            array_push($datosDto, $dto);
        }
        return $this->service_sales_product->save_salesProduct($datosDto);
    }

    public function obtener_movimientos_cliente(int $id): array
    {
        $resultado = [];
        $resultado['compras'] = $this->rep_cta_cte->obtener_compras($id);
        $id_persona = $resultado['compras'][0]['id_persona'];
        $resultado['pagos'] = $this->rep_cta_cte->obtener_pagos($id_persona);
        if ($resultado['pagos']) {
            $resultado = $this->ordenar_movimientos($resultado);
        } else {
            $resultado['pagos'] = [];
            $resultado = $this->ordenar_movimientos($resultado);
        }
        return $resultado;
    }

    private function ordenar_movimientos(array $movimientos): array
    {
        $compras = $movimientos['compras'];
        $pagos = $movimientos['pagos'];

        // Combinar y ordenar todos los movimientos por fecha
        $todos_los_movimientos = array_merge($compras, $pagos);
        usort($todos_los_movimientos, function ($a, $b) {
            return strtotime($b['fecha']) - strtotime($a['fecha']);
        });
        return $todos_los_movimientos;
    }

    public function agregar_pago(PagoDTO $pago): bool
    {
        $zonaHorariaArgentina = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $fechaArgentina = new \DateTime('now', $zonaHorariaArgentina);
        $fechaFormateada = $fechaArgentina->format('Y-m-d H:i:s');
        $pago->setFecha($fechaFormateada);
        return $this->rep_cta_cte->agregar_pago($pago);
    }

    public function agregar_cliente(ClienteDTO $cliente): bool
    {
        return $this->rep_cta_cte->agregar_cliente($cliente);
    }
}
