<?php

namespace App\Service\Arca;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpArcaTransporte implements ArcaTransporte
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    public function enviar(string $url, string $soapAction, string $xml): string
    {
        try {
            $respuesta = $this->http->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => '"' . $soapAction . '"',
                ],
                'body' => $xml,
                'timeout' => 15,
                'max_duration' => 25,
            ]);
            $estado = $respuesta->getStatusCode();
            $cuerpo = $respuesta->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new ArcaSinRespuesta('ARCA no respondió: ' . $e->getMessage(), 0, $e);
        }
        // Los errores SOAP vienen con 500 y un <Fault> en el cuerpo: esos sí se leen.
        if ($estado >= 500 && stripos($cuerpo, 'Fault') === false) {
            throw new ArcaSinRespuesta("ARCA respondió con error $estado.");
        }
        if ($cuerpo === '') {
            throw new ArcaSinRespuesta('ARCA respondió vacío.');
        }
        return $cuerpo;
    }
}
