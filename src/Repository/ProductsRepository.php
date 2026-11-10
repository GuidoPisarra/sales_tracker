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

    /**
     * Cantidad de productos activos sin stock (quantity <= 0) del negocio.
     * Ojo: en la tabla product, activo = 0 significa "activo" (ver list_products).
     */
    public function contarSinStock(int $id_negocio): int
    {
        $query = $this->get_bbdd()->prepare('SELECT COUNT(*) FROM product WHERE id_negocio = :id_negocio AND activo = 0 AND quantity <= 0');
        $query->bindValue(':id_negocio', $id_negocio, PDO::PARAM_INT);
        $query->execute();
        return (int) $query->fetchColumn();
    }

    public function contarStockBajo(int $id_negocio): int
    {
        $query = $this->get_bbdd()->prepare('SELECT COUNT(*) FROM product WHERE id_negocio = :id_negocio AND activo = 0 AND quantity > 0 AND quantity <= ' . self::STOCK_BAJO);
        $query->execute([':id_negocio' => $id_negocio]);
        return (int) $query->fetchColumn();
    }

    public function contarActivos(int $id_negocio): int
    {
        $query = $this->get_bbdd()->prepare('SELECT COUNT(*) FROM product WHERE id_negocio = :id_negocio AND activo = 0');
        $query->execute([':id_negocio' => $id_negocio]);
        return (int) $query->fetchColumn();
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

    /** Columnas que devuelve la búsqueda paginada: las mismas que list_products. */
    private const COLUMNAS_LISTA = 'p.id AS id, n.sucursal AS sucursal, n.nombre AS nombreSucursal, p.description AS description,
            p.cost_price AS cost_price, p.sale_price AS sale_price, p.quantity AS quantity,
            p.id_proveedor AS id_proveedor, p.code AS code, p.size AS size, p.activo AS activo, p.codigo_interno AS codigoInterno,
            p.fecha_actualizado AS fechaActualizado, p.barcode AS barcode';

    /** Stock a partir del cual un producto deja de contar como "stock bajo". */
    public const STOCK_BAJO = 3;

    /**
     * Búsqueda paginada de productos activos del negocio.
     *
     * Cada palabra de $texto tiene que aparecer en el nombre, el código, el código
     * interno o el código de barras (en cualquier orden). La comparación no distingue
     * mayúsculas ni tildes porque la usa la collation de la tabla (utf8mb4 *_ci).
     *
     * @return array{items: array, total: int}
     */
    public function buscar(int $idNegocio, string $texto, ?int $proveedor, ?string $filtro, ?int $sucursal, int $limite, int $desde): array
    {
        $where = ['p.activo = 0', 'p.id_negocio = :id_negocio'];
        $params = [':id_negocio' => $idNegocio];

        $palabras = array_slice(array_values(array_filter(preg_split('/\s+/', trim($texto)) ?: [], fn ($p) => $p !== '')), 0, 6);
        foreach ($palabras as $i => $palabra) {
            $like = '%' . addcslashes($palabra, '%_\\') . '%';
            $where[] = "(p.description LIKE :q{$i} OR p.code LIKE :q{$i}b OR p.codigo_interno LIKE :q{$i}c OR p.barcode LIKE :q{$i}d)";
            $params[":q{$i}"] = $like;
            $params[":q{$i}b"] = $like;
            $params[":q{$i}c"] = $like;
            $params[":q{$i}d"] = $like;
        }
        if ($proveedor !== null && $proveedor > 0) {
            $where[] = 'p.id_proveedor = :proveedor';
            $params[':proveedor'] = $proveedor;
        }
        if ($sucursal !== null && $sucursal > 0) {
            $where[] = 'p.sucursal = :sucursal';
            $params[':sucursal'] = $sucursal;
        }
        if ($filtro === 'sin_stock') {
            $where[] = 'p.quantity <= 0';
        } elseif ($filtro === 'stock_bajo') {
            $where[] = 'p.quantity > 0 AND p.quantity <= ' . self::STOCK_BAJO;
        }
        $condicion = implode(' AND ', $where);
        $from = 'FROM product p INNER JOIN negocio n ON n.id_negocio = p.id_negocio AND n.sucursal = p.sucursal';

        $pdo = $this->get_bbdd();
        $total = $pdo->prepare("SELECT COUNT(*) $from WHERE $condicion");
        $total->execute($params);

        // Los que no tienen stock van al final. Si el texto es un código
        // exacto, ese producto va primero igual (aunque no tenga stock).
        $orden = '(p.quantity <= 0) ASC, p.description ASC, p.id ASC';
        if (count($palabras) === 1) {
            $orden = '(p.code = :exacto OR p.barcode = :exacto2 OR p.codigo_interno = :exacto3) DESC, ' . $orden;
            $params[':exacto'] = $palabras[0];
            $params[':exacto2'] = $palabras[0];
            $params[':exacto3'] = $palabras[0];
        }
        $query = $pdo->prepare('SELECT ' . self::COLUMNAS_LISTA . " $from WHERE $condicion ORDER BY $orden LIMIT :limite OFFSET :desde");
        foreach ($params as $clave => $valor) {
            $query->bindValue($clave, $valor);
        }
        $query->bindValue(':limite', $limite, PDO::PARAM_INT);
        $query->bindValue(':desde', $desde, PDO::PARAM_INT);
        $query->execute();

        return [
            'items' => $query->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => (int) $total->fetchColumn(),
        ];
    }

    /**
     * Productos del negocio por id (para refrescar solo los que se tocaron).
     */
    public function porIds(int $idNegocio, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->get_bbdd()->prepare('SELECT ' . self::COLUMNAS_LISTA . "
            FROM product p INNER JOIN negocio n ON n.id_negocio = p.id_negocio AND n.sucursal = p.sucursal
            WHERE p.activo = 0 AND p.id_negocio = ? AND p.id IN ($marcas)");
        $query->execute(array_merge([$idNegocio], $ids));
        return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Catálogo liviano para buscar y vender en el dispositivo (Caja, scanner, cambios).
     *
     * Sin $desde devuelve todos los productos activos. Con $desde, solo los que se
     * modificaron después de esa fecha, y en "ids" la lista de ids activos para que
     * el front borre los que ya no existen (los borrados no dejan fila).
     *
     * @return array{productos: array, ids: ?array, servidor: string, completo: bool}
     */
    public function catalogo(int $idNegocio, ?string $desde): array
    {
        $pdo = $this->get_bbdd();
        $servidor = (string) $pdo->query('SELECT DATE_FORMAT(NOW(), "%Y-%m-%d %H:%i:%s")')->fetchColumn();
        $columnas = 'p.id, p.description, p.code, p.codigo_interno AS codigoInterno, p.barcode, p.sale_price AS salePrice,
            p.cost_price AS costPrice, p.quantity, p.sucursal, p.id_proveedor AS idProveedor, p.size';

        try {
            if ($desde !== null) {
                $query = $pdo->prepare("SELECT $columnas FROM product p
                    WHERE p.id_negocio = :n AND p.activo = 0 AND p.modificado >= :desde");
                $query->execute([':n' => $idNegocio, ':desde' => $desde]);
                $productos = $query->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $ids = $pdo->prepare('SELECT id FROM product WHERE id_negocio = :n AND activo = 0');
                $ids->execute([':n' => $idNegocio]);

                return [
                    'productos' => $productos,
                    'ids' => array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []),
                    'servidor' => $servidor,
                    'completo' => false,
                ];
            }
        } catch (\PDOException $e) {
            // Base sin la migración 0003 (no existe "modificado"): se manda todo.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1054) {
                throw $e;
            }
        }

        $query = $pdo->prepare("SELECT $columnas FROM product p WHERE p.id_negocio = :n AND p.activo = 0 ORDER BY p.description");
        $query->execute([':n' => $idNegocio]);

        return [
            'productos' => $query->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'ids' => null,
            'servidor' => $servidor,
            'completo' => true,
        ];
    }

    /**
     * Cambio rápido de precio de venta y/o stock de un producto (edición en la lista).
     */
    public function actualizarRapido(int $idNegocio, int $id, ?float $precio, ?int $stock, string $fecha): bool
    {
        $sets = [];
        $params = [':id' => $id, ':n' => $idNegocio];
        if ($precio !== null) {
            $sets[] = 'sale_price = :precio, fecha_actualizado = :fecha';
            $params[':precio'] = $precio;
            $params[':fecha'] = $fecha;
        }
        if ($stock !== null) {
            $sets[] = 'quantity = :stock';
            $params[':stock'] = $stock;
        }
        if (!$sets) {
            return false;
        }
        $query = $this->get_bbdd()->prepare('UPDATE product SET ' . implode(', ', $sets) . ' WHERE id = :id AND id_negocio = :n AND activo = 0');
        $query->execute($params);
        return true;
    }

    /**
     * Cambia el precio de venta de varios productos del negocio, cada uno con
     * su propio precio (Precios desactualizados). Todo o nada.
     *
     * @param array<int, float> $precios id => precio
     * @return int productos modificados
     */
    public function actualizarPrecios(int $idNegocio, array $precios, string $fecha): int
    {
        $pdo = $this->get_bbdd();
        $query = $pdo->prepare('UPDATE product SET sale_price = :precio, fecha_actualizado = :fecha WHERE id = :id AND id_negocio = :n AND activo = 0');
        $modificados = 0;
        $pdo->beginTransaction();
        try {
            foreach ($precios as $id => $precio) {
                $query->execute([':precio' => $precio, ':fecha' => $fecha, ':id' => $id, ':n' => $idNegocio]);
                $modificados += $query->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $th) {
            $pdo->rollBack();
            throw $th;
        }
        return $modificados;
    }

    /**
     * Edición masiva sobre productos del negocio. $cambios admite:
     *  - porcentaje: sube/baja el precio de venta un % (redondeado hacia arriba)
     *  - precio: fija el precio de venta
     *  - proveedor: cambia el proveedor
     *  - stock: fija el stock
     *
     * @return int productos modificados
     */
    public function editarMasivo(int $idNegocio, array $ids, array $cambios, string $fecha): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        if (!$ids) {
            return 0;
        }
        $sets = [];
        $valores = [];
        if (isset($cambios['porcentaje'])) {
            $sets[] = 'sale_price = CEIL(sale_price + (sale_price * ? / 100))';
            $valores[] = (float) $cambios['porcentaje'];
        } elseif (isset($cambios['precio'])) {
            $sets[] = 'sale_price = ?';
            $valores[] = (float) $cambios['precio'];
        }
        if ($sets) {
            $sets[] = 'fecha_actualizado = ?';
            $valores[] = $fecha;
        }
        if (isset($cambios['proveedor'])) {
            $sets[] = 'id_proveedor = ?';
            $valores[] = (int) $cambios['proveedor'];
        }
        if (isset($cambios['stock'])) {
            $sets[] = 'quantity = ?';
            $valores[] = (int) $cambios['stock'];
        }
        if (!$sets) {
            return 0;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->get_bbdd()->prepare('UPDATE product SET ' . implode(', ', $sets) . " WHERE id_negocio = ? AND activo = 0 AND id IN ($marcas)");
        $query->execute(array_merge($valores, [$idNegocio], $ids));
        return $query->rowCount();
    }

    /**
     * Cuántas filas de una importación serían productos nuevos (para el límite del plan).
     */
    public function contarNuevosImportacion(int $idNegocio, int $sucursal, array $codigos): int
    {
        $codigos = array_values(array_unique(array_filter($codigos, fn ($c) => $c !== '')));
        if (!$codigos) {
            return 0;
        }
        $existentes = 0;
        foreach (array_chunk($codigos, 500) as $lote) {
            $marcas = implode(',', array_fill(0, count($lote), '?'));
            $query = $this->get_bbdd()->prepare("SELECT COUNT(DISTINCT code) FROM product WHERE id_negocio = ? AND sucursal = ? AND activo = 0 AND code IN ($marcas)");
            $query->execute(array_merge([$idNegocio, $sucursal], $lote));
            $existentes += (int) $query->fetchColumn();
        }
        return count($codigos) - $existentes;
    }

    /**
     * Importa productos desde una planilla. Si el código ya existe en esa sucursal se
     * actualiza; si no, se crea. Todo en una transacción: si una fila rompe, no queda
     * la importación a medias.
     *
     * @param array<int, array{code: string, description: string, costPrice: float, salePrice: float, quantity: ?int, idProveedor: ?int, barcode: ?string, size: ?string}> $filas
     * @return array{creados: int, actualizados: int}
     */
    public function importar(int $idNegocio, int $sucursal, array $filas, string $fecha): array
    {
        $pdo = $this->get_bbdd();
        $buscar = $pdo->prepare('SELECT id FROM product WHERE id_negocio = :n AND sucursal = :s AND code = :code AND activo = 0 LIMIT 1');
        $actualizar = $pdo->prepare('UPDATE product SET description = :description, cost_price = :cost, sale_price = :sale,
                quantity = COALESCE(:quantity, quantity), id_proveedor = COALESCE(:proveedor, id_proveedor),
                barcode = COALESCE(:barcode, barcode), size = COALESCE(:size, size), fecha_actualizado = :fecha
            WHERE id = :id');
        $insertar = $pdo->prepare('INSERT INTO product
            (description, cost_price, sale_price, quantity, id_proveedor, code, size, activo, id_negocio, sucursal, fecha_actualizado, barcode)
            VALUES (:description, :cost, :sale, :quantity, :proveedor, :code, :size, 0, :n, :s, :fecha, :barcode)');

        $creados = 0;
        $actualizados = 0;
        $pdo->beginTransaction();
        try {
            foreach ($filas as $fila) {
                $buscar->execute([':n' => $idNegocio, ':s' => $sucursal, ':code' => $fila['code']]);
                $id = $buscar->fetchColumn();
                if ($id) {
                    $actualizar->execute([
                        ':description' => $fila['description'],
                        ':cost' => $fila['costPrice'],
                        ':sale' => $fila['salePrice'],
                        ':quantity' => $fila['quantity'],
                        ':proveedor' => $fila['idProveedor'],
                        ':barcode' => $fila['barcode'],
                        ':size' => $fila['size'],
                        ':fecha' => $fecha,
                        ':id' => (int) $id,
                    ]);
                    $actualizados++;
                } else {
                    $insertar->execute([
                        ':description' => $fila['description'],
                        ':cost' => $fila['costPrice'],
                        ':sale' => $fila['salePrice'],
                        ':quantity' => $fila['quantity'] ?? 0,
                        ':proveedor' => $fila['idProveedor'],
                        ':code' => $fila['code'],
                        ':size' => $fila['size'],
                        ':n' => $idNegocio,
                        ':s' => $sucursal,
                        ':fecha' => $fecha,
                        ':barcode' => $fila['barcode'],
                    ]);
                    $creados++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $th) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($th instanceof \PDOException && (int) ($th->errorInfo[1] ?? 0) === 1062) {
                throw new \RuntimeException('Hay un código de barras repetido en la planilla o que ya usa otro producto.');
            }
            throw $th;
        }

        return ['creados' => $creados, 'actualizados' => $actualizados];
    }
}
