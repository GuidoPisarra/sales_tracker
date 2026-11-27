<?php

namespace App\Repository;

use App\DTO\SalesProduct\DeleteSalesProductDTO;
use App\DTO\SalesProduct\RegisterSalesProductDTO;
use PDO;

class SalesProductRepository extends BaseRepository
{

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
    /**
     * @param bool[] $forzarStock por posición del carrito: true si el vendedor confirmó
     *   "vender igual" aunque el sistema dijera que no había stock.
     * @param bool $sinConexion la venta se hizo sin internet y llega después: ya ocurrió,
     *   así que nunca se rechaza por stock.
     *
     * Con stock forzado (o sin conexión) el stock baja hasta 0, nunca a negativo, y lo que
     * faltó se anota en stock_faltante (ver faltantes()). Las faltas quedan en $this->ultimosFaltantes.
     */
    public function save_salesProduct(array $datosDto, ?string $clave = null, array $forzarStock = [], bool $sinConexion = false)
    {
        $this->ultimosFaltantes = [];
        $active = 0;
        $register = 0;

        $pdo = $this->get_bbdd();
        $pdo->beginTransaction();
        try {
            // Venta con clave (la manda el front, p. ej. al reintentar una venta hecha sin
            // conexión): si esa clave ya está, la venta ya se guardó y no se duplica.
            if ($clave !== null && $datosDto) {
                try {
                    $pdo->prepare('INSERT INTO venta_clave (id_negocio, clave) VALUES (:n, :c)')
                        ->execute([':n' => $datosDto[0]->to_array()['id_negocio'], ':c' => $clave]);
                } catch (\PDOException $e) {
                    $codigo = (int) ($e->errorInfo[1] ?? 0);
                    if ($codigo === 1062) {
                        $pdo->rollBack();
                        return 'duplicada';
                    }
                    if ($codigo !== 1146) { // 1146: base sin la migración 0003, se sigue sin clave.
                        throw $e;
                    }
                }
            }

            //insertar en sales y quedarme con el primer id (mismo criterio que antes)
            $idSALE = null;

            // Se preparan una sola vez y se reusan por cada producto del carrito.
            $querySale = $pdo->prepare('INSERT INTO sales (id_product, id_negocio) VALUES (:idProduct, :idNegocio)');
            $query = $pdo->prepare('INSERT INTO sales_product
                    (id_sale, id_product, quantity, price, sale_product_date, type_payment, active, register,id_negocio,sucursal, id_persona,usuario)
                    VALUES (:idSale, :idProduct, :quantity, :price, :saleDay, :typePayment, :active, :register, :id_negocio, :sucursal, :id_persona,:usuario)');
            $queryStock = $pdo->prepare('UPDATE product p SET p.quantity = p.quantity - :quantity WHERE p.id = :id AND p.id_negocio = :id_negocio AND p.quantity >= :quantityMinima');
            $queryStockActual = $pdo->prepare('SELECT quantity, description FROM product WHERE id = :id AND id_negocio = :id_negocio FOR UPDATE');
            $queryStockHastaCero = $pdo->prepare('UPDATE product SET quantity = GREATEST(quantity - :quantity, 0) WHERE id = :id AND id_negocio = :id_negocio');
            $queryCtaCte = $pdo->prepare('INSERT INTO ctacte
                        (id_producto, id_sale, id_sale_product, id_persona, precio_original, precio_actual, id_negocio, sucursal, fecha_venta)
                        VALUES (:idProduct, :idSale, :idSaleProduct, :id_persona, :priceOriginal, :priceActual, :id_negocio, :sucursal, :saleDay)');

            foreach ($datosDto as $posicion => $venta) {
                $newSale = $venta->to_array();

                $querySale->bindParam(':idProduct', $newSale["idProduct"]);
                $querySale->bindParam(':idNegocio', $newSale["id_negocio"]);
                if (!$querySale->execute()) {
                    throw new \Exception('No se pudo registrar la venta.');
                }
                if ($idSALE === null) {
                    $idSALE = (int) $pdo->lastInsertId();
                }

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

                // Solo descuenta si el producto es del negocio del usuario y hay stock
                // suficiente; si no afecta ninguna fila, es por una de las dos razones.
                if ($sinConexion || !empty($forzarStock[$posicion])) {
                    $this->descontarHastaCero($pdo, $queryStockActual, $queryStockHastaCero, $newSale, $idSALE, $sinConexion);
                } else {
                    $queryStock->bindParam(':id', $newSale["idProduct"]);
                    $queryStock->bindParam(':id_negocio', $newSale["id_negocio"]);
                    $queryStock->bindParam(':quantity', $newSale["quantity"]);
                    $queryStock->bindParam(':quantityMinima', $newSale["quantity"]);
                    $queryStock->execute();
                    if ($queryStock->rowCount() === 0) {
                        throw new \Exception('No se pudo descontar el stock: el producto no existe en este negocio o no hay stock suficiente.');
                    }
                }

                if ($newSale["typePayment"] === 'Cta Cte') {
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

    /** Faltantes de stock de la última venta guardada: [{id_product, description, faltante}]. */
    public array $ultimosFaltantes = [];

    /**
     * Descuenta el stock sin rechazar la venta: baja hasta 0 y anota lo que faltó.
     * El producto tiene que existir y ser del negocio (eso sí se sigue validando).
     */
    private function descontarHastaCero(\PDO $pdo, \PDOStatement $actual, \PDOStatement $hastaCero, array $venta, int $idSale, bool $sinConexion): void
    {
        $actual->execute([':id' => $venta['idProduct'], ':id_negocio' => $venta['id_negocio']]);
        $producto = $actual->fetch(\PDO::FETCH_ASSOC);
        $actual->closeCursor();
        if (!$producto) {
            throw new \Exception('No se pudo descontar el stock: el producto no existe en este negocio.');
        }
        $stockAntes = (int) $producto['quantity'];
        $cantidad = (int) $venta['quantity'];
        $hastaCero->execute([':quantity' => $cantidad, ':id' => $venta['idProduct'], ':id_negocio' => $venta['id_negocio']]);

        $faltante = $cantidad - max($stockAntes, 0);
        if ($faltante <= 0) {
            return;
        }
        try {
            $pdo->prepare('INSERT INTO stock_faltante
                    (id_negocio, sucursal, id_product, id_sale, cantidad_vendida, stock_antes, faltante, sin_conexion, usuario, fecha_venta)
                    VALUES (:n, :s, :p, :v, :c, :antes, :f, :off, :u, :fecha)')
                ->execute([
                    ':n' => $venta['id_negocio'],
                    ':s' => $venta['sucursal'] ?: 1,
                    ':p' => $venta['idProduct'],
                    ':v' => $idSale,
                    ':c' => $cantidad,
                    ':antes' => $stockAntes,
                    ':f' => $faltante,
                    ':off' => $sinConexion ? 1 : 0,
                    ':u' => $venta['usuario'] ?: null,
                    ':fecha' => $venta['saleDay'],
                ]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1146) { // 1146: base sin la migración 0007.
                throw $e;
            }
        }
        $this->ultimosFaltantes[] = [
            'id_product' => (int) $venta['idProduct'],
            'description' => (string) $producto['description'],
            'faltante' => $faltante,
        ];
    }

    /**
     * Notificación para los administradores del negocio: se vendió algo que según el
     * sistema no tenía stock. Si falla, la venta ya está guardada y no se toca.
     */
    public function avisarFaltantes(int $idNegocio, array $faltantes, bool $sinConexion): void
    {
        try {
            $pdo = $this->get_bbdd();
            $q = $pdo->prepare("SELECT id FROM user WHERE id_negocio = :n AND role LIKE '%ROLE_TITULAR%' AND (eliminado = 0 OR eliminado IS NULL)");
            $q->execute([':n' => $idNegocio]);
            $titulares = $q->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $nombres = array_map(fn ($f) => $f['description'] . ' (faltaron ' . $f['faltante'] . ')', $faltantes);
            $mensaje = ($sinConexion ? 'En una venta hecha sin conexión se vendió' : 'Se vendió')
                . ' sin stock en el sistema: ' . implode(', ', $nombres)
                . '. El stock quedó en 0. Revisalo en Stock > Stock a revisar.';
            $insertar = $pdo->prepare('INSERT INTO notificaciones (id_negocio, usuario_id, titulo, mensaje, tipo) VALUES (:n, :u, :t, :m, :tipo)');
            foreach ($titulares as $idTitular) {
                $insertar->execute([':n' => $idNegocio, ':u' => (int) $idTitular, ':t' => 'Stock a revisar', ':m' => $mensaje, ':tipo' => 'alta']);
            }
        } catch (\Throwable $th) {
            // La notificación es un extra: no frena la venta.
        }
    }

    /** Faltantes sin revisar del negocio, el más nuevo primero. */
    public function faltantes(int $idNegocio, bool $incluirRevisados = false): array
    {
        $sql = 'SELECT f.id, f.id_product, p.description, p.code, p.quantity AS stock_actual, f.sucursal,
                    f.cantidad_vendida, f.stock_antes, f.faltante, f.sin_conexion, f.fecha_venta, f.creado,
                    f.revisado, u.name AS vendedor
                FROM stock_faltante f
                LEFT JOIN product p ON p.id = f.id_product AND p.id_negocio = f.id_negocio
                LEFT JOIN user u ON u.id = f.usuario AND u.id_negocio = f.id_negocio
                WHERE f.id_negocio = :n' . ($incluirRevisados ? '' : ' AND f.revisado = 0') . '
                ORDER BY f.creado DESC, f.id DESC
                LIMIT 500';
        try {
            $q = $this->get_bbdd()->prepare($sql);
            $q->execute([':n' => $idNegocio]);
            return $q->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1146) {
                return [];
            }
            throw $e;
        }
    }

    public function contarFaltantes(int $idNegocio): int
    {
        try {
            $q = $this->get_bbdd()->prepare('SELECT COUNT(DISTINCT id_product) FROM stock_faltante WHERE id_negocio = :n AND revisado = 0');
            $q->execute([':n' => $idNegocio]);
            return (int) $q->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /** Marca como revisados los faltantes indicados (solo los del negocio). Devuelve cuántos. */
    public function marcarFaltantesRevisados(int $idNegocio, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn ($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->get_bbdd()->prepare("UPDATE stock_faltante SET revisado = 1, revisado_en = NOW() WHERE id_negocio = ? AND revisado = 0 AND id IN ($marcas)");
        $q->execute(array_merge([$idNegocio], $ids));
        return $q->rowCount();
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

    /**
     * Cambia la forma de pago de todas las líneas de una venta. Rechaza las ventas en
     * "Cta Cte" (tienen deuda asociada en ctacte que no se puede convertir así nomás).
     */
    public function cambiarFormaPago(int $idSale, string $typePayment, int $idNegocio): bool
    {
        $pdo = $this->get_bbdd();

        $queryActual = $pdo->prepare('SELECT type_payment FROM sales_product WHERE id_sale = :idSale AND id_negocio = :id_negocio');
        $queryActual->bindParam(':idSale', $idSale);
        $queryActual->bindParam(':id_negocio', $idNegocio);
        $queryActual->execute();
        $formasActuales = $queryActual->fetchAll(PDO::FETCH_COLUMN);

        if (!$formasActuales) {
            throw new \Exception('Venta no encontrada.');
        }
        foreach ($formasActuales as $forma) {
            if (strcasecmp(trim((string) $forma), 'Cta Cte') === 0) {
                throw new \Exception('No se puede cambiar la forma de pago de una venta en cuenta corriente.');
            }
        }

        $query = $pdo->prepare('UPDATE sales_product SET type_payment = :typePayment WHERE id_sale = :idSale AND id_negocio = :id_negocio');
        $query->bindParam(':typePayment', $typePayment);
        $query->bindParam(':idSale', $idSale);
        $query->bindParam(':id_negocio', $idNegocio);
        if (!$query->execute()) {
            throw new \Exception('No se pudo actualizar la forma de pago.');
        }

        return true;
    }
}
