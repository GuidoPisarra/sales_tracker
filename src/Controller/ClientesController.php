<?php

namespace App\Controller;

use App\Service\ClientesService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/api/clientes")
 */
class ClientesController extends BaseController
{

    /**
     * @Route("/obtener_clientes/{id_negocio}", name="app_obtener_clientes", methods={"GET"})
     */
    public function change_product(Request $request, ValidatorInterface $validator, ClientesService $clientes_service, $id_negocio): JsonResponse
    {
        try {
            $list_clientes = $clientes_service->list_clientes($id_negocio);
            return $this->respuesta(200, $list_clientes, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los clientes.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
