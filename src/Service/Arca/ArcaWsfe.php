<?php

namespace App\Service\Arca;

/**
 * WSFEv1 de ARCA (factura electrónica, mercado interno). Habla SOAP "a mano" con
 * el HttpClient para no depender de ext-soap en el servidor.
 */
class ArcaWsfe
{
    public const URLS = [
        'homologacion' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
        'produccion' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
    ];
    private const NS = 'http://ar.gov.afip.dif.FEV1/';

    /** Códigos de alícuota de IVA de ARCA. */
    public const ALICUOTAS = ['0' => 3, '10.5' => 4, '21' => 5, '27' => 6, '5' => 8, '2.5' => 9];

    public function __construct(private ArcaTransporte $transporte)
    {
    }

    /** Estado de los servidores de ARCA (no necesita certificado). */
    public function estadoServidor(string $entorno): bool
    {
        $doc = $this->llamar($entorno, 'FEDummy', '');
        return strtoupper((string) ArcaXml::texto($doc, 'AppServer')) === 'OK'
            && strtoupper((string) ArcaXml::texto($doc, 'DbServer')) === 'OK';
    }

    public function ultimoAutorizado(string $entorno, array $auth, int $puntoVenta, int $tipo): int
    {
        $doc = $this->llamar($entorno, 'FECompUltimoAutorizado', self::auth($auth)
            . '<ar:PtoVta>' . $puntoVenta . '</ar:PtoVta><ar:CbteTipo>' . $tipo . '</ar:CbteTipo>');
        $this->lanzarErrores($doc);
        return (int) ArcaXml::texto($doc, 'CbteNro');
    }

    /**
     * Busca un comprobante ya emitido (para no duplicar si se cortó la respuesta).
     *
     * @return array{cae: string, vence: string, total: float, doc_nro: string}|null
     */
    public function consultar(string $entorno, array $auth, int $puntoVenta, int $tipo, int $numero): ?array
    {
        $doc = $this->llamar($entorno, 'FECompConsultar', self::auth($auth)
            . '<ar:FeCompConsReq><ar:CbteTipo>' . $tipo . '</ar:CbteTipo><ar:CbteNro>' . $numero . '</ar:CbteNro>'
            . '<ar:PtoVta>' . $puntoVenta . '</ar:PtoVta></ar:FeCompConsReq>');
        $cae = ArcaXml::texto($doc, 'CodAutorizacion');
        if (!$cae) {
            return null; // Error 602: no existe.
        }
        return [
            'cae' => $cae,
            'vence' => self::fecha((string) ArcaXml::texto($doc, 'FchVto')),
            'total' => (float) ArcaXml::texto($doc, 'ImpTotal'),
            'doc_nro' => (string) ArcaXml::texto($doc, 'DocNro'),
        ];
    }

    /**
     * Pide el CAE de un comprobante.
     *
     * $c: tipo, punto_venta, numero, fecha (Y-m-d), doc_tipo, doc_nro, condicion_iva_receptor,
     *     total, neto, iva, alicuota (null sin IVA), asociado (null o {tipo, punto_venta, numero, cuit, fecha})
     *
     * @return array{cae: string, vence: string, observaciones: string[]}
     * @throws ArcaRechazo si ARCA lo rechaza.
     */
    public function solicitarCae(string $entorno, array $auth, array $c): array
    {
        $f = fn (float $n) => number_format($n, 2, '.', '');
        $det = '<ar:Concepto>1</ar:Concepto>'
            . '<ar:DocTipo>' . (int) $c['doc_tipo'] . '</ar:DocTipo>'
            . '<ar:DocNro>' . preg_replace('/\D/', '', (string) $c['doc_nro']) . '</ar:DocNro>'
            . '<ar:CbteDesde>' . (int) $c['numero'] . '</ar:CbteDesde>'
            . '<ar:CbteHasta>' . (int) $c['numero'] . '</ar:CbteHasta>'
            . '<ar:CbteFch>' . str_replace('-', '', $c['fecha']) . '</ar:CbteFch>'
            . '<ar:ImpTotal>' . $f($c['total']) . '</ar:ImpTotal>'
            . '<ar:ImpTotConc>0.00</ar:ImpTotConc>'
            . '<ar:ImpNeto>' . $f($c['neto']) . '</ar:ImpNeto>'
            . '<ar:ImpOpEx>0.00</ar:ImpOpEx>'
            . '<ar:ImpTrib>0.00</ar:ImpTrib>'
            . '<ar:ImpIVA>' . $f($c['iva']) . '</ar:ImpIVA>'
            . '<ar:MonId>PES</ar:MonId>'
            . '<ar:MonCotiz>1</ar:MonCotiz>'
            . '<ar:CondicionIVAReceptorId>' . (int) $c['condicion_iva_receptor'] . '</ar:CondicionIVAReceptorId>';
        if (!empty($c['asociado'])) {
            $a = $c['asociado'];
            $det .= '<ar:CbtesAsoc><ar:CbteAsoc><ar:Tipo>' . (int) $a['tipo'] . '</ar:Tipo><ar:PtoVta>' . (int) $a['punto_venta']
                . '</ar:PtoVta><ar:Nro>' . (int) $a['numero'] . '</ar:Nro><ar:Cuit>' . ArcaXml::esc((string) $a['cuit'])
                . '</ar:Cuit><ar:CbteFch>' . str_replace('-', '', $a['fecha']) . '</ar:CbteFch></ar:CbteAsoc></ar:CbtesAsoc>';
        }
        if ($c['alicuota'] !== null) {
            $id = self::ALICUOTAS[self::claveAlicuota((float) $c['alicuota'])] ?? 5;
            $det .= '<ar:Iva><ar:AlicIva><ar:Id>' . $id . '</ar:Id><ar:BaseImp>' . $f($c['neto'])
                . '</ar:BaseImp><ar:Importe>' . $f($c['iva']) . '</ar:Importe></ar:AlicIva></ar:Iva>';
        }
        $cuerpo = self::auth($auth) . '<ar:FeCAEReq><ar:FeCabReq><ar:CantReg>1</ar:CantReg><ar:PtoVta>' . (int) $c['punto_venta']
            . '</ar:PtoVta><ar:CbteTipo>' . (int) $c['tipo'] . '</ar:CbteTipo></ar:FeCabReq>'
            . '<ar:FeDetReq><ar:FECAEDetRequest>' . $det . '</ar:FECAEDetRequest></ar:FeDetReq></ar:FeCAEReq>';

        $doc = $this->llamar($entorno, 'FECAESolicitar', $cuerpo);
        $obs = array_map(fn ($o) => $o['mensaje'] . ' (' . $o['codigo'] . ')', ArcaXml::mensajes($doc, 'Observaciones', 'Obs'));
        $errores = ArcaXml::mensajes($doc, 'Errors', 'Err');
        $resultado = strtoupper((string) ArcaXml::texto($doc, 'Resultado'));
        $cae = (string) ArcaXml::texto($doc, 'CAE');
        if ($resultado === 'A' && $cae !== '') {
            return ['cae' => $cae, 'vence' => self::fecha((string) ArcaXml::texto($doc, 'CAEFchVto')), 'observaciones' => $obs];
        }
        $motivos = array_merge(array_map(fn ($e) => $e['mensaje'] . ' (' . $e['codigo'] . ')', $errores), $obs);
        if (!$motivos && $resultado === '') {
            throw new ArcaSinRespuesta('ARCA respondió sin resultado.');
        }
        // 10016: el número no es el siguiente (otra caja emitió justo antes). Se reintenta.
        foreach ($errores as $e) {
            if (in_array($e['codigo'], [10016, 500, 501, 502], true)) {
                throw new ArcaSinRespuesta('ARCA: ' . $e['mensaje']);
            }
        }
        throw new ArcaRechazo('ARCA rechazó el comprobante: ' . implode(' · ', $motivos ?: ['sin detalle']));
    }

    public static function claveAlicuota(float $a): string
    {
        foreach (array_keys(self::ALICUOTAS) as $k) {
            if (abs((float) $k - $a) < 0.01) {
                return (string) $k;
            }
        }
        return '21';
    }

    private static function auth(array $auth): string
    {
        return '<ar:Auth><ar:Token>' . ArcaXml::esc($auth['token']) . '</ar:Token><ar:Sign>' . ArcaXml::esc($auth['sign'])
            . '</ar:Sign><ar:Cuit>' . preg_replace('/\D/', '', (string) $auth['cuit']) . '</ar:Cuit></ar:Auth>';
    }

    private function llamar(string $entorno, string $metodo, string $cuerpo): \DOMDocument
    {
        $sobre = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="' . self::NS . '">'
            . '<soapenv:Header/><soapenv:Body><ar:' . $metodo . '>' . $cuerpo . '</ar:' . $metodo . '></soapenv:Body></soapenv:Envelope>';
        $respuesta = $this->transporte->enviar(self::URLS[$entorno] ?? self::URLS['homologacion'], self::NS . $metodo, $sobre);
        $doc = ArcaXml::cargar($respuesta);
        if ($falla = ArcaXml::falla($doc)) {
            throw new ArcaSinRespuesta('ARCA: ' . $falla);
        }
        return $doc;
    }

    private function lanzarErrores(\DOMDocument $doc): void
    {
        $errores = ArcaXml::mensajes($doc, 'Errors', 'Err');
        if (!$errores) {
            return;
        }
        $texto = implode(' · ', array_map(fn ($e) => $e['mensaje'] . ' (' . $e['codigo'] . ')', $errores));
        // 600: token inválido o vencido; 601: CUIT no autorizado; 11002: punto de venta no habilitado.
        throw new ArcaRechazo('ARCA: ' . $texto);
    }

    private static function fecha(string $yyyymmdd): string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $yyyymmdd, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : $yyyymmdd;
    }
}
