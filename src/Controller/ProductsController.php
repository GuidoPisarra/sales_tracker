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
use App\Service\SuscripcionService;
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

    /**
     * Búsqueda paginada: ?q=texto&proveedor=&filtro=sin_stock|stock_bajo&sucursal=&pagina=1&limite=50
     * Reemplaza bajar la lista completa en las pantallas de listado.
     */
    #[Route('/buscar', name: 'app_products_buscar', methods: ['GET'])]
    public function buscar(Request $request, ProductsService $products_service): JsonResponse
    {
        $idNegocio = $this->idNegocioUsuarioActual();
        if ($idNegocio === null) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        $filtro = $request->query->get('filtro');
        $filtro = in_array($filtro, ['sin_stock', 'stock_bajo'], true) ? $filtro : null;
        $proveedor = $request->query->get('proveedor');
        $sucursal = $request->query->get('sucursal');
        if ($sucursal !== null && $sucursal !== '' && ($check = $this->sucursalPermitida((int) $sucursal))) {
            return $check;
        }

        try {
            $resultado = $products_service->buscar(
                $idNegocio,
                mb_substr((string) $request->query->get('q', ''), 0, 100),
                is_numeric($proveedor) ? (int) $proveedor : null,
                $filtro,
                is_numeric($sucursal) ? (int) $sucursal : null,
                (int) $request->query->get('pagina', 1),
                (int) $request->query->get('limite', 50)
            );
            return $this->respuesta(200, $resultado, []);
        } catch (\Throwable $th) {
            error_log('buscar productos: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al buscar los productos.'], 400);
        }
    }

    /** Productos por id ({ids: [1,2,3]}), para refrescar solo los que se tocaron. */
    #[Route('/por_ids', name: 'app_products_por_ids', methods: ['POST'])]
    public function porIds(Request $request, ProductsService $products_service): JsonResponse
    {
        $idNegocio = $this->idNegocioUsuarioActual();
        if ($idNegocio === null) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        $datos = json_decode($request->getContent(), true);
        $ids = is_array($datos['ids'] ?? null) ? $datos['ids'] : [];
        try {
            return $this->respuesta(200, $products_service->porIds($idNegocio, $ids), []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
    }

    /**
     * Catálogo liviano para buscar en el dispositivo. ?desde=YYYY-MM-DD HH:MM:SS
     * devuelve solo lo modificado desde esa fecha (usar el "servidor" de la respuesta anterior).
     */
    #[Route('/catalogo', name: 'app_products_catalogo', methods: ['GET'])]
    public function catalogo(Request $request, ProductsService $products_service): JsonResponse
    {
        $idNegocio = $this->idNegocioUsuarioActual();
        if ($idNegocio === null) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        $desde = (string) $request->query->get('desde', '');
        $desde = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde) ? $desde : null;
        try {
            return $this->respuesta(200, $products_service->catalogo($idNegocio, $desde), []);
        } catch (\Throwable $th) {
            error_log('catalogo: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
    }

    /** Edición rápida desde la lista: {id, salePrice?, quantity?}. */
    #[Route('/rapido', name: 'app_products_rapido', methods: ['POST'])]
    public function rapido(Request $request, ProductsService $products_service): JsonResponse
    {
        $datos = json_decode($request->getContent(), true) ?: [];
        $id = (int) ($datos['id'] ?? 0);
        $precio = isset($datos['salePrice']) && is_numeric($datos['salePrice']) ? (float) $datos['salePrice'] : null;
        $stock = isset($datos['quantity']) && is_numeric($datos['quantity']) ? (int) $datos['quantity'] : null;
        if ($id <= 0 || ($precio === null && $stock === null)) {
            return $this->respuesta(400, [], ['Faltan datos para actualizar el producto.'], 400);
        }
        if (($precio !== null && $precio < 0) || ($stock !== null && $stock < 0)) {
            return $this->respuesta(400, [], ['El precio y el stock no pueden ser negativos.'], 400);
        }
        $idNegocioReal = $products_service->obtenerIdNegocio($id);
        if ($idNegocioReal === null) {
            return $this->respuesta(404, [], ['Producto no encontrado'], 404);
        }
        if ($check = $this->negocioPermitido($idNegocioReal)) {
            return $check;
        }
        try {
            $products_service->actualizarRapido($idNegocioReal, $id, $precio, $stock);
            $producto = $products_service->porIds($idNegocioReal, [$id]);
            return $this->respuesta(200, $producto[0] ?? [], []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['No se pudo actualizar el producto.'], 400);
        }
    }

    /**
     * Varios precios a la vez, cada producto con el suyo (solo administrador):
     * {items: [{id, salePrice}, ...]}. Lo usa "Precios desactualizados".
     */
    #[Route('/precios', name: 'app_products_precios', methods: ['POST'])]
    public function precios(Request $request, ProductsService $products_service): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $idNegocio = $this->idNegocioUsuarioActual();
        $datos = json_decode($request->getContent(), true) ?: [];
        $items = is_array($datos['items'] ?? null) ? $datos['items'] : [];
        $precios = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $precio = $item['salePrice'] ?? null;
            if ($id <= 0 || !is_numeric($precio) || (float) $precio <= 0) {
                return $this->respuesta(400, [], ['Hay un precio inválido: tiene que ser mayor a cero.'], 400);
            }
            $precios[$id] = round((float) $precio, 2);
        }
        if (!$precios) {
            return $this->respuesta(400, [], ['Elegí al menos un producto.'], 400);
        }
        if (count($precios) > 1000) {
            return $this->respuesta(400, [], ['Se pueden actualizar hasta 1000 productos por vez.'], 400);
        }
        try {
            $modificados = $products_service->actualizarPrecios($idNegocio, $precios);
            return $this->respuesta(200, ['modificados' => $modificados], []);
        } catch (\Throwable $th) {
            error_log('precios varios: ' . $th->getMessage());
            return $this->respuesta(400, [], ['No se pudieron actualizar los precios.'], 400);
        }
    }

    /**
     * Edición masiva (solo administrador): {ids: [...], cambios: {porcentaje?|precio?, proveedor?, stock?}}.
     */
    #[Route('/masivo', name: 'app_products_masivo', methods: ['POST'])]
    public function masivo(Request $request, ProductsService $products_service): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $idNegocio = $this->idNegocioUsuarioActual();
        $datos = json_decode($request->getContent(), true) ?: [];
        $ids = is_array($datos['ids'] ?? null) ? $datos['ids'] : [];
        $crudos = is_array($datos['cambios'] ?? null) ? $datos['cambios'] : [];
        $cambios = [];
        if (isset($crudos['porcentaje']) && is_numeric($crudos['porcentaje']) && (float) $crudos['porcentaje'] != 0) {
            $cambios['porcentaje'] = max(-99, min(1000, (float) $crudos['porcentaje']));
        } elseif (isset($crudos['precio']) && is_numeric($crudos['precio']) && (float) $crudos['precio'] >= 0) {
            $cambios['precio'] = (float) $crudos['precio'];
        }
        if (isset($crudos['proveedor']) && is_numeric($crudos['proveedor']) && (int) $crudos['proveedor'] > 0) {
            $cambios['proveedor'] = (int) $crudos['proveedor'];
        }
        if (isset($crudos['stock']) && is_numeric($crudos['stock']) && (int) $crudos['stock'] >= 0) {
            $cambios['stock'] = (int) $crudos['stock'];
        }
        if (!$ids || !$cambios) {
            return $this->respuesta(400, [], ['Elegí productos y al menos un cambio.'], 400);
        }
        try {
            $modificados = $products_service->editarMasivo($idNegocio, $ids, $cambios);
            return $this->respuesta(200, ['modificados' => $modificados], []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], ['No se pudieron modificar los productos.'], 400);
        }
    }

    /**
     * Importación desde planilla (solo administrador): {sucursal, filas: [{code, description,
     * salePrice, costPrice?, quantity?, idProveedor?, barcode?, size?}]}. Con "simular": true
     * solo valida y cuenta, sin guardar.
     */
    #[Route('/importar', name: 'app_products_importar', methods: ['POST'])]
    public function importar(Request $request, ProductsService $products_service, SuscripcionService $suscripciones): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $idNegocio = $this->idNegocioUsuarioActual();
        $datos = json_decode($request->getContent(), true) ?: [];
        $sucursal = (int) ($datos['sucursal'] ?? 0);
        if ($check = $this->sucursalPermitida($sucursal)) {
            return $check;
        }
        $crudas = is_array($datos['filas'] ?? null) ? $datos['filas'] : [];
        if (!$crudas) {
            return $this->respuesta(400, [], ['La planilla no tiene filas.'], 400);
        }
        if (count($crudas) > 10000) {
            return $this->respuesta(400, [], ['La planilla tiene más de 10.000 filas. Dividila en partes.'], 400);
        }

        [$filas, $errores] = ProductsService::normalizarFilasImportacion($crudas);
        try {
            $nuevos = $products_service->contarNuevosImportacion($idNegocio, $sucursal, $filas);
            $resumen = ['validas' => count($filas), 'nuevos' => $nuevos, 'actualizar' => count($filas) - $nuevos, 'errores' => $errores];
            if ($errores || !empty($datos['simular'])) {
                return $this->respuesta($errores ? 400 : 200, $resumen, $errores, $errores ? 400 : 200);
            }
            if ($nuevos > 0 && ($mensaje = $suscripciones->excedeLimite($idNegocio, 'productos', $nuevos))) {
                return $this->respuesta(403, $resumen, [$mensaje], 403);
            }
            $resultado = $products_service->importar($idNegocio, $sucursal, $filas);
            return $this->respuesta(200, $resultado, []);
        } catch (\RuntimeException $e) {
            return $this->respuesta(400, [], [$e->getMessage()], 400);
        } catch (\Throwable $th) {
            error_log('importar productos: ' . $th->getMessage());
            return $this->respuesta(400, [], ['No se pudo importar la planilla.'], 400);
        }
    }
}
