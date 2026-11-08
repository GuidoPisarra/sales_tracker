<?php

namespace App\Controller;

use App\DTO\Products\AddProductDTO;
use App\DTO\Products\AddStockDTO;
use App\DTO\Products\DeleteProductDTO;
use App\DTO\Products\OneProductDTO;
use App\DTO\Products\TrasladoProductDTO;
use App\Form\Type\Productos\AddProductType;
use App\Form\Type\Productos\AddStockType;
use App\Form\Type\Productos\DeleteProductType;
use App\Form\Type\Productos\OneProductType;
use App\Form\Type\Productos\TrasladoProductType;
use App\Service\ProductsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;


#[Route('/api/products')]
class ProductsController extends BaseController
{

    #[Route('/products_list', name: 'app_products_list_token', defaults: ['id_local' => null], methods: ['GET'])]
    #[Route('/products_list/{id_local}', name: 'app_products_list', requirements: ['id_local' => '\d+'], methods: ['GET'])]
    public function list_products(Request $request, ValidatorInterface $validator, ProductsService $products_service, ?int $id_local): JsonResponse
    {
        $id_local ??= $this->idNegocioUsuarioActual();
        // id_local es en realidad el id_negocio (ver ProductsRepository::list_products).
        if ($check = $this->negocioPermitido($id_local)) {
            return $check;
        }

        try {
            $a = $id_local;
            $list_products = $products_service->list_products($id_local);
            return $this->respuesta(200, $list_products, []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
    }


    #[Route('/products', name: 'app_products_add', methods: ['POST'])]
    public function add_product(Request $request, ValidatorInterface $validator, ProductsService $products_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new AddProductDTO();
        $form = $this->createForm(AddProductType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // El id_negocio nunca se toma del body: siempre el del usuario autenticado.
            $dto->setIdNegocio((string) $this->idNegocioUsuarioActual());
            try {
                $resultado = $products_service->add_product($dto);
                if ($resultado !== true) {
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => 'OK'
                ];
                return $this->respuesta(200, $respuesta, []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/products/delete', name: 'app_products_del', methods: ['POST'])]
    public function delete_product(Request $request, ValidatorInterface $validator, ProductsService $products_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new DeleteProductDTO();
        $form = $this->createForm(DeleteProductType::class, $dto);
        $form->handleRequest($request);
        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $idNegocioReal = $products_service->obtenerIdNegocio($dto->getId());
            if ($idNegocioReal === null) {
                return $this->respuesta(404, [], ['Producto no encontrado'], 404);
            }
            if ($check = $this->negocioPermitido($idNegocioReal)) {
                return $check;
            }
            try {
                $resultado = $products_service->del_product($dto);
                if ($resultado !== true) {
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => 'OK'
                ];
                return $this->respuesta(200, $respuesta, []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/products_one/{code}/{sucursal}', name: 'app_products_get_one_token', defaults: ['id_negocio' => null], methods: ['GET'])]
    #[Route('/products_one/{code}/{id_negocio}/{sucursal}', name: 'app_products_get_one', requirements: ['id_negocio' => '\d+'], methods: ['GET'])]
    public function one_product(ValidatorInterface $validator, ProductsService $products_service, string $code, ?int $id_negocio, int $sucursal): JsonResponse
    {
        $id_negocio ??= $this->idNegocioUsuarioActual();
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }
        if ($check = $this->sucursalPermitida($sucursal)) {
            return $check;
        }

        $dto = new OneProductDTO();
        $form = $this->createForm(OneProductType::class, $dto);
        $dto->setCode($code);
        $dto->setIdNegocio($id_negocio);
        $dto->setSucursal($sucursal);
        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }

        try {
            $resultado = $products_service->one_product($dto);
            if ($resultado === null) {
                throw new \Exception("Ocurrió un error al buscar el producto.");
            }
            $respuesta = [
                'OK' => 'OK',
                'product' => $resultado
            ];
            return $this->respuesta(200, $respuesta, []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], [$th->getMessage()], 400);
        }

        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/products/price_percent/{percentage}/{proveedor}', name: 'app_products_price_percent_token', defaults: ['id_negocio' => null], methods: ['GET'])]
    #[Route('/products/price_percent/{percentage}/{id_negocio}/{proveedor}', name: 'app_products_price_percent', requirements: ['id_negocio' => '\d+'], methods: ['GET'])]
    public function percentage_price_product(ValidatorInterface $validator, ProductsService $products_service, $percentage, ?int $id_negocio, $proveedor): JsonResponse
    {
        $id_negocio ??= $this->idNegocioUsuarioActual();
        if ($check = $this->soloTitular()) {
            return $check;
        }
        if ($percentage === null || $id_negocio === null) {
            return $this->respuesta(400, [], [], 400);
        }
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        try {
            $resultado = $products_service->products_price_percentage($percentage, $id_negocio, $proveedor);
            if ($resultado !== true) {
                throw new \Exception("Ocurrió un error al buscar el producto.");
            }
            $respuesta = [
                'OK' => 'OK',
            ];
            return $this->respuesta(200, $respuesta, []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], [$th->getMessage()], 400);
        }

        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/products/stock', name: 'app_products_stock', methods: ['POST'])]
    public function product_stock(Request $request, ValidatorInterface $validator, ProductsService $products_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new AddStockDTO;
        $form = $this->createForm(AddStockType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $idNegocioReal = $products_service->obtenerIdNegocio((int) $dto->getId());
            if ($idNegocioReal === null) {
                return $this->respuesta(404, [], ['Producto no encontrado'], 404);
            }
            if ($check = $this->negocioPermitido($idNegocioReal)) {
                return $check;
            }
            try {
                $resultado = $products_service->add_stock_product($dto);
                if ($resultado !== true) {
                    throw new \Exception("Ocurrió un error al agregar el producto.");
                }
                $respuesta = [
                    'OK' => 'OK'
                ];
                return $this->respuesta(200, $respuesta, []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/trasladar_products', name: 'app_trasladar_products', methods: ['POST'])]
    public function trasladar_productos(Request $request, ValidatorInterface $validator, ProductsService $product_service): JsonResponse
    {
        $this->request_to_json($request);

        $datos = $request->request->all();
        $errores =  false;
        $datosDto = [];
        $idNegocioUsuario = $this->idNegocioUsuarioActual();

        foreach ($datos as $producto) {
            // El producto de origen tiene que ser del negocio del usuario autenticado.
            $idNegocioReal = $product_service->obtenerIdNegocio((int) $producto['id']);
            if ($idNegocioReal === null || $idNegocioReal !== $idNegocioUsuario) {
                return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
            }

            // Origen y destino tienen que ser sucursales del mismo negocio.
            if (($check = $this->sucursalPermitida($producto['sucursal'] ?? null))
                || ($check = $this->sucursalPermitida($producto['sucursal_nueva'] ?? null))) {
                return $check;
            }

            $dto = new TrasladoProductDTO();
            $dto->setCode($producto['code']);
            $dto->setCodeCanvas($producto['codeCanvas']);
            $dto->setCostPrice($producto['costPrice']);
            $dto->setDescription($producto['description']);
            $dto->setId($producto['id']);
            $dto->setIdProveedor($producto['idProveedor']);
            // El id_negocio nunca se toma del body: siempre el del usuario autenticado
            // (no se puede trasladar un producto hacia/desde un negocio ajeno).
            $dto->setIdNegocio($idNegocioUsuario);
            $dto->setQuantity($producto['quantity']);
            $dto->setSalePrice($producto['salePrice']);
            $dto->setSize($producto['size']);
            $dto->setSucursal($producto['sucursal']);
            $dto->setSucursalNueva($producto['sucursal_nueva']);

            $this->createForm(TrasladoProductType::class, $dto);
            $errores = $this->obtener_validaciones($validator, $dto);
            if (count($errores) > 0) {
                $errores = true;
            }
            array_push($datosDto, $dto);
        }

        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }

        if (!$errores) {
            try {
                $sales_product = $product_service->trasladar_product($datosDto);
                return $this->respuesta(200, [$sales_product], []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
            }
            return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
        }
        return $this->respuesta(400, $datosDto, ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/editOneProduct', name: 'app_editOneProduct', methods: ['POST'])]
    public function editOneProduct(Request $request, ValidatorInterface $validator, ProductsService $product_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new AddStockDTO;
        $form = $this->createForm(AddStockType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            return $this->respuesta(400, [], $errores, 400);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $idNegocioReal = $product_service->obtenerIdNegocio((int) $dto->getId());
            if ($idNegocioReal === null) {
                return $this->respuesta(404, [], ['Producto no encontrado'], 404);
            }
            if ($check = $this->negocioPermitido($idNegocioReal)) {
                return $check;
            }
            try {
                $resultado = $product_service->add_stock_product_edit($dto);
                if ($resultado !== true) {
                    throw new \Exception("Ocurrió un error al editar el producto.");
                }
                $respuesta = [
                    'OK' => 'OK'
                ];
                return $this->respuesta(200, $respuesta, []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

}
