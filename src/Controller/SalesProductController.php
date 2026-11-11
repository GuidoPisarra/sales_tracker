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


#[Route('/api/salesProduct')]
class SalesProductController extends BaseController
{

    #[Route('/salesProduct', name: 'app_add_salesProduct', methods: ['POST'])]
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

        // Venta hecha sin conexión que se envía después: el front manda la hora real de
        // la venta. Se acepta solo si es de los últimos 7 días y no es futura.
        $fechaOffline = (string) $request->headers->get('X-Venta-Fecha', '');
        if ($fechaOffline !== '') {
            $f = \DateTime::createFromFormat('Y-m-d H:i:s', $fechaOffline, $zonaHorariaArgentina);
            if ($f && $f <= $fechaArgentina && $f >= (clone $fechaArgentina)->modify('-7 days')) {
                $fechaFormateada = $f->format('Y-m-d H:i:s');
            }
        }
        $clave = (string) $request->headers->get('X-Venta-Clave', '');
        $clave = preg_match('/^[A-Za-z0-9-]{8,64}$/', $clave) ? $clave : null;
        $idNegocioUsuario = $this->idNegocioUsuarioActual();
        // Venta sin conexión (trae X-Venta-Fecha): ya ocurrió, así que no se rechaza por stock.
        $sinConexion = $fechaOffline !== '';
        // Productos que el vendedor confirmó "vender igual" aunque el sistema dijera que no había stock.
        $forzarStock = [];
        foreach ($datos as $venta) {
            $forzarStock[] = !empty($venta['sinStock']);
            if ($check = $this->sucursalPermitida($venta['sucursal'] ?? null)) {
                return $check;
            }
            $dto = new AddSalesProductDTO();
            $dto->setIdProduct($venta['id']);
            $dto->setSaleDay($fechaFormateada);
            $dto->setPrice($venta['salePrice']);
            $dto->setQuantity($venta['cantidad']);
            $dto->setTypePayment($venta['typePayment']);
            // El id_negocio nunca se toma del body: siempre el del usuario autenticado.
            $dto->setIdNegocio((string) $idNegocioUsuario);
            $dto->setSucursal($venta['sucursal']);
            // Quién vendió sale del token, no del body.
            $dto->setUsuario($this->idUsuarioActual());
            $this->createForm(AddSalesProductType::class, $dto);
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
                $sales_product = $salesProduct_service->save_salesProduct($datosDto, $clave, $forzarStock, $sinConexion);
                if ($sales_product === 'duplicada') {
                    return $this->respuesta(200, [true], [], 200, ['X-Venta-Duplicada' => '1']);
                }
                // datos[0] sigue siendo true (el front lo usa así); datos[1] lista lo que faltó.
                $faltantes = $salesProduct_service->ultimosFaltantes();
                return $this->respuesta(200, $faltantes ? [$sales_product, ['faltantes' => $faltantes]] : [$sales_product], []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], [$th->getMessage()], 400);
            }
            return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
        }
        return $this->respuesta(400, $datosDto, ['Ocurrió un error desconocido.'], 400);
    }

    /** Productos vendidos sin stock que el administrador todavía no revisó. */
    #[Route('/faltantes', name: 'app_salesProduct_faltantes', methods: ['GET'])]
    public function faltantes(Request $request, SalesProductService $salesProduct_service): JsonResponse
    {
        if (!$this->esTitular()) {
            return $this->respuesta(403, [], ['Solo el administrador puede ver el stock a revisar.'], 403);
        }
        $todos = $request->query->get('todos') === '1';
        return $this->respuesta(200, $salesProduct_service->faltantes((int) $this->idNegocioUsuarioActual(), $todos), []);
    }

    #[Route('/faltantes/revisados', name: 'app_salesProduct_faltantes_revisados', methods: ['POST'])]
    public function faltantesRevisados(Request $request, SalesProductService $salesProduct_service): JsonResponse
    {
        if (!$this->esTitular()) {
            return $this->respuesta(403, [], ['Solo el administrador puede marcar el stock como revisado.'], 403);
        }
        $this->request_to_json($request);
        $ids = $request->request->all()['ids'] ?? [];
        $ids = is_array($ids) ? $ids : [];
        $marcados = $salesProduct_service->marcarFaltantesRevisados((int) $this->idNegocioUsuarioActual(), $ids);
        return $this->respuesta(200, ['marcados' => $marcados], []);
    }

    #[Route('/salesProduct/delete', name: 'app_delete_salesProduct', methods: ['POST'])]
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
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $idNegocioReal = $salesProduct_service->obtenerIdNegocioVenta($dto->getIdSaleProduct());
            if ($idNegocioReal === null) {
                return $this->respuesta(404, [], ['Venta no encontrada'], 404);
            }
            if ($check = $this->negocioPermitido($idNegocioReal)) {
                return $check;
            }
            try {
                $sales_product_delete = $salesProduct_service->delete_salesProduct($dto);
                return $this->respuesta(200, [$sales_product_delete], []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
            }
            return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    /**
     * Cambia la forma de pago de una venta ya registrada (todas las líneas del mismo id_sale).
     * Body: { "idSaleProduct": <id_sale>, "typePayment": "efectivo" | "tarjeta" | "dni" | "mercadopago" }.
     * No pasa a ni desde "Cta Cte": eso implica crear/borrar la deuda del cliente en ctacte.
     */
    #[Route('/salesProduct/typePayment', name: 'app_type_payment_salesProduct', methods: ['POST'])]
    public function salesProduct_type_payment(Request $request, SalesProductService $salesProduct_service): JsonResponse
    {
        $this->request_to_json($request);
        $idSale = (int) $request->request->get('idSaleProduct', 0);
        $typePayment = trim((string) $request->request->get('typePayment', ''));

        if ($idSale <= 0) {
            return $this->respuesta(400, [], ['Falta el id de la venta.'], 400);
        }
        if (!in_array($typePayment, SalesProductService::FORMAS_PAGO_EDITABLES, true)) {
            return $this->respuesta(400, [], ['Forma de pago inválida.'], 400);
        }

        $idNegocioReal = $salesProduct_service->obtenerIdNegocioVenta($idSale);
        if ($idNegocioReal === null) {
            return $this->respuesta(404, [], ['Venta no encontrada'], 404);
        }
        if ($check = $this->negocioPermitido($idNegocioReal)) {
            return $check;
        }

        try {
            $salesProduct_service->cambiarFormaPago($idSale, $typePayment, $idNegocioReal);
            return $this->respuesta(200, ['OK' => 'OK', 'typePayment' => $typePayment], []);
        } catch (\Throwable $th) {
            return $this->respuesta(400, [], [$th->getMessage()], 400);
        }
    }

    #[Route('/salesProduct/register', name: 'app_registe_salesProduct', methods: ['POST'])]
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
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $idNegocioReal = $salesProduct_service->obtenerIdNegocioVenta($dto->getIdSaleProduct());
            if ($idNegocioReal === null) {
                return $this->respuesta(404, [], ['Venta no encontrada'], 404);
            }
            if ($check = $this->negocioPermitido($idNegocioReal)) {
                return $check;
            }
            try {
                $sales_product_delete = $salesProduct_service->register_salesProduct($dto);
                return $this->respuesta(200, [$sales_product_delete], []);
            } catch (\Throwable $th) {
                return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
            }
            return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
        }
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
