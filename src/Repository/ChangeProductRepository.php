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
        FROM saleTrackerTEST.cambios c
        LEFT JOIN saleTrackerTEST.product p ON p.id =  c.id_producto_nuevo
		LEFT JOIN saleTrackerTEST.product pr ON pr.id =  c.id_producto_cambio
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

    public function add_change(ChangeProductDTO $dto): bool
    {
        $query = $this->get_bbdd()->prepare('INSERT INTO cambios 
        (id_producto_cambio, precio_producto_cambio, id_producto_nuevo, precio_producto_nuevo, id_negocio, fecha_cambio)
        VALUES (:id_producto_cambio, :precio_producto_cambio, :id_producto_nuevo, :precio_producto_nuevo, :id_negocio, :fecha_cambio)');

        $newChange = $dto->to_array();
        $query->bindParam(':id_producto_cambio', $newChange["id_producto_cambio"]);
        $query->bindParam(':precio_producto_cambio', $newChange["precio_producto_cambio"]);
        $query->bindParam(':id_producto_nuevo', $newChange["id_producto_nuevo"]);
        $query->bindParam(':precio_producto_nuevo', $newChange["precio_producto_nuevo"]);
        $query->bindParam(':id_negocio', $newChange["id_negocio"]);
        $query->bindParam(':fecha_cambio', $newChange["fecha_cambio"]);


        $response = $query->execute();
        return $response;
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

    public function add_stock(ChangeProductDTO $dto): bool
    {
        $query = $this->get_bbdd()->prepare('UPDATE product p SET p.quantity = p.quantity + :quantity WHERE p.id = :id');

        $newProduct = $dto->to_array();
        $activo = 0;
        $cant = 1;
        $query->bindParam(':id', $newProduct["id_producto_cambio"]);
        $query->bindParam(':quantity', $cant);

        $response = $query->execute();

        return $response;
    }

    public function discount_stock(ChangeProductDTO $dto): bool
    {
        $query = $this->get_bbdd()->prepare('UPDATE product p SET p.quantity = p.quantity - :quantity WHERE p.id = :id ');

        $newProduct = $dto->to_array();
        $activo = 0;
        $cant = 1;
        $query->bindParam(':id', $newProduct["id_producto_nuevo"]);
        $query->bindParam(':quantity', $cant);

        $response = $query->execute();

        return $response;
    }
}
