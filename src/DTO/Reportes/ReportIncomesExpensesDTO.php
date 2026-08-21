<?php

namespace App\DTO\Reportes;


class ReportIncomesExpensesDTO
{
    protected $incomes;
    protected $egress;
    protected $difference;


    public function __construct(float $incomes, float $expenses, float $difference)
    {
        $this->incomes = $incomes;
        $this->egress = $expenses;
        $this->difference = $difference;
    }

    public function to_array(): array
    {
        $respuesta = [];
        $respuesta['incomes'] = $this->getIncomes();
        $respuesta['egress'] = $this->getEgress();
        $respuesta['changes'] = $this->getDifference();
        $respuesta['total'] = $this->getTotal();

        return $respuesta;
    }

    public function getIncomes(): float
    {
        return $this->incomes;
    }
    public function setIncomes(float $incomes): void
    {
        $this->incomes = $incomes;
    }

    public function getEgress(): float
    {
        return $this->egress;
    }
    public function setEgress(float $expenses): void
    {
        $this->egress = $expenses;
    }

    public function getDifference(): float
    {
        return $this->difference;
    }
    public function setDifference(float $difference): void
    {
        $this->difference = $difference;
    }

    public function getTotal(): float
    {
        return $this->getIncomes() - $this->getEgress() + $this->getDifference();
    }
}
