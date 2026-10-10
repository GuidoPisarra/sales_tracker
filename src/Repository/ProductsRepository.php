<?php

namespace App\Repository;

use App\DTO\Products\AddProductDTO;
use App\DTO\Products\AddStockDTO;
use App\DTO\Products\DeleteProductDTO;
use App\DTO\Products\OneProductDTO;
use App\DTO\Products\ProductDTO;
use App\DTO\Products\TrasladoProductDTO;
use PDO;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Model\Product;


class ProductsRepository extends BaseRepository
{

    /**
     * Negocio real dueño de un producto, para validar antes de modificarlo/borrarlo.
     */
    public function obtenerIdNegocio(int $id_producto): ?int
    {
        $query = $this->get_bbdd()->prepare('SELECT id_negocio FROM product WHERE id = :id');
        $query->bindParam(':id', $id_producto);
        $query->execute();
        $fila = $query->fetch(PDO::FETCH_ASSOC);
        return $fila ? (int) $fila['id_negocio'] : null;
    }

    public function list_products(int $id_local): ?array
    {
        $query = $this->get_bbdd()->prepare('SELECT p.id AS id, n.sucursal AS sucursal, n.nombre AS nombreSucursal, p.description AS description,
            p.cost_price AS cost_price, p.sale_price AS sale_price, p.quantity AS quantity,
            p.id_proveedor AS id_proveedor, p.code AS code, p.size AS size, p.activo AS activo, p.codigo_interno AS codigoInterno, fecha_actualizado as fechaActualizado, p.barcode AS barcode
        FROM product p
        INNER JOIN negocio n ON n.id_negocio = p.id_negocio AND n.sucursal = p.sucursal
        WHERE p.activo = :activo AND p.id_negocio = :local 
        ORDER BY p.description ASC ');

        $activo = 0;
        $query->bindParam(':activo', $activo);
        $query->bindParam(':local', $id_local);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $products = $query->fetchAll();

        if (!$products) {
            return [];
        }

        return $products;
    }

    public function add_product(AddProductDTO $dto, string $fecha): bool
    {

        $query = $this->get_bbdd()->prepare('INSERT INTO product
        (description, cost_price, sale_price, quantity, id_proveedor, code, size, activo, id_negocio, sucursal, fecha_actualizado, barcode)
        VALUES (:description, :costPrice, :salePrice, :quantity, :idProveedor, :code, :size, :activo, :id_negocio, :id_sucursal, :fecha, :barcode)');

        $newProduct = $dto->to_array();
        $activo = 0;
        $query->bindParam(':description', $newProduct["description"]);
        $query->bindParam(':costPrice', $newProduct["costPrice"]);
        $query->bindParam(':salePrice', $newProduct["salePrice"]);
        $query->bindParam(':quantity', $newProduct["quantity"]);
        $query->bindParam(':idProveedor', $newProduct["idProveedor"]);
        $query->bindParam(':code', $newProduct["code"]);
        $query->bindParam(':size', $newProduct["size"]);
        $query->bindParam(':activo', $activo);
        $query->bindParam(':id_negocio', $newProduct["id_negocio"]);
        $query->bindParam(':id_sucursal', $newProduct["id_sucursal"]);
        $query->bindParam(':fecha', $fecha);
        $query->bindParam(':barcode', $newProduct["barcode"]);

        $response = $query->execute();
        if (!$response && $query->errorInfo()[1] === 1062) {
            throw new \Exception('Ya existe un producto con ese código de barras en este negocio.');
        }
        return $response;
    }

    public function del_product(DeleteProductDTO $dto): bool
    {
        $query = $this->get_bbdd()->prepare('DELETE  FROM product WHERE id = :id');

        $newProduct = $dto->to_array();
        $query->bindParam(':id', $newProduct["id"]);


        $response = $query->execute();
        return $response;
    }

    public function one_product(OneProductDTO $dto): array
    {
        $query = $this->get_bbdd()->prepare('SELECT id id, description description,cost_price costPrice,sale_price salePrice,quantity quantity,id_proveedor id_proveedor,code code,size size,activo activo, fecha_actualizado as fechaActualizado FROM product WHERE  code = :code AND activo=0 AND id_negocio = :id_negocio AND sucursal = :sucursal');

        $newProduct = $dto->to_array();
        $query->bindParam(':code', $newProduct["code"]);
        $query->bindParam(':id_negocio', $newProduct["id_negocio"]);
        $query->bindParam(':sucursal', $newProduct["sucursal"]);

        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $product = $query->fetch();
        if (!$product) {
            return [];
        }

        return $product;
    }

    public function products_price_percentage(float $percentage, int $id_negocio, int $proveedor, string $fecha): bool
    {
        $percent = (float) $percentage;
        $negocio = $id_negocio;
        $query_proveedor = ($proveedor !== 0) ? ' AND id_proveedor = :proveedor' : '';

        $query = $this->get_bbdd()->prepare("UPDATE product p SET p.sale_price =CEIL( p.sale_price +((p.sale_price  * :percent )/100)), p.fecha_actualizado = :fecha WHERE p.activo = 0 AND id_negocio = :id_negocio" . $query_proveedor);

        $query->bindParam(':percent', $percent);
        $query->bindParam(':id_negocio', $negocio);
        $query->bindParam(':fecha', $fecha);
        if ($proveedor !== 0) {
            $query->bindParam(':proveedor', $proveedor);
        }
        $response = $query->execute();


        return $response;
    }

    public function actualizar_cta_cte(float $percentage, int $id_negocio, int $proveedor): bool
    {
        $percent = (float) $percentage;
        $negocio = $id_negocio;
        $query_proveedor = ($proveedor !== 0) ? ' AND id_proveedor = :proveedor' : '';

        $query = $this->get_bbdd()->prepare("UPDATE ctacte cuenta SET cuenta.precio_actual =CEIL( cuenta.precio_actual +((cuenta.precio_actual  * :percent )/100)) WHERE id_negocio = :id_negocio" . $query_proveedor);

        $query->bindParam(':percent', $percent);
        $query->bindParam(':id_negocio', $negocio);
        if ($proveedor !== 0) {
            $query->bindParam(':proveedor', $proveedor);
        }
        $response = $query->execute();


        return $response;
    }



    /**
     * Carga de stock (reponer mercadería): solo suma cantidad. No toca cost_price/sale_price ni
     * fecha_actualizado — eso es responsabilidad de add_products_stock_edit(), que es la edición
     * real del producto. Si este método tocara el precio, reponer stock sacaría productos de
     * "precios desactualizados" sin que el precio se haya revisado de verdad.
     */
    public function add_products_stcok(AddStockDTO $dto): bool
    {
        $query = $this->get_bbdd()->prepare('UPDATE product p SET p.quantity = p.quantity + :quantity WHERE p.id = :id AND p.activo = 0');

        $newProduct = $dto->to_array();
        $query->bindParam(':id', $newProduct["id"]);
        $query->bindParam(':quantity', $newProduct["quantity"]);

        $response = $query->execute();
        return $response;
    }

    public function add_products_stock_edit(AddStockDTO $dto, string $fecha): bool
    {
        $query = $this->get_bbdd()->prepare('UPDATE product p SET p.quantity = :quantity, p.cost_price = :costo, p.sale_price = :venta, p.description = :description, p.barcode = :barcode, fecha_actualizado = :fecha WHERE p.id = :id AND p.activo = 0');

        $newProduct = $dto->to_array();
        $activo = 0;
        $query->bindParam(':id', $newProduct["id"]);
        $query->bindParam(':quantity', $newProduct["quantity"]);
        $query->bindParam(':costo', $newProduct["costPrice"]);
        $query->bindParam(':venta', $newProduct["salePrice"]);
        $query->bindParam(':description', $newProduct["description"]);
        $query->bindParam(':barcode', $newProduct["barcode"]);
        $query->bindParam(':fecha', $fecha);

        $response = $query->execute();
        if (!$response && $query->errorInfo()[1] === 1062) {
            throw new \Exception('Ya existe un producto con ese código de barras en este negocio.');
        }
        return $response;
    }

    public function get_one_product(TrasladoProductDTO $prod)
    {
        $query = $this->get_bbdd()->prepare('SELECT id id, description description,sucursal sucursal,cost_price costPrice,sale_price salePrice,quantity quantity,id_proveedor id_proveedor,code code,size size,activo activo FROM product WHERE  code = :code AND activo=0 AND id_negocio = :id_negocio AND sucursal = :sucursal_nueva');

        $prod = $prod->to_array();
        $query->bindParam(':id_negocio', $prod['id_negocio']);
        $query->bindParam(':code', $prod['code']);
        $query->bindParam(':sucursal_nueva', $prod['sucursalNueva']);

        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $product = $query->fetch();

        return $product;
    }

    /**
     * Traslada un producto de una sucursal a otra: todo en una sola transacción (misma
     * conexión reutilizada en cada paso) para que no quede stock duplicado o perdido si algo
     * falla a mitad de camino. Reemplaza a get_one_product_traslado/crear_producto_sucursal/
     * actualizar_stock_sucursal_nueva/actualizar_stock_sucursal_anterior/
     * eliminar_producto_sin_stock, que antes se llamaban sueltas desde el Service (cada una
     * con su propia conexión, sin transacción posible entre ellas).
     */
    public function realizarTraslado(array $datosDto): bool
    {
        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            foreach ($datosDto as $dto) {
                $prod = $dto->to_array();

                $queryBuscar = $pdo->prepare('SELECT id id, description description,sucursal sucursal,cost_price costPrice,sale_price salePrice,quantity quantity,id_proveedor id_proveedor,code code,size size,activo activo FROM product WHERE  code = :code  AND id_negocio = :id_negocio AND sucursal = :sucursal_nueva');
                $queryBuscar->bindParam(':id_negocio', $prod['id_negocio']);
                $queryBuscar->bindParam(':code', $prod['code']);
                $queryBuscar->bindParam(':sucursal_nueva', $prod['sucursalNueva']);
                $queryBuscar->execute();
                $queryBuscar->setFetchMode(PDO::FETCH_ASSOC);
                $productoBuscado = $queryBuscar->fetch();

                if (!$productoBuscado) {
                    // No existe en la sucursal destino: se crea ahí.
                    $queryCrear = $pdo->prepare('INSERT INTO product
                    (description, cost_price, sale_price, quantity, id_proveedor, code, size, activo, id_negocio, sucursal)
                    VALUES (:description, :costPrice, :salePrice, :quantity, :idProveedor, :code, :size, :activo, :id_negocio, :sucursal)');
                    $activo = 0;
                    $queryCrear->bindParam(':description', $prod["description"]);
                    $queryCrear->bindParam(':costPrice', $prod["cost_price"]);
                    $queryCrear->bindParam(':salePrice', $prod["sale_price"]);
                    $queryCrear->bindParam(':quantity', $prod["quantity"]);
                    $queryCrear->bindParam(':idProveedor', $prod["id_proveedor"]);
                    $queryCrear->bindParam(':code', $prod["code"]);
                    $queryCrear->bindParam(':size', $prod["size"]);
                    $queryCrear->bindParam(':activo', $activo);
                    $queryCrear->bindParam(':id_negocio', $prod["id_negocio"]);
                    $queryCrear->bindParam(':sucursal', $prod["sucursalNueva"]);
                    if (!$queryCrear->execute()) {
                        throw new \Exception('No se pudo crear el producto en la sucursal destino.');
                    }
                } else {
                    // Ya existe en la sucursal destino: se le suma el stock trasladado.
                    $queryNueva = $pdo->prepare('UPDATE product p SET p.quantity = p.quantity + :quantity, p.activo = 0 WHERE p.id_negocio= :id_negocio AND p.code = :code AND sucursal = :sucursal');
                    $queryNueva->bindParam(':code', $prod["code"]);
                    $queryNueva->bindParam(':quantity', $prod["quantity"]);
                    $queryNueva->bindParam(':sucursal', $prod["sucursalNueva"]);
                    $queryNueva->bindParam(':id_negocio', $prod['id_negocio']);
                    if (!$queryNueva->execute()) {
                        throw new \Exception('No se pudo actualizar el stock en la sucursal destino.');
                    }
                }

                $queryAnterior = $pdo->prepare('UPDATE product p SET p.quantity = p.quantity - :quantity WHERE p.id = :id AND p.activo = 0 AND sucursal = :sucursal');
                $queryAnterior->bindParam(':id', $prod["id"]);
                $queryAnterior->bindParam(':quantity', $prod["quantity"]);
                $queryAnterior->bindParam(':sucursal', $prod["sucursal"]);
                if (!$queryAnterior->execute()) {
                    throw new \Exception('No se pudo descontar el stock de la sucursal de origen.');
                }

                $queryEliminar = $pdo->prepare('UPDATE product p SET activo = :activo  WHERE p.id = :id  AND sucursal = :sucursal AND p.quantity = 0 ');
                $activoBaja = 1;
                $queryEliminar->bindParam(':id', $prod["id"]);
                $queryEliminar->bindParam(':sucursal', $prod["sucursal"]);
                $queryEliminar->bindParam(':activo', $activoBaja);
                $queryEliminar->execute();
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

    public function one_product_sin_token(OneProductDTO $dto): array
    {
        $query = $this->get_bbdd()->prepare('SELECT description description,sale_price salePrice FROM product WHERE  code = :code AND activo=0 AND id_negocio = :id_negocio');

        $newProduct = $dto->to_array();
        $query->bindParam(':code', $newProduct["code"]);
        $query->bindParam(':id_negocio', $newProduct["id_negocio"]);

        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $product = $query->fetch();
        if (!$product) {
            return [];
        }

        return $product;
    }
}