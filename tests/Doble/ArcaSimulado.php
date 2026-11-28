<?php

namespace App\Tests\Doble;

use App\Service\Arca\ArcaSinRespuesta;
use App\Service\Arca\ArcaTransporte;

/** ARCA de mentira para los tests: guarda lo que se le pidió y responde como ARCA. */
class ArcaSimulado implements ArcaTransporte
{
    /** @var array<int, array{url: string, accion: string, xml: string}> */
    public array $pedidos = [];
    /** Último número autorizado por tipo de comprobante. */
    public array $ultimo = [];
    /** Comprobantes que ARCA "ya emitió": "tipo-numero" => total. */
    public array $emitidos = [];
    public bool $caido = false;
    /** Si se pone, FECAESolicitar emite el CAE pero la respuesta "se pierde". */
    public bool $perderRespuesta = false;
    public ?string $rechazo = null;

    public function enviar(string $url, string $soapAction, string $xml): string
    {
        $this->pedidos[] = ['url' => $url, 'accion' => $soapAction, 'xml' => $xml];
        if ($this->caido) {
            throw new ArcaSinRespuesta('Sin conexión (simulado).');
        }
        $metodo = substr($soapAction, strrpos($soapAction, '/') + 1);
        if ($soapAction === '') {
            $ticket = '<?xml version="1.0" encoding="UTF-8"?><loginTicketResponse version="1.0"><header><expirationTime>'
                . date('c', time() + 43200) . '</expirationTime></header><credentials><token>TOKEN-' . count($this->pedidos)
                . '</token><sign>SIGN</sign></credentials></loginTicketResponse>';
            return $this->sobre('<loginCmsResponse><loginCmsReturn>' . htmlspecialchars($ticket, ENT_XML1) . '</loginCmsReturn></loginCmsResponse>');
        }
        $tipo = (int) $this->valor($xml, 'CbteTipo');
        switch ($metodo) {
            case 'FEDummy':
                return $this->sobre('<FEDummyResponse><FEDummyResult><AppServer>OK</AppServer><DbServer>OK</DbServer><AuthServer>OK</AuthServer></FEDummyResult></FEDummyResponse>');
            case 'FECompUltimoAutorizado':
                return $this->sobre('<FECompUltimoAutorizadoResponse><FECompUltimoAutorizadoResult><PtoVta>' . $this->valor($xml, 'PtoVta')
                    . '</PtoVta><CbteTipo>' . $tipo . '</CbteTipo><CbteNro>' . ($this->ultimo[$tipo] ?? 0) . '</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse>');
            case 'FECompConsultar':
                $nro = (int) $this->valor($xml, 'CbteNro');
                if (!isset($this->emitidos["$tipo-$nro"])) {
                    return $this->sobre('<FECompConsultarResponse><FECompConsultarResult><Errors><Err><Code>602</Code><Msg>No existen datos</Msg></Err></Errors></FECompConsultarResult></FECompConsultarResponse>');
                }
                return $this->sobre('<FECompConsultarResponse><FECompConsultarResult><ResultGet><CbteDesde>' . $nro . '</CbteDesde><ImpTotal>'
                    . $this->emitidos["$tipo-$nro"] . '</ImpTotal><DocNro>0</DocNro><CodAutorizacion>7' . str_pad((string) $nro, 13, '0', STR_PAD_LEFT)
                    . '</CodAutorizacion><FchVto>20991231</FchVto></ResultGet></FECompConsultarResult></FECompConsultarResponse>');
            case 'FECAESolicitar':
                if ($this->rechazo) {
                    return $this->sobre('<FECAESolicitarResponse><FECAESolicitarResult><FeCabResp><Resultado>R</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><Resultado>R</Resultado><Observaciones><Obs><Code>10015</Code><Msg>'
                        . $this->rechazo . '</Msg></Obs></Observaciones></FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse>');
                }
                $nro = (int) $this->valor($xml, 'CbteDesde');
                if ($nro !== ($this->ultimo[$tipo] ?? 0) + 1) {
                    return $this->sobre('<FECAESolicitarResponse><FECAESolicitarResult><FeCabResp><Resultado>R</Resultado></FeCabResp><Errors><Err><Code>10016</Code><Msg>El numero no es el proximo</Msg></Err></Errors></FECAESolicitarResult></FECAESolicitarResponse>');
                }
                $this->ultimo[$tipo] = $nro;
                $this->emitidos["$tipo-$nro"] = $this->valor($xml, 'ImpTotal');
                if ($this->perderRespuesta) {
                    $this->perderRespuesta = false;
                    throw new ArcaSinRespuesta('Se cortó la respuesta (simulado).');
                }
                return $this->sobre('<FECAESolicitarResponse><FECAESolicitarResult><FeCabResp><Resultado>A</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><Resultado>A</Resultado><CAE>7'
                    . str_pad((string) $nro, 13, '0', STR_PAD_LEFT) . '</CAE><CAEFchVto>20991231</CAEFchVto></FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse>');
        }
        throw new \LogicException('Método no simulado: ' . $soapAction);
    }

    /** Pedidos hechos a un método (FECAESolicitar, etc.). */
    public function llamadas(string $metodo): array
    {
        return array_values(array_filter($this->pedidos, fn ($p) => str_ends_with($p['accion'], '/' . $metodo)));
    }

    public function valor(string $xml, string $nombre): ?string
    {
        return preg_match('#<ar:' . $nombre . '>([^<]*)</ar:' . $nombre . '>#', $xml, $m) ? $m[1] : null;
    }

    private function sobre(string $cuerpo): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>' . $cuerpo . '</soap:Body></soap:Envelope>';
    }
}
