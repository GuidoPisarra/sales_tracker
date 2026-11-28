<?php

namespace App\Repository;

use PDO;

class ReportRepository extends BaseRepository
{
    /**
     * Ventas del negocio. Con $desde/$hasta (Y-m-d H:i:s) solo las de ese rango; sin
     * rango, todas (así lo piden las versiones viejas de la app).
     */
    public function report_salesProduct(int $id_negocio, ?string $desde = null, ?string $hasta = null)
    {
        $rango = '';
        $params = [':id_negocio' => $id_negocio];
        if ($desde !== null && $hasta !== null) {
            $rango = ' AND sp.sale_product_date >= :desde AND sp.sale_product_date < :hasta';
            $params[':desde'] = $desde;
            $params[':hasta'] = $hasta;
        }
        $query = $this->get_bbdd()->prepare('SELECT s.id, sp.id_product, sp.id_sale, p.description, sp.quantity, (sp.quantity * sp.price) as amount,sp.sale_product_date, sp.type_payment 
            FROM sales_product sp
            JOIN sales s 
            ON s.id = sp.id_sale
            JOIN  product p 
            ON p.id = sp.id_product 
            WHERE sp.active = 0 AND sp.id_negocio = :id_negocio' . $rango . '
            ORDER BY sp.sale_product_date ASC');
        $query->execute($params);
        $query->setFetchMode(PDO::FETCH_ASSOC);

        $salesProduct = $query->fetchAll();

        if (!$salesProduct) {
            return [];
        }

        return $salesProduct;
    }

    /**
     * Totales del día para el Inicio: monto vendido, cantidad de ventas y por medio de pago.
     */
    public function resumenVentas(int $id_negocio, string $desde, string $hasta): array
    {
        $query = $this->get_bbdd()->prepare('SELECT COALESCE(SUM(sp.price * sp.quantity), 0) AS total,
                COUNT(DISTINCT sp.id_sale) AS ventas, COALESCE(SUM(sp.quantity), 0) AS unidades
            FROM sales_product sp
            WHERE sp.active = 0 AND sp.id_negocio = :n AND sp.sale_product_date >= :desde AND sp.sale_product_date < :hasta');
        $query->execute([':n' => $id_negocio, ':desde' => $desde, ':hasta' => $hasta]);
        $fila = $query->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (float) ($fila['total'] ?? 0),
            'ventas' => (int) ($fila['ventas'] ?? 0),
            'unidades' => (int) ($fila['unidades'] ?? 0),
        ];
    }

    /**
     * Productos únicos vendidos en los últimos $dias días, ordenados por venta más reciente
     * primero (para accesos rápidos tipo "vendidos recientemente"), limitado a $limite filas.
     */
    public function report_salesProduct_recientes(int $id_negocio, int $dias, int $limite): array
    {
        $query = $this->get_bbdd()->prepare(
            'SELECT sp.id_product AS id, p.description AS description, p.sale_price AS sale_price,
                MAX(sp.sale_product_date) AS ultima_venta
            FROM sales_product sp
            JOIN product p ON p.id = sp.id_product
            WHERE sp.active = 0 AND sp.id_negocio = :id_negocio
                AND sp.sale_product_date >= (NOW() - INTERVAL :dias DAY)
            GROUP BY sp.id_product, p.description, p.sale_price
            ORDER BY ultima_venta DESC
            LIMIT :limite'
        );
        $query->bindParam(':id_negocio', $id_negocio);
        $query->bindValue(':dias', $dias, PDO::PARAM_INT);
        $query->bindValue(':limite', $limite, PDO::PARAM_INT);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);

        return $query->fetchAll() ?: [];
    }

    public function report_expenses(int $month, int $year, $id_negocio): ?float
    {
        $query = $this->get_bbdd()->prepare('SELECT COALESCE(SUM(e.price), 0)
            FROM expense e
            WHERE MONTH(e.date_expense)= :month AND YEAR(e.date_expense)= :year AND e.id_negocio = :id_negocio ');

        $query->bindParam(':month', $month);
        $query->bindParam(':year', $year);
        $query->bindParam(':id_negocio', $id_negocio);
        $query->execute();

        $expenses = $query->fetch();

        if (!$expenses) {
            return null;
        }

        return $expenses[0];
    }
    public function report_incomes(int $month, int $year, int $id_negocio): ?float
    {
        $query = $this->get_bbdd()->prepare('SELECT COALESCE(SUM(sp.price*sp.quantity), 0)
            FROM sales_product sp 
            JOIN sales s 
            ON s.id = sp.id_sale
            JOIN  product p 
            ON p.id = s.id_product
            WHERE MONTH(sp.sale_product_date)= :month AND YEAR(sp.sale_product_date)= :year AND sp.id_negocio = :id_negocio ');

        $query->bindParam(':month', $month);
        $query->bindParam(':year', $year);
        $query->bindParam(':id_negocio', $id_negocio);

        $query->execute();

        $incomes = $query->fetch();

        if (!$incomes) {
            return null;
        }

        return $incomes[0];
    }

    /**
     * Ingresos, egresos y cambios agrupados por mes, entre $desde (incluido) y
     * $hasta (excluido), con las mismas cuentas que report_incomes,
     * report_expenses y report_changes. Devuelve ['2026-11' => [...], ...]
     * solo con los meses que tienen algún movimiento.
     */
    public function balancePorMes(int $idNegocio, string $desde, string $hasta): array
    {
        $pdo = $this->get_bbdd();
        $consultas = [
            'ingresos' => 'SELECT DATE_FORMAT(sp.sale_product_date, \'%Y-%m\') AS mes, COALESCE(SUM(sp.price * sp.quantity), 0) AS monto
                FROM sales_product sp
                JOIN sales s ON s.id = sp.id_sale
                JOIN product p ON p.id = s.id_product
                WHERE sp.id_negocio = :n AND sp.sale_product_date >= :desde AND sp.sale_product_date < :hasta
                GROUP BY mes',
            'egresos' => 'SELECT DATE_FORMAT(e.date_expense, \'%Y-%m\') AS mes, COALESCE(SUM(e.price), 0) AS monto
                FROM expense e
                WHERE e.id_negocio = :n AND e.date_expense >= :desde AND e.date_expense < :hasta
                GROUP BY mes',
            'cambios' => 'SELECT DATE_FORMAT(c.fecha_cambio, \'%Y-%m\') AS mes, COALESCE(SUM(c.precio_producto_cambio - c.precio_producto_nuevo), 0) AS monto
                FROM cambios c
                WHERE c.id_negocio = :n AND c.fecha_cambio >= :desde AND c.fecha_cambio < :hasta
                GROUP BY mes',
        ];
        $meses = [];
        foreach ($consultas as $campo => $sql) {
            $query = $pdo->prepare($sql);
            $query->execute([':n' => $idNegocio, ':desde' => $desde, ':hasta' => $hasta]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $meses[$fila['mes']][$campo] = (float) $fila['monto'];
            }
        }
        return $meses;
    }

    /**
     * Margen de ganancia entre $desde y $hasta (Y-m-d H:i:s, hasta excluido). El costo es el
     * guardado al vender; las ventas de antes de guardarlo usan el costo actual del producto.
     * Las líneas sin costo cargado (0) no cuentan para el margen y se informan aparte.
     */
    public function margen(int $idNegocio, string $desde, string $hasta): array
    {
        $costo = 'COALESCE(sp.cost, p.cost_price, 0)';
        $base = "FROM sales_product sp JOIN product p ON p.id = sp.id_product
            WHERE sp.active = 0 AND sp.id_negocio = :n AND sp.sale_product_date >= :desde AND sp.sale_product_date < :hasta";
        $params = [':n' => $idNegocio, ':desde' => $desde, ':hasta' => $hasta];
        $conCosto = "$costo > 0";

        $stmt = $this->get_bbdd()->prepare("SELECT
                COALESCE(SUM(sp.quantity * sp.price), 0) AS ventas,
                COALESCE(SUM(CASE WHEN $conCosto THEN sp.quantity * sp.price ELSE 0 END), 0) AS ventas_con_costo,
                COALESCE(SUM(CASE WHEN $conCosto THEN sp.quantity * $costo ELSE 0 END), 0) AS costo,
                COALESCE(SUM(CASE WHEN $conCosto THEN 0 ELSE sp.quantity * sp.price END), 0) AS ventas_sin_costo,
                COUNT(DISTINCT CASE WHEN $conCosto THEN NULL ELSE sp.id_product END) AS productos_sin_costo,
                COALESCE(SUM(sp.quantity), 0) AS unidades,
                COUNT(DISTINCT sp.id_sale) AS cantidad_ventas
            $base");
        $stmt->execute($params);
        $t = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totales = self::numeros([
            'ventas' => $t['ventas'] ?? 0,
            'costo' => $t['costo'] ?? 0,
            'ganancia' => (float) ($t['ventas_con_costo'] ?? 0) - (float) ($t['costo'] ?? 0),
            'ventas_con_costo' => $t['ventas_con_costo'] ?? 0,
            'ventas_sin_costo' => $t['ventas_sin_costo'] ?? 0,
        ]);
        $totales['margen'] = self::porcentaje($totales['ganancia'], $totales['ventas_con_costo']);
        $totales['productos_sin_costo'] = (int) ($t['productos_sin_costo'] ?? 0);
        $totales['unidades'] = (int) ($t['unidades'] ?? 0);
        $totales['cantidad_ventas'] = (int) ($t['cantidad_ventas'] ?? 0);

        $stmt = $this->get_bbdd()->prepare("SELECT sp.id_product AS id, MAX(p.description) AS descripcion,
                SUM(sp.quantity) AS unidades, SUM(sp.quantity * sp.price) AS ventas,
                SUM(sp.quantity * $costo) AS costo, MIN($costo) > 0 AS con_costo
            $base GROUP BY sp.id_product ORDER BY SUM(sp.quantity * sp.price) DESC LIMIT 500");
        $stmt->execute($params);
        $productos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
            $conCostoProducto = (int) $f['con_costo'] === 1;
            $ventas = round((float) $f['ventas'], 2);
            $costoProducto = round((float) $f['costo'], 2);
            $productos[] = [
                'id' => (int) $f['id'],
                'descripcion' => $f['descripcion'],
                'unidades' => (int) $f['unidades'],
                'ventas' => $ventas,
                'costo' => $conCostoProducto ? $costoProducto : null,
                'ganancia' => $conCostoProducto ? round($ventas - $costoProducto, 2) : null,
                'margen' => $conCostoProducto ? self::porcentaje($ventas - $costoProducto, $ventas) : null,
            ];
        }

        $stmt = $this->get_bbdd()->prepare("SELECT DATE(sp.sale_product_date) AS fecha,
                SUM(sp.quantity * sp.price) AS ventas,
                SUM(CASE WHEN $conCosto THEN sp.quantity * (sp.price - $costo) ELSE 0 END) AS ganancia
            $base GROUP BY DATE(sp.sale_product_date) ORDER BY fecha");
        $stmt->execute($params);
        $dias = array_map(fn ($f) => ['fecha' => $f['fecha'], 'ventas' => round((float) $f['ventas'], 2), 'ganancia' => round((float) $f['ganancia'], 2)],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return ['totales' => $totales, 'productos' => $productos, 'dias' => $dias];
    }

    private static function numeros(array $valores): array
    {
        return array_map(fn ($v) => round((float) $v, 2), $valores);
    }

    private static function porcentaje(float $parte, float $total): ?float
    {
        return $total > 0 ? round($parte * 100 / $total, 1) : null;
    }
}
