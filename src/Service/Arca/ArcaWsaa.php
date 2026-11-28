<?php

namespace App\Service\Arca;

/**
 * WSAA de ARCA: con el certificado del negocio pide un ticket de acceso (token + sign)
 * para usar el web service de factura electrónica. El ticket dura 12 horas.
 */
class ArcaWsaa
{
    public const URLS = [
        'homologacion' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
        'produccion' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
    ];

    public function __construct(private ArcaTransporte $transporte)
    {
    }

    /** Pedido de acceso (TRA) firmado en CMS, en base64. */
    public static function firmarPedido(string $certificado, string $clave, string $servicio = 'wsfe', ?int $ahora = null): string
    {
        $ahora ??= time();
        $tz = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $desde = (new \DateTime('@' . ($ahora - 600)))->setTimezone($tz)->format('c');
        $hasta = (new \DateTime('@' . ($ahora + 600)))->setTimezone($tz)->format('c');
        $tra = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<loginTicketRequest version="1.0"><header>'
            . '<uniqueId>' . $ahora . '</uniqueId>'
            . '<generationTime>' . $desde . '</generationTime>'
            . '<expirationTime>' . $hasta . '</expirationTime>'
            . '</header><service>' . $servicio . '</service></loginTicketRequest>';

        $dir = sys_get_temp_dir();
        $entrada = tempnam($dir, 'tra');
        $salida = tempnam($dir, 'cms');
        try {
            file_put_contents($entrada, $tra);
            if (!openssl_pkcs7_sign($entrada, $salida, $certificado, $clave, [], PKCS7_BINARY)) {
                throw new ArcaRechazo('No se pudo firmar el pedido de acceso con el certificado.');
            }
            $smime = (string) file_get_contents($salida);
        } finally {
            @unlink($entrada);
            @unlink($salida);
        }
        // Es un mensaje S/MIME: los encabezados y, después de una línea vacía, el CMS en base64.
        $partes = preg_split("/\r?\n\r?\n/", $smime, 2);
        return preg_replace('/\s+/', '', $partes[1] ?? '');
    }

    /** @return array{token: string, sign: string, vence: string} */
    public function pedirAcceso(string $entorno, string $certificado, string $clave, string $servicio = 'wsfe'): array
    {
        $cms = self::firmarPedido($certificado, $clave, $servicio);
        $sobre = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov">'
            . '<soapenv:Header/><soapenv:Body><wsaa:loginCms><wsaa:in0>' . $cms . '</wsaa:in0></wsaa:loginCms></soapenv:Body></soapenv:Envelope>';
        $respuesta = $this->transporte->enviar(self::URLS[$entorno] ?? self::URLS['homologacion'], '', $sobre);

        $xml = ArcaXml::cargar($respuesta);
        if ($falla = ArcaXml::falla($xml)) {
            throw new ArcaRechazo(self::explicarFalla($falla));
        }
        $retorno = ArcaXml::texto($xml, 'loginCmsReturn');
        if ($retorno === null) {
            throw new ArcaSinRespuesta('ARCA respondió algo inesperado al pedir acceso.');
        }
        $ticket = ArcaXml::cargar($retorno);
        $token = ArcaXml::texto($ticket, 'token');
        $sign = ArcaXml::texto($ticket, 'sign');
        $vence = ArcaXml::texto($ticket, 'expirationTime');
        if (!$token || !$sign || !$vence) {
            throw new ArcaSinRespuesta('ARCA no devolvió el ticket de acceso.');
        }
        return ['token' => $token, 'sign' => $sign, 'vence' => date('Y-m-d H:i:s', strtotime($vence))];
    }

    private static function explicarFalla(string $falla): string
    {
        $f = mb_strtolower($falla);
        if (str_contains($f, 'ya posee un ta valido')) {
            return 'ARCA ya entregó un permiso de acceso hace poco y todavía no venció. Probá de nuevo en unos minutos.';
        }
        if (str_contains($f, 'no autorizado') || str_contains($f, 'notauthorized') || str_contains($f, 'computador no autorizado')) {
            return 'El certificado no está autorizado para factura electrónica. En ARCA, entrá a "Administrador de relaciones de clave fiscal" y agregá el servicio "Facturación electrónica" (wsfe) para el certificado.';
        }
        if (str_contains($f, 'expirado') || str_contains($f, 'expired')) {
            return 'El certificado de ARCA venció. Generá uno nuevo desde Facturación electrónica.';
        }
        if (str_contains($f, 'firma') || str_contains($f, 'cms') || str_contains($f, 'certificado')) {
            return 'ARCA no aceptó el certificado (' . $falla . '). Revisá que sea el del entorno correcto: pruebas o producción.';
        }
        return 'ARCA rechazó el acceso: ' . $falla;
    }
}
