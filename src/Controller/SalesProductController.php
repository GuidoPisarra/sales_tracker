<?php

namespace App\Controller;

use App\DTO\SalesProduct\AddSalesProductDTO;
use App\DTO\SalesProduct\DeleteSalesProductDTO;
use App\DTO\SalesProduct\RegisterSalesProductDTO;
use App\Form\Type\SalesProduct\AddSalesProductType;
use App\Form\Type\SalesProduct\DeleteSalesProductType;
use App\Form\Type\SalesProduct\RegisterSalesProductType;
use App\Service\SalesProductService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/api/salesProduct")
 */
class SalesProductController extends BaseController
{

    /**
     * @Route("/salesProduct", name="app_salesProduct_list", methods={"GET"})
     */
    public function salesProduct_list(Request $request, ValidatorInterface $validator, SalesProductService $salesProduct_service): JsonResponse
    {
        try {
            $list_salesProduct = $salesProduct_service->list_salesProduct();
            return $this->respuesta(200, $list_salesProduct, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    /**
     * @Route("/salesProduct", name="app_add_salesProduct", methods={"POST"})
     */
    public function salesProduct_add(Request $request, ValidatorInterface $validator, SalesProductService $salesProduct_service): JsonResponse
    {
        $this->request_to_json($request);

        $datos = $request->request->all();
        $errores =  false;
        $datosDto = [];
        $zonaHorariaArgentina = new \DateTimeZone('America/Argentina/Buenos_Aires');

        // Obtener la fecha actual con la zona horaria de Argentina
        $fechaArgentina = new \DateTime('now', $zonaHorariaArgentina);

        // Formatear la fecha al formato deseado (YYYY-MM-DD)
        $fechaFormateada = $fechaArgentina->format('Y-m-d H:i:s');
        foreach ($datos as $venta) {
            $dto = new AddSalesProductDTO();
            $dto->setIdProduct($venta['id']);
            $dto->setSaleDay($fechaFormateada);
            $dto->setPrice($venta['salePrice']);
            $dto->setQuantity($venta['quantity']);
            $dto->setTypePayment($venta['typePayment']);
            $dto->setIdNegocio($venta['id_negocio']);
            $dto->setSucursal($venta['sucursal']);
            $dto->setUsuario($venta['usuario']);
            $this->createForm(AddSalesProductType::class, $dto);
            $errores = $this->obtener_validaciones($validator, $dto);
            if (count($errores) > 0) {
                $errores = true;
            }
            array_push($datosDto, $dto);
        }

        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        if (!$errores) {
            try {
                $sales_product = $salesProduct_service->save_salesProduct($datosDto);
                return $this->respuesta(200, [$sales_product], []);
            } catch (\Throwable $th) {
                //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
                return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
            }
            // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
            return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
        }
        return $this->respuesta(400, $datosDto, ['Ocurrió un error desconocido.'], 400);
    }

    /**
     * @Route("/salesProduct/delete", name="app_delete_salesProduct", methods={"POST"})
     */
    public function salesProduct_delete(Request $request, ValidatorInterface $validator, SalesProductService $salesProduct_service): JsonResponse
    {
        $this->request_to_json($request);

        $dto = new DeleteSalesProductDTO();
        $form = $this->createForm(DeleteSalesProductType::class, $dto);
        $form->handleRequest($request);
        $errores = $this->obtener_validaciones($validator, $dto);

        if (count($errores) > 0) {
            $errores = true;
        }

        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $sales_product_delete = $salesProduct_service->delete_salesProduct($dto);
                return $this->respuesta(200, [$sales_product_delete], []);
            } catch (\Throwable $th) {
                //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
                return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
            }
            // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
            return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    /**
     * @Route("/salesProduct/register", name="app_registe_salesProduct", methods={"POST"})
     */
    public function salesProduct_register(Request $request, ValidatorInterface $validator, SalesProductService $salesProduct_service): JsonResponse
    {
        $this->request_to_json($request);

        $dto = new RegisterSalesProductDTO();
        $form = $this->createForm(RegisterSalesProductType::class, $dto);
        $form->handleRequest($request);
        $errores = $this->obtener_validaciones($validator, $dto);

        if (count($errores) > 0) {
            $errores = true;
        }

        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $sales_product_delete = $salesProduct_service->register_salesProduct($dto);
                return $this->respuesta(200, [$sales_product_delete], []);
            } catch (\Throwable $th) {
                //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
                return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
            }
            // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
            return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
