<?php

namespace App\Service;

use App\DTO\Expenses\AddExpenseDTO;
use App\DTO\Expenses\ExpensesDTO;
use App\Repository\ExpenseRepository;
use App\Repository\ExpensesRepository;

class ExpenseService
{
    protected $rep_expense;

    public function __construct(ExpensesRepository $rep_expense)
    {
        $this->rep_expense = $rep_expense;
    }

    public function list_expense(int $id_negocio)
    {
        return $this->rep_expense->list_expense($id_negocio);
    }

    public function add_expense(AddExpenseDTO $dto)
    {
        return $this->rep_expense->add_expense($dto);
    }
}
