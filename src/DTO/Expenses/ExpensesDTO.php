<?php

namespace App\DTO\Expenses;


class ExpensesDTO
{
    protected $id;
    protected $description;
    protected $price;
    protected $idSucursal;
    protected $dateExpense;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['id'] = $this->getId();
        $resultado['description'] = $this->getDescription();
        $resultado['price'] = $this->getPrice();
        $resultado['idSucursal'] = 1;
        $resultado['dateExpense'] = $this->getDateExpense();
        return $resultado;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }
    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setIdSucursal(int $idSucursal): void
    {
        $this->idSucursal = $idSucursal;
    }

    public function getIdSucursal(): int
    {
        return $this->idSucursal;
    }

    public function setDateExpense(string $date): void
    {
        $this->dateExpense = $date;
    }

    public function getDateExpense(): string
    {
        return $this->dateExpense;
    }
}
