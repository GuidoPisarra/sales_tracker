<?php

namespace App\Service\Arca;

/** Envía un sobre SOAP a ARCA y devuelve la respuesta cruda (para poder simularlo en tests). */
interface ArcaTransporte
{
    /**
     * @throws ArcaSinRespuesta si no hubo respuesta (sin conexión, timeout, error 5xx).
     */
    public function enviar(string $url, string $soapAction, string $xml): string;
}
