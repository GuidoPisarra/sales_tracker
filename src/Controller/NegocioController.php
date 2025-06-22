<?php

namespace App\Controller;

use App\DTO\Negocio\EliminarNegocioDTO;
use App\DTO\Negocio\NegocioDTO;
use App\Form\Type\Negocio\EliminarNegocioType;
use App\Form\Type\Negocio\NegocioType;
use App\Service\ServicioNegocio;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/api/negocio")
 */
class NegocioController extends BaseController
{

    /**
     * @Route("/obtener_negocios/{id}", name="app_obtener_negocios", methods={"GET"})
     */
    public function obtener_negocios(Request $request, ServicioNegocio $servicio_negocio, int $id): JsonResponse
    {
        try {
            $listado = $servicio_negocio->list_negocios($id);
            $respuesta = [
                'negocios' => $listado
            ];
            return $this->respuesta(200, $respuesta, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error obteniendo los negocios.'], 400);
        }


        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    /**
     * @Route("/nuevo_negocio", name="app_add_negocio", methods={"POST"})
     */
    public function add_negocio(Request $request, ValidatorInterface $validator, ServicioNegocio $negocio_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new NegocioDTO();
        $form = $this->createForm(NegocioType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $resultado = $negocio_service->add_negocio($dto);
                if ($resultado !== true) {
                    //$log::get_log()->error('ENDPOINT: app_agregar_gastos ERROR: Ocurrió un error grabando el gasto.');
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => $resultado
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

    /**
     * @Route("/eliminar_negocio", name="app_delete_negocio", methods={"POST"})
     */
    public function delete_negocio(Request $request, ValidatorInterface $validator, ServicioNegocio $negocio_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new EliminarNegocioDTO();
        $form = $this->createForm(EliminarNegocioType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $resultado = $negocio_service->delete_negocio($dto);
                if ($resultado !== true) {
                    //$log::get_log()->error('ENDPOINT: app_agregar_gastos ERROR: Ocurrió un error grabando el gasto.');
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => $resultado
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
