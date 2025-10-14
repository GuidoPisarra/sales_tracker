<?php

namespace App\Service;

use App\DTO\ChangesProduct\ChangeProductDTO;
use App\Repository\ChangeProductRepository;

class ChangeProductService
{
    protected $rep_change;

    public function __construct(ChangeProductRepository $rep_expense)
    {
        $this->rep_change = $rep_expense;
    }

    public function add_change(ChangeProductDTO $dto)
    {
        $this->rep_change->add_change($dto);
        $this->rep_change->add_stock($dto);
        return $this->rep_change->discount_stock($dto);
    }
}
