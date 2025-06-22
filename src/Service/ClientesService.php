<?php

namespace App\Service;

use App\Repository\ClientesRepository;

class ClientesService
{
    protected $rep_expense;

    public function __construct(ClientesRepository $rep_expense)
    {
        $this->rep_expense = $rep_expense;
    }

    public function list_clientes(int $id_negocio)
    {
        return $this->rep_expense->list_clientes($id_negocio);
    }
}
