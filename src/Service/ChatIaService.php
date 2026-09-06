<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatIaService
{
    private $httpClient;
    private $agenteUrl;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        // Igual que el resto del proyecto (ver BaseRepository::get_bbdd()): se lee
        // directo de $_ENV, sin pasar por el sistema de parámetros %env()% de
        // Symfony, para no depender de que el compilador del contenedor "vea" el
        // servicio como usado (falla en prod si no).
        $this->agenteUrl = $_ENV['AGENTE_IA_URL'] ?? '';
    }

    /**
     * $authorization es el header "Authorization" tal cual llegó en la request del
     * usuario logueado (ej. "Bearer eyJ..."). Se reenvía sin tocar: el agente saca
     * negocio_id y roles de ese JWT, nunca del body.
     */
    public function consultarAgente(string $authorization, string $mensaje, string $threadId = '1', ?string $sucursalId = null): array
    {
        $body = [
            'mensaje' => $mensaje,
            'thread_id' => $threadId,
        ];
        if ($sucursalId !== null && $sucursalId !== '') {
            $body['sucursal_id'] = $sucursalId;
        }

        $response = $this->httpClient->request('POST', rtrim($this->agenteUrl, '/') . '/asistente/mensaje', [
            'headers' => [
                'Authorization' => $authorization,
            ],
            'json' => $body,
            'timeout' => 60, // Dale margen por los tiempos de respuesta del LLM
        ]);

        return $response->toArray();
    }
}
