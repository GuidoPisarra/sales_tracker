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


#[Route('/api/negocio')]
class NegocioController extends BaseController
{

    #[Route('/obtener_negocios', name: 'app_obtener_negocios_token', defaults: ['id' => null], methods: ['GET'])]
    #[Route('/obtener_negocios/{id}', name: 'app_obtener_negocios', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function obtener_negocios(Request $request, ServicioNegocio $servicio_negocio, ?int $id): JsonResponse
    {
        $id ??= $this->idNegocioUsuarioActual();
        if ($check = $this->negocioPermitido($id)) {
            return $check;
        }

        try {
            $listado = $servicio_negocio->list_negocios($id);
            $respuesta = [
                'negocios' => $listado
            ];
            return $this->respuesta(200, $respuesta, []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['Ocurrió un error obteniendo los negocios.'], 400);
        }


        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/nuevo_negocio', name: 'app_add_negocio', methods: ['POST'])]
    public function add_negocio(Request $request, ValidatorInterface $validator, ServicioNegocio $negocio_service): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $this->request_to_json($request);
        $dto = new NegocioDTO();
        $form = $this->createForm(NegocioType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // La sucursal nueva siempre va al negocio del usuario autenticado,
            // nunca al id_negocio que mande el cliente.
            $dto->setIdNegocio((int) $this->idNegocioUsuarioActual());
            try {
                $resultado = $negocio_service->add_negocio($dto);
                if ($resultado !== true) {
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => $resultado
                ];
                return $this->respuesta(200, $respuesta, []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/eliminar_negocio', name: 'app_delete_negocio', methods: ['POST'])]
    public function delete_negocio(Request $request, ValidatorInterface $validator, ServicioNegocio $negocio_service): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $this->request_to_json($request);
        $dto = new EliminarNegocioDTO();
        $form = $this->createForm(EliminarNegocioType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($check = $this->negocioPermitido($dto->getIdNegocio())) {
                return $check;
            }
            try {
                $resultado = $negocio_service->delete_negocio($dto);
                if ($resultado !== true) {
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => $resultado
                ];
                return $this->respuesta(200, $respuesta, []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/meses_aviso_precios', name: 'app_meses_aviso_precios', methods: ['PATCH'])]
    public function meses_aviso_precios(Request $request, ServicioNegocio $servicio_negocio): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $data = json_decode($request->getContent(), true) ?? [];
        $meses = isset($data['meses']) ? (int) $data['meses'] : null;

        if ($meses === null || $meses < 1) {
            return $this->respuesta(400, [], ['El campo "meses" es obligatorio y tiene que ser mayor o igual a 1.'], 400);
        }

        // Siempre el negocio del usuario autenticado — nunca se recibe id_negocio por body.
        $idNegocio = $this->idNegocioUsuarioActual();

        try {
            $resultado = $servicio_negocio->actualizarMesesAvisoPrecios($idNegocio, $meses);
            if ($resultado !== true) {
                throw new \Exception('No se pudo actualizar la configuración.');
            }
            return $this->respuesta(200, ['OK' => 'OK'], []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], [$th->getMessage()], 400);
        }
    }
}
