<?php

namespace App\Repository;

use App\DTO\ChangesProduct\ChangeProductDTO;
use PDO;

class ChangeProductRepository extends BaseRepository
{

    public function list_changes(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT c.id_producto_cambio, c.precio_producto_cambio, c.id_producto_nuevo, c.precio_producto_nuevo, c.id_negocio, 
                c.fecha_cambio, p.description descripcion_nuevo, pr.description descripcion_cambio
        FROM cambios c
        LEFT JOIN product p ON p.id =  c.id_producto_nuevo
		LEFT JOIN product pr ON pr.id =  c.id_producto_cambio
        WHERE c.id_negocio = :id_negocio');
        $query->bindParam(':id_negocio', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $changes = $query->fetchAll();

        if (!$changes) {
            return [];
        }

        return $changes;
    }

    /**
     * Registra el cambio y ajusta el stock de los dos productos (+1 al que se devuelve, -1 al
     * nuevo) en una sola transacción — si algo falla a mitad de camino, no queda nada a medio
     * aplicar. También rechaza el cambio si el producto nuevo no tiene stock disponible.
     */
    public function realizarCambio(ChangeProductDTO $dto): bool
    {
        $datos = $dto->to_array();
        $pdo = $this->get_bbdd();

        $pdo->beginTransaction();
        try {
            $queryStock = $pdo->prepare('SELECT quantity FROM product WHERE id = :id FOR UPDATE');
            $queryStock->bindParam(':id', $datos['id_producto_nuevo']);
            $queryStock->execute();
            $fila = $queryStock->fetch(PDO::FETCH_ASSOC);

            if (!$fila || (int) $fila['quantity'] <= 0) {
                throw new \Exception('El producto nuevo no tiene stock disponible.');
            }

            $queryChange = $pdo->prepare('INSERT INTO cambios
                (id_producto_cambio, precio_producto_cambio, id_producto_nuevo, precio_producto_nuevo, id_negocio, fecha_cambio)
                VALUES (:id_producto_cambio, :precio_producto_cambio, :id_producto_nuevo, :precio_producto_nuevo, :id_negocio, :fecha_cambio)');
            $queryChange->bindParam(':id_producto_cambio', $datos['id_producto_cambio']);
            $queryChange->bindParam(':precio_producto_cambio', $datos['precio_producto_cambio']);
            $queryChange->bindParam(':id_producto_nuevo', $datos['id_producto_nuevo']);
            $queryChange->bindParam(':precio_producto_nuevo', $datos['precio_producto_nuevo']);
            $queryChange->bindParam(':id_negocio', $datos['id_negocio']);
            $queryChange->bindParam(':fecha_cambio', $datos['fecha_cambio']);
            if (!$queryChange->execute()) {
                throw new \Exception('No se pudo registrar el cambio.');
            }

            $queryDevuelto = $pdo->prepare('UPDATE product SET quantity = quantity + 1 WHERE id = :id');
            $queryDevuelto->bindParam(':id', $datos['id_producto_cambio']);
            if (!$queryDevuelto->execute()) {
                throw new \Exception('No se pudo actualizar el stock del producto devuelto.');
            }

            $queryNuevo = $pdo->prepare('UPDATE product SET quantity = quantity - 1 WHERE id = :id');
            $queryNuevo->bindParam(':id', $datos['id_producto_nuevo']);
            if (!$queryNuevo->execute()) {
                throw new \Exception('No se pudo actualizar el stock del producto nuevo.');
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
     * Cambio de un producto devuelto por varios productos nuevos, todo en una transacción:
     * +1 de stock al devuelto, -cantidad a cada nuevo (si alguno no tiene stock suficiente se
     * revierte todo). Una fila en "cambios" por unidad nueva, todas con la misma fecha_cambio
     * (así se agrupan al listar) y el precio del devuelto solo en la primera.
     *
     * @param array $productosNuevos [ ['id' => int, 'precio' => float, 'cantidad' => int], ... ]
     */
    public function realizarCambioMultiple(int $idProductoCambio, float $precioProductoCambio, array $productosNuevos, int $idNegocio): bool
    {
        $timezone = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $fechaCambio = (new \DateTime('now', $timezone))->format('Y-m-d H:i:s');

        // Mismo producto elegido más de una vez: se suma la cantidad para validar el stock.
        $cantidadPorProducto = [];
        foreach ($productosNuevos as $nuevo) {
            $cantidadPorProducto[$nuevo['id']] = ($cantidadPorProducto[$nuevo['id']] ?? 0) + $nuevo['cantidad'];
        }

        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            $queryChange = $pdo->prepare('INSERT INTO cambios
                (id_producto_cambio, precio_producto_cambio, id_producto_nuevo, precio_producto_nuevo, id_negocio, fecha_cambio)
                VALUES (:id_producto_cambio, :precio_producto_cambio, :id_producto_nuevo, :precio_producto_nuevo, :id_negocio, :fecha_cambio)');

            $primera = true;
            foreach ($productosNuevos as $nuevo) {
                for ($i = 0; $i < $nuevo['cantidad']; $i++) {
                    $precioDevuelto = $primera ? $precioProductoCambio : 0;
                    $queryChange->bindValue(':id_producto_cambio', $idProductoCambio);
                    $queryChange->bindValue(':precio_producto_cambio', $precioDevuelto);
                    $queryChange->bindValue(':id_producto_nuevo', $nuevo['id']);
                    $queryChange->bindValue(':precio_producto_nuevo', $nuevo['precio']);
                    $queryChange->bindValue(':id_negocio', $idNegocio);
                    $queryChange->bindValue(':fecha_cambio', $fechaCambio);
                    if (!$queryChange->execute()) {
                        throw new \Exception('No se pudo registrar el cambio.');
                    }
                    $primera = false;
                }
            }

            $queryDevuelto = $pdo->prepare('UPDATE product SET quantity = quantity + 1 WHERE id = :id AND id_negocio = :id_negocio');
            $queryDevuelto->bindValue(':id', $idProductoCambio);
            $queryDevuelto->bindValue(':id_negocio', $idNegocio);
            if (!$queryDevuelto->execute()) {
                throw new \Exception('No se pudo actualizar el stock del producto devuelto.');
            }

            $queryNuevo = $pdo->prepare('UPDATE product SET quantity = quantity - :cantidad
                WHERE id = :id AND id_negocio = :id_negocio AND quantity >= :cantidadMinima');
            foreach ($cantidadPorProducto as $idNuevo => $cantidad) {
                $queryNuevo->bindValue(':cantidad', $cantidad, PDO::PARAM_INT);
                $queryNuevo->bindValue(':cantidadMinima', $cantidad, PDO::PARAM_INT);
                $queryNuevo->bindValue(':id', $idNuevo);
                $queryNuevo->bindValue(':id_negocio', $idNegocio);
                $queryNuevo->execute();
                if ($queryNuevo->rowCount() === 0) {
                    throw new \Exception('Uno de los productos nuevos no tiene stock suficiente.');
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

    public function report_changes(int $month, int $year, int $id_negocio): ?float
    {
        $query = $this->get_bbdd()->prepare(' SELECT COALESCE(SUM(c.precio_producto_cambio - c.precio_producto_nuevo), 0) AS precio_diferencia
            FROM cambios c
            WHERE MONTH(c.fecha_cambio) = :month
            AND YEAR(c.fecha_cambio) = :year
            AND c.id_negocio = :id_negocio');

        $query->bindParam(':month', $month);
        $query->bindParam(':year', $year);
        $query->bindParam(':id_negocio', $id_negocio);

        $query->execute();

        $changes = $query->fetch();

        if (!$changes) {
            return null;
        }

        return $changes[0];
    }
}
