<?php

namespace App\Controller;

use App\DTO\ChangesProduct\ChangeProductDTO;
use App\Form\Type\ChangeProduct\ChangeProductType;
use App\Service\ChangeProductService;
use App\Service\ExpenseService;
use App\Service\ProductsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/changes')]
class ChangesController extends BaseController
{

    #[Route('/change', name: 'app_change', methods: ['POST'])]
    public function change_product(Request $request, ValidatorInterface $validator, ChangeProductService $change_service, ProductsService $products_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new ChangeProductDTO();
        $form = $this->createForm(ChangeProductType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Los dos productos involucrados en el cambio tienen que ser del negocio del usuario autenticado.
            $idNegocioUsuario = $this->idNegocioUsuarioActual();
            $idNegocioCambio = $products_service->obtenerIdNegocio($dto->getIdProductoCambio());
            $idNegocioNuevo = $products_service->obtenerIdNegocio($dto->getIdProductoNuevo());
            if ($idNegocioCambio === null || $idNegocioNuevo === null
                || $idNegocioCambio !== $idNegocioUsuario || $idNegocioNuevo !== $idNegocioUsuario) {
                return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
            }
            // El id_negocio nunca se toma del body: siempre el del usuario autenticado.
            $dto->setIdNegocio($idNegocioUsuario);
            try {
                $resultado = $change_service->add_change($dto);
                if ($resultado !== true) {
                    //$log::get_log()->error('ENDPOINT: app_agregar_gastos ERROR: Ocurrió un error grabando el gasto.');
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

    /**
     * Cambio de un producto vendido por uno o más productos nuevos.
     * Body: {
     *   "id_producto_cambio": 10, "precio_producto_cambio": 5000,
     *   "productos_nuevos": [ { "id": 20, "precio": 3000, "cantidad": 1 }, { "id": 21, "precio": 2500, "cantidad": 2 } ]
     * }
     * Se guarda una fila en "cambios" por cada unidad nueva (la tabla no tiene cantidad) y el
     * precio del producto devuelto va solo en la primera, así los totales del balance
     * (SUM(precio_producto_cambio - precio_producto_nuevo)) siguen dando bien.
     */
    #[Route('/change_multiple', name: 'app_change_multiple', methods: ['POST'])]
    public function change_product_multiple(Request $request, ChangeProductService $change_service, ProductsService $products_service): JsonResponse
    {
        $this->request_to_json($request);
        $datos = $request->request->all();

        $idCambio = (int) ($datos['id_producto_cambio'] ?? 0);
        $precioCambio = $datos['precio_producto_cambio'] ?? null;
        $nuevosBody = $datos['productos_nuevos'] ?? null;

        if ($idCambio <= 0 || !is_numeric($precioCambio)) {
            return $this->respuesta(400, [], ['Falta el producto vendido o su precio.'], 400);
        }
        if (!is_array($nuevosBody) || count($nuevosBody) === 0) {
            return $this->respuesta(400, [], ['Tiene que elegir al menos un producto nuevo.'], 400);
        }

        $nuevos = [];
        foreach ($nuevosBody as $nuevo) {
            $id = (int) ($nuevo['id'] ?? 0);
            $precio = $nuevo['precio'] ?? null;
            $cantidad = (int) ($nuevo['cantidad'] ?? 1);
            if ($id <= 0 || !is_numeric($precio) || $cantidad <= 0) {
                return $this->respuesta(400, [], ['Hay un producto nuevo con datos inválidos.'], 400);
            }
            if ($id === $idCambio) {
                return $this->respuesta(400, [], ['El producto vendido y el nuevo no pueden ser el mismo.'], 400);
            }
            $nuevos[] = ['id' => $id, 'precio' => (float) $precio, 'cantidad' => $cantidad];
        }

        // Todos los productos involucrados tienen que ser del negocio del usuario autenticado.
        $idNegocioUsuario = $this->idNegocioUsuarioActual();
        $ids = array_unique(array_merge([$idCambio], array_column($nuevos, 'id')));
        foreach ($ids as $id) {
            $idNegocioProducto = $products_service->obtenerIdNegocio($id);
            if ($idNegocioUsuario === null || $idNegocioProducto === null || $idNegocioProducto !== $idNegocioUsuario) {
                return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
            }
        }

        try {
            $change_service->add_change_multiple($idCambio, (float) $precioCambio, $nuevos, $idNegocioUsuario);
            return $this->respuesta(200, ['OK' => 'OK'], []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], [$th->getMessage()], 400);
        }
    }
}
