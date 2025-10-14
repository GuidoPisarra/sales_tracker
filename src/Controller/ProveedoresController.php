<?php

namespace App\Controller;

use App\DTO\Proveedores\AddProveedorDTO;
use App\Form\Type\Proveedores\AddProvvedorType;
use App\Service\ProveedoresService;
use App\Service\ProveedorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/api/proveedores")
 */
class ProveedoresController extends BaseController
{
    /**
     * @Route("/proveedores_list/{id_local}", name="app_proveedores_list", methods={"GET"})
     */
    public function list_proveedores(Request $request, ValidatorInterface $validator, ProveedoresService $proveedores_service, int $id_local): JsonResponse
    {
        try {
            $a = $id_local;
            $list_products = $proveedores_service->list_proveedores($id_local);
            return $this->respuesta(200, $list_products, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    /**
     * @Route("/proveedor", name="app_proveedor_add", methods={"POST"})
     */
    public function add_proveedor(Request $request, ValidatorInterface $validator, ProveedoresService $proveedor_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new AddProveedorDTO();
        $form = $this->createForm(AddProvvedorType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $resultado = $proveedor_service->add_proveedor($dto);
                if ($resultado !== true) {
                    //$log::get_log()->error('ENDPOINT: app_agregar_productos ERROR: Ocurrió un error grabando el producto.');
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => 'OK'
                ];
                return $this->respuesta(200, $respuesta, []);
            } catch (\Throwable $th) {
                //$log::get_log()->error('ENDPOINT: app_agregar_productos ERROR: ' . $th->getMessage());
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
