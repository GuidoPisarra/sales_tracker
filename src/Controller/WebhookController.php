<?php

namespace App\Controller;

use App\Repository\SuscripcionRepository;
use App\Service\CobrosService;
use App\Service\Entorno;
use App\Service\MercadoPagoClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends BaseController
{
    /**
     * Avisos de Mercado Pago. Se valida la firma, y nunca se confía en el
     * contenido del aviso: se consulta el estado real a la API de Mercado Pago.
     * Mercado Pago reintenta si no recibe 200/201, así que todo es idempotente.
     */
    #[Route('/api/webhooks/mercadopago', name: 'app_webhook_mercadopago', methods: ['POST'])]
    public function mercadoPago(Request $request, MercadoPagoClient $mp, CobrosService $cobros, SuscripcionRepository $repo): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?: [];
        $tipo = (string) ($body['type'] ?? $request->query->get('type', ''));
        $dataId = (string) ($request->query->get('data_id') ?? $request->query->get('data.id') ?? ($body['data']['id'] ?? ''));
        $requestId = $request->headers->get('x-request-id');

        if ($dataId === '' || !MercadoPagoClient::firmaValida($request->headers->get('x-signature'), $requestId, $dataId, Entorno::get('MP_WEBHOOK_SECRET'))) {
            return new JsonResponse(['ok' => false], 401);
        }
        if ($requestId && !$repo->registrarEvento('mercadopago', $requestId)) {
            return new JsonResponse(['ok' => true, 'repetido' => true]);
        }

        try {
            switch ($tipo) {
                case 'subscription_preapproval':
                    $cobros->aplicarSuscripcion($mp->obtenerSuscripcion($dataId));
                    break;
                case 'subscription_authorized_payment':
                    $cobros->aplicarCobro($mp->obtenerCobro($dataId));
                    break;
                default:
                    // Otros avisos (pagos sueltos, etc.) no se usan.
                    break;
            }
        } catch (\Throwable $th) {
            error_log('Webhook MP ' . $tipo . ' ' . $dataId . ': ' . $th->getMessage());
            // 500 para que Mercado Pago reintente más tarde.
            return new JsonResponse(['ok' => false], 500);
        }
        return new JsonResponse(['ok' => true]);
    }
}
