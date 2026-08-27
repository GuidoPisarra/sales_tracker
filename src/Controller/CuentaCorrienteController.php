<?php

namespace App\Controller;

use App\DTO\CuentaCorriente\ClienteDTO;
use App\DTO\CuentaCorriente\NuevaVentaCtaCteDTO;
use App\DTO\CuentaCorriente\PagoDTO;
use App\Form\Type\CuentaCorriente\ClienteType;
use App\Form\Type\CuentaCorriente\NuevaVentaCtaCteType;
use App\Form\Type\CuentaCorriente\PagoType;
use App\Service\ClientesService;
use App\Service\CuentaCorrienteService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/cuenta_corriente')]
class CuentaCorrienteController extends BaseController
{


    #[Route('/obtener_cuentas_corrientes/{id_negocio}', name: 'app_obtener_cuentas_corrientes', methods: ['GET'])]
    public function cuentas_list(Request $request, ValidatorInterface $validator, CuentaCorrienteService $service, int $id_negocio): JsonResponse
    {
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        try {
            $list_cuentas_corrientes = $service->list_cuentas_corrientes($id_negocio);
            return $this->respuesta(200, $list_cuentas_corrientes, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }


    #[Route('/obtener_cuentas_corrientes_con_deuda/{id_negocio}', name: 'app_obtener_cuentas_corrientes_con_deuda', methods: ['GET'])]
    public function cuentass_list_con_deuda(Request $request, ValidatorInterface $validator, CuentaCorrienteService $service, int $id_negocio): JsonResponse
    {
        if ($check = $this->negocioPermitido($id_negocio)) {
            return $check;
        }

        try {
            $list_cuentas_corrientes = $service->list_cuentas_corrientes_con_deuda($id_negocio);
            return $this->respuesta(200, $list_cuentas_corrientes, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/agregar_venta_cuenta_corriente', name: 'app_agregar_venta_cuenta_corriente', methods: ['POST'])]
    public function add_agregar_venta_cuenta_corriente(Request $request, ValidatorInterface $validator, CuentaCorrienteService $service, ClientesService $clientes_service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new NuevaVentaCtaCteDTO();
        $form = $this->createForm(NuevaVentaCtaCteType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        // El cliente de la venta tiene que ser del negocio del usuario autenticado.
        $idNegocioUsuario = $this->idNegocioUsuarioActual();
        $idNegocioCliente = $clientes_service->obtenerIdNegocio((int) $dto->getCliente()->getId());
        if ($idNegocioCliente === null || $idNegocioCliente !== $idNegocioUsuario) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        // El id_negocio de cada venta nunca se toma del body: siempre el del usuario autenticado.
        foreach ($dto->getVenta() as $venta) {
            $venta->setNegocio($idNegocioUsuario);
        }

        try {
            $resultado = $service->add_agregar_venta_cuenta_corriente($dto);
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

        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/obtener_movimientos_cliente/{id}', name: 'app_obtener_movimientos_cliente', methods: ['GET'])]
    public function obtener_movimientos_cliente(Request $request, ValidatorInterface $validator, CuentaCorrienteService $service, ClientesService $clientes_service, int $id): JsonResponse
    {
        $idNegocioCliente = $clientes_service->obtenerIdNegocio($id);
        if ($idNegocioCliente === null) {
            return $this->respuesta(404, [], ['Cliente no encontrado'], 404);
        }
        if ($check = $this->negocioPermitido($idNegocioCliente)) {
            return $check;
        }

        try {
            $movimientos = $service->obtener_movimientos_cliente($id);
            return $this->respuesta(200, $movimientos, []);
        } catch (\Throwable $th) {
            //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
            return $this->respuesta(400, [], ['Ocurrió un error al obtener los productos.'], 400);
        }
        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/agregar_pago', name: 'app_agregar_pago', methods: ['POST'])]
    public function add_agregar_pago(Request $request, ValidatorInterface $validator, CuentaCorrienteService $service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new PagoDTO();
        $form = $this->createForm(PagoType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        $idNegocioCtaCte = $service->obtenerIdNegocioCtaCte($dto->getIdCtaCte());
        if ($idNegocioCtaCte === null) {
            return $this->respuesta(404, [], ['Cuenta corriente no encontrada'], 404);
        }
        if ($check = $this->negocioPermitido($idNegocioCtaCte)) {
            return $check;
        }

        try {
            $resultado = $service->agregar_pago($dto);
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

        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }

    #[Route('/agregar_cliente', name: 'app_agregar_cliente', methods: ['POST'])]
    public function add_agregar_cliente(Request $request, ValidatorInterface $validator, CuentaCorrienteService $service): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new ClienteDTO();
        $form = $this->createForm(ClienteType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'app_agregar_productos');
            return $this->respuesta(400, [], $errores, 400);
        }

        // El id_negocio nunca se toma del body: siempre el del usuario autenticado.
        $dto->setIdNegocio($this->idNegocioUsuarioActual());

        try {
            $resultado = $service->agregar_cliente($dto);
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

        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
