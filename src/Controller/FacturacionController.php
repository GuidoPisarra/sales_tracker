<?php

namespace App\Controller;

use App\Service\Arca\ArcaRechazo;
use App\Service\Arca\ArcaSinRespuesta;
use App\Service\FacturacionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Factura electrónica ARCA. La configuración es solo del administrador; facturar una
 * venta lo puede hacer cualquiera que vende.
 */
#[Route('/api/facturacion')]
class FacturacionController extends BaseController
{
    #[Route('/config', name: 'app_facturacion_config', methods: ['GET'])]
    public function config(FacturacionService $servicio): JsonResponse
    {
        $config = $servicio->config((int) $this->idNegocioUsuarioActual());
        if (!$this->esTitular()) {
            // El empleado solo necesita saber si puede facturar.
            $config = array_intersect_key($config, array_flip(['incluido_en_plan', 'activo', 'modo', 'condicion_iva', 'tope_consumidor_final']));
        }
        return $this->respuesta(200, $config, []);
    }

    #[Route('/config', name: 'app_facturacion_config_guardar', methods: ['POST'])]
    public function guardarConfig(Request $request, FacturacionService $servicio): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        return $this->ejecutar(fn () => $servicio->guardarConfig((int) $this->idNegocioUsuarioActual(), $this->cuerpo($request)));
    }

    /** Genera la clave y el pedido de certificado (CSR) para subir a ARCA. */
    #[Route('/pedido-certificado', name: 'app_facturacion_pedido', methods: ['POST'])]
    public function pedido(FacturacionService $servicio): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        return $this->ejecutar(fn () => ['pedido' => $servicio->generarPedido((int) $this->idNegocioUsuarioActual())]);
    }

    #[Route('/certificado', name: 'app_facturacion_certificado', methods: ['POST'])]
    public function certificado(Request $request, FacturacionService $servicio): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $pem = (string) ($this->cuerpo($request)['certificado'] ?? '');
        if (trim($pem) === '' || strlen($pem) > 20000) {
            return $this->respuesta(400, [], ['Pegá el contenido del certificado que te dio ARCA.'], 400);
        }
        return $this->ejecutar(fn () => $servicio->guardarCertificado((int) $this->idNegocioUsuarioActual(), $pem));
    }

    #[Route('/probar', name: 'app_facturacion_probar', methods: ['POST'])]
    public function probar(FacturacionService $servicio): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        return $this->ejecutar(fn () => $servicio->probar((int) $this->idNegocioUsuarioActual()));
    }

    /**
     * Factura una venta: {idVenta, receptor?: {condicion, doc_nro, nombre}}.
     * Responde 200 con el comprobante aunque quede pendiente (ARCA sin respuesta) o
     * rechazado: el estado y el motivo vienen adentro.
     */
    #[Route('/emitir', name: 'app_facturacion_emitir', methods: ['POST'])]
    public function emitir(Request $request, FacturacionService $servicio): JsonResponse
    {
        $d = $this->cuerpo($request);
        $idVenta = (int) ($d['idVenta'] ?? 0);
        if ($idVenta <= 0) {
            return $this->respuesta(400, [], ['Falta el número de venta.'], 400);
        }
        $receptor = is_array($d['receptor'] ?? null) ? $d['receptor'] : [];
        return $this->ejecutar(fn () => $servicio->facturar((int) $this->idNegocioUsuarioActual(), $idVenta, $receptor));
    }

    /** Comprobantes de una venta (para el ticket). */
    #[Route('/venta/{idVenta}', name: 'app_facturacion_venta', requirements: ['idVenta' => '\d+'], methods: ['GET'])]
    public function venta(int $idVenta, FacturacionService $servicio): JsonResponse
    {
        return $this->respuesta(200, $servicio->deVenta((int) $this->idNegocioUsuarioActual(), $idVenta), []);
    }

    /** Comprobantes emitidos: ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&estado=pendiente|emitida|rechazada */
    #[Route('/lista', name: 'app_facturacion_lista', methods: ['GET'])]
    public function lista(Request $request, FacturacionService $servicio): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $fecha = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $estado = $request->query->get('estado');
        $estado = in_array($estado, ['pendiente', 'emitida', 'rechazada'], true) ? $estado : null;
        return $this->respuesta(200, $servicio->lista(
            (int) $this->idNegocioUsuarioActual(),
            $fecha($request->query->get('desde')),
            $fecha($request->query->get('hasta')),
            $estado
        ), []);
    }

    #[Route('/reintentar/{id}', name: 'app_facturacion_reintentar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reintentar(int $id, FacturacionService $servicio): JsonResponse
    {
        return $this->ejecutar(fn () => $servicio->reintentar((int) $this->idNegocioUsuarioActual(), $id));
    }

    private function cuerpo(Request $request): array
    {
        $d = json_decode($request->getContent(), true);
        return is_array($d) ? $d : [];
    }

    private function ejecutar(callable $accion): JsonResponse
    {
        try {
            return $this->respuesta(200, $accion(), []);
        } catch (ArcaRechazo $e) {
            return $this->respuesta(400, [], [$e->getMessage()], 400);
        } catch (ArcaSinRespuesta $e) {
            return $this->respuesta(503, [], ['ARCA no responde en este momento. Probá de nuevo en unos minutos.'], 503);
        } catch (\Throwable $e) {
            error_log('facturacion: ' . $e->getMessage());
            return $this->respuesta(500, [], ['No se pudo completar la operación de facturación.'], 500);
        }
    }
}
