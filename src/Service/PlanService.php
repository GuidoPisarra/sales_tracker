<?php

namespace App\Service;

use App\Repository\PlanRepository;

class PlanService
{
    protected $rep_plan;

    public function __construct(PlanRepository $rep_plan)
    {
        $this->rep_plan = $rep_plan;
    }

    public function obtenerPorNegocio(int $idNegocio): ?array
    {
        return $this->rep_plan->obtenerPorNegocio($idNegocio);
    }
}
