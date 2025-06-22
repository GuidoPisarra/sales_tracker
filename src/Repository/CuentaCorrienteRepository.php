<?php

namespace App\Repository;

use App\DTO\CuentaCorriente\ClienteDTO;
use App\DTO\CuentaCorriente\PagoDTO;
use PDO;

class CuentaCorrienteRepository extends BaseRepository
{

    public function list_cuentas_corrientes(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT c.id_persona AS idCliente,
            clientes.*,
            COALESCE(SUM(cuentaDeuda.precio_original), 0) AS deuda,
            COALESCE(SUM(pago.entrega), 0) AS pagos
        FROM clientes clientes
        LEFT JOIN ctacte c ON clientes.id = c.id_persona
        LEFT JOIN pagos pago ON pago.id_ctacte = c.id
        LEFT JOIN ctacte cuentaDeuda ON clientes.id = cuentaDeuda.id_persona
        WHERE clientes.id_negocio = :id_negocio
        GROUP BY clientes.id');
        $query->bindParam(':id_negocio', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $expenses = $query->fetchAll();

        if (!$expenses) {
            return [];
        }

        return $expenses;
    }

    public function list_cuentas_corrientes_con_deuda(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT clientes.*, 
                (
                SELECT SUM(pago.entrega) FROM ctacte cuentaDeuda
                INNER JOIN pagos pago ON pago.id_ctacte = cuentaDeuda.id
                ) as pagos,
                (
                SELECT SUM(cta.precio_original) FROM ctacte cta 
                INNER JOIN clientes c ON c.id = cta.id_persona
                ) as deuda
            FROM (
                SELECT MIN(clientes.id) AS cliente_id, clientes.dni
                FROM clientes clientes
                INNER JOIN ctacte cuenta ON clientes.id = cuenta.id_persona
                WHERE clientes.id_negocio = :id_negocio
                GROUP BY clientes.dni
            ) AS clientesUnicos
            INNER JOIN clientes clientes ON clientesUnicos.cliente_id = clientes.id
            INNER JOIN ctacte cuenta ON clientes.id = cuenta.id_persona 

            WHERE clientes.id_negocio = :id_negocio1
            GROUP BY clientes.dni
            HAVING deuda> ifnull(pagos,0)');
        $query->bindParam(':id_negocio', $idNegocio);
        $query->bindParam(':id_negocio1', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $expenses = $query->fetchAll();

        if (!$expenses) {
            return null;
        }

        return $expenses;
    }

    public function add_agregar_venta_cuenta_corriente()
    {
    }

    public function obtener_compras(int $id_cliente): ?array
    {
        $id = $id_cliente;
        $query = $this->get_bbdd()->prepare('SELECT cuenta.id_persona, cuenta.precio_original, cuenta.precio_actual, cuenta.fecha_venta fecha, prod.id_product, p.description, prod.quantity 
        FROM ctacte cuenta
            INNER JOIN sales_product prod ON prod.id_sales_product= cuenta.id_sale_product
            INNER JOIN product p ON p.id = prod.id_product
            WHERE cuenta.id_persona= :id_persona
            ORDER BY fecha DESC');
        $query->bindParam(':id_persona', $id);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $compras = $query->fetchAll();

        if (!$compras) {
            return null;
        }

        return $compras;
    }

    public function obtener_pagos(int $id_cliente): ?array
    {
        $id = $id_cliente;
        $query = $this->get_bbdd()->prepare('SELECT * FROM pagos
            WHERE id_persona =:id_persona
            ORDER BY fecha DESC
            ');
        $query->bindParam(':id_persona', $id);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $pagos = $query->fetchAll();

        if (!$pagos) {
            return null;
        }

        return $pagos;
    }

    public function agregar_pago(PagoDTO $pago)
    {
        $dto = $pago->to_array();
        $query = $this->get_bbdd()->prepare('INSERT INTO pagos 
            (id_persona, id_ctacte, entrega, fecha)
            VALUES (:idPersona, :idCtaCte, :monto, :fecha)');
        $query->bindParam(':idCtaCte', $dto["id_cta_cte"]);
        $query->bindParam(':idPersona', $dto['id_persona']);
        $query->bindParam(':monto', $dto['entrega']);
        $query->bindParam(':fecha', $dto["fecha"]);
        $response = $query->execute();
        return $response;
    }

    public function agregar_cliente(ClienteDTO $pago)
    {
        $dto = $pago->to_array();
        $query = $this->get_bbdd()->prepare('INSERT INTO clientes 
            (id_negocio, dni, apellido, nombre, telefono)
            VALUES (:idNegocio, :dni, :apellido, :nombre, :telefono)');
        $query->bindParam(':idNegocio', $dto["id_negocio"]);
        $query->bindParam(':dni', $dto['dni']);
        $query->bindParam(':apellido', $dto['apellido']);
        $query->bindParam(':nombre', $dto["nombre"]);
        $query->bindParam(':telefono', $dto["telefono"]);
        $response = $query->execute();
        return $response;
    }
}
