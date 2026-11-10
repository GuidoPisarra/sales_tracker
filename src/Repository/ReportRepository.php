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
}
