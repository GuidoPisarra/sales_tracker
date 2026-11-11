<?php

namespace App\Controller;

use App\Repository\SuscripcionRepository;
use App\Service\CobrosService;
use App\Service\SuscripcionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class SuscripcionController extends BaseController
{
    /**
     * Planes a la venta (público, para la landing y el registro).
     */
    #[Route('/api/planes', name: 'app_planes', methods: ['GET'])]
    public function planes(SuscripcionService $suscripciones): JsonResponse
    {
        return $this->respuesta(200, $suscripciones->planesPublicos(), []);
    }

    /**
     * Pantalla "Mi plan": plan actual, estado, uso contra límites, planes y pagos.
     */
    #[Route('/api/suscripcion', name: 'app_suscripcion', methods: ['GET'])]
    public function ver(SuscripcionService $suscripciones, SuscripcionRepository $repo): JsonResponse
    {
        $idNegocio = $this->tenant()->idNegocioRequerido();
        $datos = $suscripciones->resumen($idNegocio, true);
        $datos['planes'] = $suscripciones->planesPublicos();
        $datos['pagos'] = $this->esTitular() ? $repo->pagos($idNegocio) : [];
        return $this->respuesta(200, $datos, []);
    }

    #[Route('/api/suscripcion/checkout', name: 'app_suscripcion_checkout', methods: ['POST'])]
    public function checkout(Request $request, CobrosService $cobros): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $body = json_decode($request->getContent(), true) ?: [];
        $plan = (string) ($body['plan'] ?? '');
        // Mercado Pago exige pagar con una cuenta cuyo email sea el del pagador:
        // si el titular usa otro email en Mercado Pago, lo manda en email_pago.
        $emailPago = trim((string) ($body['email_pago'] ?? ''));
        if ($emailPago !== '' && !filter_var($emailPago, FILTER_VALIDATE_EMAIL)) {
            return $this->respuesta(400, [], ['El email de Mercado Pago no es válido.'], 400);
        }
        try {
            $resultado = $cobros->elegirPlan($this->tenant()->idNegocioRequerido(), $plan, $emailPago !== '' ? $emailPago : $this->tenant()->usuario()->get_email());
            return $this->respuesta(200, $resultado, []);
        } catch (\DomainException $e) {
            return $this->respuesta(400, [], [$e->getMessage()], 400);
        } catch (\Throwable $th) {
            error_log('Checkout MP: ' . $th->getMessage());
            return $this->respuesta(502, [], ['No pudimos conectar con Mercado Pago. Probá de nuevo en unos minutos.'], 502);
        }
    }

    #[Route('/api/suscripcion/cancelar', name: 'app_suscripcion_cancelar', methods: ['POST'])]
    public function cancelar(CobrosService $cobros): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        try {
            $cobros->cancelar($this->tenant()->idNegocioRequerido());
            return $this->respuesta(200, ['cancelada' => true], []);
        } catch (\DomainException $e) {
            return $this->respuesta(400, [], [$e->getMessage()], 400);
        } catch (\Throwable $th) {
            error_log('Cancelar MP: ' . $th->getMessage());
            return $this->respuesta(502, [], ['No pudimos conectar con Mercado Pago. Probá de nuevo en unos minutos.'], 502);
        }
    }
}
