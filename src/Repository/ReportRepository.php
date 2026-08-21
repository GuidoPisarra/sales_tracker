<?php

namespace App\Repository;

use PDO;

class ReportRepository extends BaseRepository
{
    public function report_salesProduct(int $id_negocio): ?array
    {
        $negocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT s.id, sp.id_product, sp.id_sale, p.description, sp.quantity, (sp.quantity * sp.price) as amount,sp.sale_product_date, sp.type_payment 
            FROM sales_product sp
            JOIN sales s 
            ON s.id = sp.id_sale
            JOIN  product p 
            ON p.id = sp.id_product 
            WHERE sp.active = 0 AND sp.id_negocio = :id_negocio
            ORDER BY sp.sale_product_date ASC');
        $query->bindParam(':id_negocio', $negocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);

        $salesProduct = $query->fetchAll();

        if (!$salesProduct) {
            return [];
        }

        return $salesProduct;
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
}
