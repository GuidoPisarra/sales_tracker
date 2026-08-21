<?php

namespace App\Service;

use App\DTO\Negocio\EliminarNegocioDTO;
use App\DTO\Negocio\NegocioDTO;
use App\Repository\NegocioRepository;

class ServicioNegocio
{
    protected $rep_negocio;

    public function __construct(NegocioRepository $rep_negocio)
    {
        $this->rep_negocio = $rep_negocio;
    }

    public function list_negocios(int $id_negocio)
    {
        return $this->rep_negocio->list_negocios($id_negocio);
    }

    public function add_negocio(NegocioDTO $dto)
    {
        return $this->rep_negocio->add_negocio($dto);
    }

    public function delete_negocio(EliminarNegocioDTO $dto)
    {
        return $this->rep_negocio->delete_negocio($dto);
    }
}
