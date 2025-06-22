<?php

namespace App\Repository;

use App\DTO\Expenses\AddExpenseDTO;
use App\DTO\Expenses\ExpensesDTO;
use PDO;

class ExpensesRepository extends BaseRepository
{

    public function list_expense(int $id_negocio): ?array
    {
        $idNegocio = $id_negocio;
        $query = $this->get_bbdd()->prepare('SELECT id id, description description,price price,id_sucursal id_sucursal, date_expense date_expense FROM expense WHERE id_negocio = :id_negocio');
        $query->bindParam(':id_negocio', $idNegocio);
        $query->execute();
        $query->setFetchMode(PDO::FETCH_ASSOC);
        $expenses = $query->fetchAll();

        if (!$expenses) {
            return null;
        }

        return $expenses;
    }

    public function add_expense(AddExpenseDTO $dto): bool
    {
        $query = $this->get_bbdd()->prepare('INSERT INTO expense 
        (price, description, id_sucursal, date_expense, id_negocio)
        VALUES (:price, :description, :id_sucursal, :dateExpense, :id_negocio)');

        $newExpense = $dto->to_array();
        $query->bindParam(':description', $newExpense["description"]);
        $query->bindParam(':price', $newExpense["price"]);
        $query->bindParam(':id_negocio', $newExpense["idNegocio"]);
        $query->bindParam(':id_sucursal', $newExpense["idNegocio"]);
        $query->bindParam(':dateExpense', $newExpense["dateExpense"]);


        $response = $query->execute();
        return $response;
    }
}
