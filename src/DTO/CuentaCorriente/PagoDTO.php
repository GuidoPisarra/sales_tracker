<?php

namespace App\DTO\CuentaCorriente;


class PagoDTO
{

    protected $id_cta_cte;
    protected $id_persona;
    protected $entrega;
    protected $fecha;

    public function getIdCtaCte(): int
    {
        return $this->id_cta_cte;
    }

    public function setIdCtaCte(int $idCtaCte): void
    {
        $this->id_cta_cte = $idCtaCte;
    }

    public function getIdPersona(): int
    {
        return $this->id_persona;
    }

    public function setIdPersona(int $id_persona): void
    {
        $this->id_persona = $id_persona;
    }

    public function getEntrega(): int
    {
        return $this->entrega;
    }

    public function setEntrega(float $entrega): void
    {
        $this->entrega = $entrega;
    }

    public function getFecha(): string
    {
        return $this->fecha;
    }

    public function setFecha(string $fecha): void
    {
        $this->fecha = $fecha;
    }


    public function to_array(): array
    {
        $respuesta = [];

        $respuesta['id_cta_cte'] = $this->getIdCtaCte();
        $respuesta['id_persona'] = $this->getIdPersona();
        $respuesta['entrega'] = $this->getEntrega();
        $respuesta['fecha'] = $this->getFecha();

        return $respuesta;
    }
}
