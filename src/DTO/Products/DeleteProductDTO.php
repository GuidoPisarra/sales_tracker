<?php

namespace App\DTO\Products;


class DeleteProductDTO
{
    protected $id;

    public function to_array(): array
    {
        $resultado = [];
        $resultado['id'] = $this->getId();
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
}
