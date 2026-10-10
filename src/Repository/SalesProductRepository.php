<?php

namespace App\Repository;

use App\DTO\SalesProduct\DeleteSalesProductDTO;
use App\DTO\SalesProduct\RegisterSalesProductDTO;
use PDO;

class SalesProductRepository extends BaseRepository
{
    public function list_salesProduct(int $id_negocio): ?array
    {
        $query = $this->get_bbdd()->prepare('SELECT id id, description description,price price,id_sucursal id_sucursal, date_expense date_expense FROM expense WHERE id_negocio = :id_negocio');
        $query->bindParam(':id_negocio', $id_negocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $salesProduct = $query->fetchAll();

        if (!$salesProduct) {
            return null;
        }

        return $salesProduct;
    }

    /**
     * Negocio real dueño de una venta (sales_product), para validar antes de borrarla/registrarla.
     */
    public function obtenerIdNegocioVenta(int $idSaleProduct): ?int
    {
        $query = $this->get_bbdd()->prepare('SELECT id_negocio FROM sales_product WHERE id_sale = :id LIMIT 1');
        $query->bindParam(':id', $idSaleProduct);
        $query->execute();
        $fila = $query->fetch(PDO::FETCH_ASSOC);
        return $fila ? (int) $fila['id_negocio'] : null;
    }

    /**
     * Todo el alta de una venta (sales + sales_product + descuento de stock + ctacte si
     * corresponde) va en una sola transacción: si algo falla a mitad de camino (p. ej. no hay
     * stock de un producto), se revierte todo en vez de dejar ventas parciales ya guardadas.
     */
    public function save_salesProduct(array $datosDto): ?bool
    {
        $active = 0;
        $register = 0;

        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            //insertar en sales y quedarme con el primer id (mismo criterio que antes)
            $idSALE = null;

            foreach ($datosDto as $venta) {
                $newSale = $venta->to_array();

                $querySale = $pdo->prepare('INSERT INTO sales (id_product, id_negocio) VALUES (:idProduct, :idNegocio)');
                $querySale->bindParam(':idProduct', $newSale["idProduct"]);
                $querySale->bindParam(':idNegocio', $newSale["id_negocio"]);
                if (!$querySale->execute()) {
                    throw new \Exception('No se pudo registrar la venta.');
                }
                if ($idSALE === null) {
                    $idSALE = (int) $pdo->lastInsertId();
                }

                $query = $pdo->prepare('INSERT INTO sales_product
                    (id_sale, id_product, quantity, price, sale_product_date, type_payment, active, register,id_negocio,sucursal, id_persona,usuario)
                    VALUES (:idSale, :idProduct, :quantity, :price, :saleDay, :typePayment, :active, :register, :id_negocio, :sucursal, :id_persona,:usuario)');

                $query->bindParam(':idSale', $idSALE);
                $query->bindParam(':idProduct', $newSale["idProduct"]);
                $query->bindParam(':saleDay', $newSale["saleDay"]);
                $query->bindParam(':quantity', $newSale["quantity"]);
                $query->bindParam(':price', $newSale["price"]);
                $query->bindParam(':typePayment', $newSale["typePayment"]);
                $query->bindParam(':active', $active);
                $query->bindParam(':register', $register);
                $query->bindParam(':id_negocio', $newSale["id_negocio"]);
                $query->bindParam(':sucursal', $newSale["sucursal"]);
                $query->bindParam(':id_persona', $newSale["id_persona"]);
                $query->bindParam(':usuario', $newSale["usuario"]);

                if (!$query->execute()) {
                    throw new \Exception('No se pudo registrar el detalle de la venta.');
                }
                $idSALEPRODUCT = (int) $pdo->lastInsertId();

                // Solo descuenta si realmente hay stock suficiente; si no afecta ninguna fila,
                // es porque el stock actual es menor a lo que se quiere vender.
                $queryStock = $pdo->prepare('UPDATE product p SET p.quantity = p.quantity - :quantity WHERE p.id = :id AND p.quantity >= :quantityMinima');
                $queryStock->bindParam(':id', $newSale["idProduct"]);
                $queryStock->bindParam(':quantity', $newSale["quantity"]);
                $queryStock->bindParam(':quantityMinima', $newSale["quantity"]);
                $queryStock->execute();
                if ($queryStock->rowCount() === 0) {
                    throw new \Exception('No hay stock suficiente para completar la venta de uno de los productos.');
                }

                if ($newSale["typePayment"] === 'Cta Cte') {
                    $queryCtaCte = $pdo->prepare('INSERT INTO ctacte
                        (id_producto, id_sale, id_sale_product, id_persona, precio_original, precio_actual, id_negocio, sucursal, fecha_venta)
                        VALUES (:idProduct, :idSale, :idSaleProduct, :id_persona, :priceOriginal, :priceActual, :id_negocio, :sucursal, :saleDay)');

                    $queryCtaCte->bindParam(':idProduct', $newSale["idProduct"]);
                    $queryCtaCte->bindParam(':idSale', $idSALE);
                    $queryCtaCte->bindParam(':idSaleProduct', $idSALEPRODUCT);
                    $queryCtaCte->bindParam(':id_persona', $newSale["id_persona"]);
                    $queryCtaCte->bindParam(':priceOriginal', $newSale["price"]);
                    $queryCtaCte->bindParam(':priceActual', $newSale["price"]);
                    $queryCtaCte->bindParam(':id_negocio', $newSale["id_negocio"]);
                    $queryCtaCte->bindParam(':sucursal', $newSale["sucursal"]);
                    $queryCtaCte->bindParam(':saleDay', $newSale["saleDay"]);
                    if (!$queryCtaCte->execute()) {
                        throw new \Exception('No se pudo registrar la cuenta corriente.');
                    }
                }
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

    public function delete_salesProduct(DeleteSalesProductDTO $dto): ?bool
    {
        $deleteId = $dto->to_array();

        $query = $this->get_bbdd()->prepare('UPDATE sales_product SET active = 1 WHERE id_sale = :idSaleProduct');

        $query->bindParam(':idSaleProduct', $deleteId['idSaleProduct']);
        $delete = $query->execute();

        return $delete;
    }

    public function register_salesProduct(RegisterSalesProductDTO $dto): ?bool
    {
        $deleteId = $dto->to_array();

        $query = $this->get_bbdd()->prepare('UPDATE sales_product SET register = 1 WHERE id_sale = :idSaleProduct');

        $query->bindParam(':idSaleProduct', $deleteId['idSaleProduct']);
        $delete = $query->execute();

        return $delete;
    }
}
