<?php

namespace App\Repository;

use App\DTO\CuentaCorriente\ClienteDTO;
use App\DTO\CuentaCorriente\PagoDTO;
use PDO;

class CuentaCorrienteRepository extends BaseRepository
{

    /**
     * Deuda y pagos se pre-agregan cada uno en su propia sub-query (agrupados por id_persona)
     * antes de unirlos a clientes. Si se hacen como dos LEFT JOIN directos a ctacte (como estaba
     * antes), el cruce entre "N cuentas" x "M pagos" de un mismo cliente infla ambas sumas por
     * ese cruce — acá cada total ya viene sumado 1 vez por cliente antes de juntarse.
     */
    public function list_cuentas_corrientes(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT clientes.id AS idCliente,
            clientes.*,
            COALESCE(deuda_agg.total, 0) AS deuda,
            COALESCE(pagos_agg.total, 0) AS pagos
        FROM clientes clientes
        LEFT JOIN (
            SELECT id_persona, SUM(precio_original) AS total
            FROM ctacte
            WHERE anulado = 0
            GROUP BY id_persona
        ) deuda_agg ON deuda_agg.id_persona = clientes.id
        LEFT JOIN (
            SELECT ctacte.id_persona, SUM(pagos.entrega) AS total
            FROM pagos
            INNER JOIN ctacte ON ctacte.id = pagos.id_ctacte
            WHERE pagos.anulado = 0
            GROUP BY ctacte.id_persona
        ) pagos_agg ON pagos_agg.id_persona = clientes.id
        WHERE clientes.id_negocio = :id_negocio');
        $query->bindParam(':id_negocio', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $expenses = $query->fetchAll();

        if (!$expenses) {
            return [];
        }

        return $expenses;
    }

    /**
     * Antes, "deuda" y "pagos" salían de dos sub-queries sin relación con el cliente de la fila
     * (sumaban TODA la tabla, de todos los negocios) — cualquier cliente veía el mismo número
     * global, y el HAVING no filtraba nada de verdad. Ahora cada total se pre-agrega por
     * id_persona (mismo criterio que list_cuentas_corrientes). Agrupa por id (un cliente = una
     * fila) porque el UNIQUE(dni, id_negocio) de la tabla ya garantiza que no hay duplicados por
     * DNI que haya que fusionar.
     */
    public function list_cuentas_corrientes_con_deuda(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT * FROM (
                SELECT
                    clientes.id AS idCliente,
                    clientes.*,
                    COALESCE(deuda_agg.total, 0) AS deuda,
                    COALESCE(pagos_agg.total, 0) AS pagos
                FROM clientes clientes
                LEFT JOIN (
                    SELECT id_persona, SUM(precio_original) AS total
                    FROM ctacte
                    WHERE anulado = 0
                    GROUP BY id_persona
                ) deuda_agg ON deuda_agg.id_persona = clientes.id
                LEFT JOIN (
                    SELECT ctacte.id_persona, SUM(pagos.entrega) AS total
                    FROM pagos
                    INNER JOIN ctacte ON ctacte.id = pagos.id_ctacte
                    WHERE pagos.anulado = 0
                    GROUP BY ctacte.id_persona
                ) pagos_agg ON pagos_agg.id_persona = clientes.id
                WHERE clientes.id_negocio = :id_negocio
            ) AS cuentas
            WHERE deuda > pagos');
        $query->bindParam(':id_negocio', $idNegocio);
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
        $query = $this->get_bbdd()->prepare('SELECT cuenta.id AS id_cta_cte, cuenta.id_persona, cuenta.precio_original, cuenta.precio_actual, cuenta.fecha_venta fecha, cuenta.anulado, prod.id_product, p.description, prod.quantity
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

    /**
     * Negocio real dueño de una cuenta corriente (ctacte), para validar antes de imputar un pago.
     */
    public function obtenerIdNegocioCtaCte(int $id_cta_cte): ?int
    {
        $query = $this->get_bbdd()->prepare('SELECT id_negocio FROM ctacte WHERE id = :id');
        $query->bindParam(':id', $id_cta_cte);
        $query->execute();
        $fila = $query->fetch(PDO::FETCH_ASSOC);
        return $fila ? (int) $fila['id_negocio'] : null;
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
        if (!$response && $query->errorInfo()[1] === 1062) {
            throw new \Exception('Ya existe un cliente con ese DNI en este negocio.');
        }
        return $response;
    }

    /**
     * Update parcial (PATCH): trae la fila actual y solo pisa los campos que vinieron
     * realmente en el request — así un campo que no se manda no se borra ni queda en NULL.
     */
    public function actualizarCliente(ClienteDTO $cliente): bool
    {
        $datos = $cliente->to_array();

        $queryActual = $this->get_bbdd()->prepare('SELECT dni, apellido, nombre, telefono, limite_credito FROM clientes WHERE id = :id');
        $queryActual->bindParam(':id', $datos['id']);
        $queryActual->execute();
        $actual = $queryActual->fetch(PDO::FETCH_ASSOC);
        if (!$actual) {
            return false;
        }

        $dni = $datos['dni'] ?? $actual['dni'];
        $apellido = $datos['apellido'] ?? $actual['apellido'];
        $nombre = $datos['nombre'] ?? $actual['nombre'];
        $telefono = $datos['telefono'] ?? $actual['telefono'];
        $limiteCredito = $datos['limite_credito'] ?? $actual['limite_credito'];

        $query = $this->get_bbdd()->prepare('UPDATE clientes
            SET dni = :dni, apellido = :apellido, nombre = :nombre, telefono = :telefono, limite_credito = :limite_credito
            WHERE id = :id AND id_negocio = :id_negocio');
        $query->bindParam(':dni', $dni);
        $query->bindParam(':apellido', $apellido);
        $query->bindParam(':nombre', $nombre);
        $query->bindParam(':telefono', $telefono);
        $query->bindParam(':limite_credito', $limiteCredito);
        $query->bindParam(':id', $datos['id']);
        $query->bindParam(':id_negocio', $datos['id_negocio']);
        $response = $query->execute();

        if (!$response && $query->errorInfo()[1] === 1062) {
            throw new \Exception('Ya existe un cliente con ese DNI en este negocio.');
        }
        return $response;
    }

    /**
     * Negocio real dueño de un pago (vía la cuenta corriente a la que pertenece), para validar
     * antes de anularlo.
     */
    public function obtenerIdNegocioPago(int $idPago): ?int
    {
        $query = $this->get_bbdd()->prepare('SELECT ctacte.id_negocio
            FROM pagos
            INNER JOIN ctacte ON ctacte.id = pagos.id_ctacte
            WHERE pagos.id = :id');
        $query->bindParam(':id', $idPago);
        $query->execute();
        $fila = $query->fetch(PDO::FETCH_ASSOC);
        return $fila ? (int) $fila['id_negocio'] : null;
    }

    /**
     * Baja lógica: el pago queda marcado como anulado (no cuenta más para la deuda) pero no se
     * borra, para conservar el rastro de que existió.
     */
    public function anularPago(int $idPago): bool
    {
        $query = $this->get_bbdd()->prepare('UPDATE pagos SET anulado = 1 WHERE id = :id AND anulado = 0');
        $query->bindParam(':id', $idPago);
        $response = $query->execute();
        if ($response && $query->rowCount() === 0) {
            throw new \Exception('El pago no existe o ya estaba anulado.');
        }
        return $response;
    }

    /**
     * Anula una venta a cuenta corriente y devuelve el stock del producto al inventario, en una
     * sola transacción. Se bloquea si ya tiene pagos cargados (hay que anularlos primero) — así
     * nunca queda plata ya cobrada asociada a una cuenta que dejó de existir.
     */
    public function anularVentaCtaCte(int $idCtaCte): bool
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            $queryCtacte = $pdo->prepare('SELECT id_producto, anulado FROM ctacte WHERE id = :id FOR UPDATE');
            $queryCtacte->bindParam(':id', $idCtaCte);
            $queryCtacte->execute();
            $ctacte = $queryCtacte->fetch(PDO::FETCH_ASSOC);

            if (!$ctacte) {
                throw new \Exception('La cuenta corriente no existe.');
            }
            if ((int) $ctacte['anulado'] === 1) {
                throw new \Exception('Esta venta ya estaba anulada.');
            }

            $queryPagos = $pdo->prepare('SELECT COUNT(*) AS cantidad FROM pagos WHERE id_ctacte = :id AND anulado = 0');
            $queryPagos->bindParam(':id', $idCtaCte);
            $queryPagos->execute();
            $pagos = $queryPagos->fetch(PDO::FETCH_ASSOC);
            if ((int) $pagos['cantidad'] > 0) {
                throw new \Exception('No se puede anular: esta cuenta ya tiene pagos cargados. Anulá los pagos primero.');
            }

            $queryAnular = $pdo->prepare('UPDATE ctacte SET anulado = 1 WHERE id = :id');
            $queryAnular->bindParam(':id', $idCtaCte);
            if (!$queryAnular->execute()) {
                throw new \Exception('No se pudo anular la venta.');
            }

            $queryStock = $pdo->prepare('UPDATE product SET quantity = quantity + 1 WHERE id = :id_producto');
            $queryStock->bindParam(':id_producto', $ctacte['id_producto']);
            if (!$queryStock->execute()) {
                throw new \Exception('No se pudo devolver el stock del producto.');
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $th) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $th;
        }
    }

    /**
     * Límite de crédito del cliente. null = sin límite cargado (no se bloquea ninguna venta).
     */
    public function obtenerLimiteCredito(int $idCliente): ?float
    {
        $query = $this->get_bbdd()->prepare('SELECT limite_credito FROM clientes WHERE id = :id');
        $query->bindParam(':id', $idCliente);
        $query->execute();
        $fila = $query->fetch(PDO::FETCH_ASSOC);
        if (!$fila || $fila['limite_credito'] === null) {
            return null;
        }
        return (float) $fila['limite_credito'];
    }

    /**
     * Deuda neta actual del cliente (cuentas activas - pagos activos), para chequear contra el
     * límite de crédito antes de sumarle una venta nueva.
     */
    public function obtenerDeudaActual(int $idCliente): float
    {
        $queryDeuda = $this->get_bbdd()->prepare('SELECT COALESCE(SUM(precio_original), 0) AS total FROM ctacte WHERE id_persona = :id AND anulado = 0');
        $queryDeuda->bindParam(':id', $idCliente);
        $queryDeuda->execute();
        $deuda = (float) $queryDeuda->fetch(PDO::FETCH_ASSOC)['total'];

        $queryPagos = $this->get_bbdd()->prepare('SELECT COALESCE(SUM(pagos.entrega), 0) AS total
            FROM pagos
            INNER JOIN ctacte ON ctacte.id = pagos.id_ctacte
            WHERE ctacte.id_persona = :id AND pagos.anulado = 0');
        $queryPagos->bindParam(':id', $idCliente);
        $queryPagos->execute();
        $pagos = (float) $queryPagos->fetch(PDO::FETCH_ASSOC)['total'];

        return $deuda - $pagos;
    }
}
