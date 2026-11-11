<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente mínimo de la API de Mercado Pago para Suscripciones (preapproval).
 * Docs: https://www.mercadopago.com.ar/developers/es/reference/subscriptions
 *
 * Los datos de tarjeta nunca pasan por nuestro servidor: el cliente autoriza el
 * débito en el checkout de Mercado Pago (init_point).
 */
class MercadoPagoClient
{
    private const API = 'https://api.mercadopago.com';

    private HttpClientInterface $http;

    public function __construct(HttpClientInterface $http)
    {
        $this->http = $http;
    }

    public function configurado(): bool
    {
        return Entorno::get('MP_ACCESS_TOKEN') !== '';
    }

    private function pedir(string $metodo, string $ruta, ?array $json = null): array
    {
        $opciones = [
            'headers' => ['Authorization' => 'Bearer ' . Entorno::get('MP_ACCESS_TOKEN')],
            'timeout' => 20,
        ];
        if ($json !== null) {
            $opciones['json'] = $json;
            // Evita duplicar la operación si se reintenta la misma request.
            $opciones['headers']['X-Idempotency-Key'] = hash('sha256', $metodo . $ruta . json_encode($json) . date('Y-m-d H:i'));
        }
        $respuesta = $this->http->request($metodo, self::API . $ruta, $opciones);
        $datos = $respuesta->toArray(false);
        if ($respuesta->getStatusCode() >= 300) {
            throw new \RuntimeException('Mercado Pago respondió ' . $respuesta->getStatusCode() . ': ' . ($datos['message'] ?? json_encode($datos)));
        }
        return $datos;
    }

    /**
     * Crea una suscripción pendiente de autorización y devuelve id e init_point
     * (link al checkout de Mercado Pago).
     */
    public function crearSuscripcion(int $idNegocio, array $plan, string $emailPagador): array
    {
        return $this->pedir('POST', '/preapproval', [
            'reason' => 'Kontia - Plan ' . $plan['nombre'],
            'external_reference' => self::referencia($idNegocio, (string) $plan['codigo']),
            'payer_email' => $emailPagador,
            'back_url' => Entorno::get('MP_BACK_URL', Entorno::get('APP_URL_FRONT') . '/mi-plan'),
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float) $plan['precio_mensual'],
                'currency_id' => 'ARS',
            ],
            'status' => 'pending',
        ]);
    }

    public function obtenerSuscripcion(string $id): array
    {
        return $this->pedir('GET', '/preapproval/' . rawurlencode($id));
    }

    /**
     * Cambia el monto de una suscripción ya autorizada (cambio de plan).
     */
    public function cambiarMonto(string $id, array $plan, int $idNegocio): array
    {
        return $this->pedir('PUT', '/preapproval/' . rawurlencode($id), [
            'reason' => 'Kontia - Plan ' . $plan['nombre'],
            'external_reference' => self::referencia($idNegocio, (string) $plan['codigo']),
            'auto_recurring' => [
                'transaction_amount' => (float) $plan['precio_mensual'],
                'currency_id' => 'ARS',
            ],
        ]);
    }

    public function cancelarSuscripcion(string $id): array
    {
        return $this->pedir('PUT', '/preapproval/' . rawurlencode($id), ['status' => 'cancelled']);
    }

    /**
     * Cobro mensual de una suscripción (evento subscription_authorized_payment).
     */
    public function obtenerCobro(string $id): array
    {
        return $this->pedir('GET', '/authorized_payments/' . rawurlencode($id));
    }

    public static function referencia(int $idNegocio, string $codigoPlan): string
    {
        return 'kontia:' . $idNegocio . ':' . $codigoPlan;
    }

    /**
     * @return array{0: int, 1: string}|null [id_negocio, codigo_plan]
     */
    public static function leerReferencia(?string $ref): ?array
    {
        if ($ref && preg_match('/^kontia:(\d+):([a-z0-9_-]+)$/', $ref, $m)) {
            return [(int) $m[1], $m[2]];
        }
        return null;
    }

    /**
     * Valida el header x-signature de un webhook ("ts=...,v1=...").
     * Plantilla firmada: "id:{data.id};request-id:{x-request-id};ts:{ts};"
     */
    public static function firmaValida(?string $firma, ?string $requestId, string $dataId, string $secreto, int $toleranciaSegundos = 600): bool
    {
        if (!$firma || $secreto === '') {
            return false;
        }
        $partes = [];
        foreach (explode(',', $firma) as $par) {
            [$k, $v] = array_map('trim', explode('=', $par, 2) + [1 => '']);
            $partes[$k] = $v;
        }
        if (empty($partes['ts']) || empty($partes['v1'])) {
            return false;
        }
        // ts viene en milisegundos o segundos según la versión.
        $ts = (int) $partes['ts'];
        $segundos = $ts > 100000000000 ? intdiv($ts, 1000) : $ts;
        if (abs(time() - $segundos) > $toleranciaSegundos) {
            return false;
        }
        $id = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
        $plantilla = 'id:' . $id . ';';
        if ($requestId) {
            $plantilla .= 'request-id:' . $requestId . ';';
        }
        $plantilla .= 'ts:' . $partes['ts'] . ';';
        return hash_equals(hash_hmac('sha256', $plantilla, $secreto), $partes['v1']);
    }
}
