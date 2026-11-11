<?php

namespace App\Controller;

use App\Service\ClientesService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;


#[Route('/api/clientes')]
class ClientesController extends BaseController
{

    #[Route('/obtener_clientes', name: 'app_obtener_clientes_token', defaults: ['id_negocio' => null], methods: ['GET'])]
    #[Route('/obtener_clientes/{id_negocio}', name: 'app_obtener_clientes', requirements: ['id_negocio' => '\d+'], methods: ['GET'])]
    public function change_product(Request $request, ValidatorInterface $validator, ClientesService $clientes_service, ?int $id_negocio): JsonResponse
    {
        $id_negocio ??= $this->idNegocioUsuarioActual();
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        try {
            $list_clientes = $clientes_service->list_clientes($id_negocio);
            return $this->respuesta(200, $list_clientes, []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los clientes.'], 400);
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
