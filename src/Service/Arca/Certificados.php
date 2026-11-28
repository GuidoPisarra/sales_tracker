<?php

namespace App\Service\Arca;

use App\Service\Entorno;

/**
 * Clave privada, pedido de certificado (CSR) y certificado de ARCA de cada negocio.
 *
 * El flujo para el comerciante: Kontia genera la clave y el pedido; el comerciante
 * sube el pedido en ARCA ("Administración de certificados digitales"), descarga el
 * certificado y lo pega en Kontia. La clave privada nunca sale del servidor y se
 * guarda cifrada con ARCA_CLAVE (o APP_SECRET si no está).
 */
class Certificados
{
    /** @return array{clave: string, pedido: string} clave privada y CSR en PEM. */
    public static function generarClaveYPedido(string $cuit, string $razonSocial): array
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($clave === false) {
            throw new \RuntimeException('No se pudo generar la clave privada.');
        }
        $nombre = trim(preg_replace('/[^\p{L}\p{N} .,&-]/u', '', $razonSocial)) ?: 'Kontia';
        $dn = [
            'countryName' => 'AR',
            'organizationName' => mb_substr($nombre, 0, 60),
            'commonName' => 'kontia',
            'serialNumber' => 'CUIT ' . $cuit,
        ];
        $csr = openssl_csr_new($dn, $clave, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new \RuntimeException('No se pudo generar el pedido de certificado.');
        }
        openssl_pkey_export($clave, $clavePem);
        openssl_csr_export($csr, $csrPem);
        return ['clave' => $clavePem, 'pedido' => $csrPem];
    }

    /**
     * Valida un certificado pegado por el comerciante contra la clave guardada.
     *
     * @return array{certificado: string, vence: string, cuit: ?string}
     */
    public static function validarCertificado(string $pem, string $clavePem): array
    {
        $pem = trim($pem);
        if (!str_contains($pem, 'BEGIN CERTIFICATE')) {
            // A veces lo pegan sin las líneas de inicio y fin.
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END CERTIFICATE-----";
        }
        $cert = @openssl_x509_read($pem);
        if ($cert === false) {
            throw new ArcaRechazo('Eso no parece un certificado. Copiá el contenido completo del archivo .crt que te dio ARCA.');
        }
        if (!openssl_x509_check_private_key($cert, $clavePem)) {
            throw new ArcaRechazo('Ese certificado no corresponde al último pedido generado en Kontia. Generá un pedido nuevo y subilo a ARCA.');
        }
        $datos = openssl_x509_parse($cert) ?: [];
        $vence = isset($datos['validTo_time_t']) ? date('Y-m-d H:i:s', (int) $datos['validTo_time_t']) : null;
        if ($vence === null) {
            throw new ArcaRechazo('No se pudo leer la fecha de vencimiento del certificado.');
        }
        if (strtotime($vence) < time()) {
            throw new ArcaRechazo('Ese certificado ya venció. Generá uno nuevo en ARCA.');
        }
        $cuit = null;
        $serial = (string) ($datos['subject']['serialNumber'] ?? '');
        if (preg_match('/(\d{11})/', $serial, $m)) {
            $cuit = $m[1];
        }
        openssl_x509_export($cert, $limpio);
        return ['certificado' => $limpio, 'vence' => $vence, 'cuit' => $cuit];
    }

    public static function cifrar(string $texto): string
    {
        $iv = random_bytes(16);
        $cifrado = openssl_encrypt($texto, 'aes-256-cbc', self::llave(), OPENSSL_RAW_DATA, $iv);
        if ($cifrado === false) {
            throw new \RuntimeException('No se pudo cifrar la clave.');
        }
        return 'v1:' . base64_encode($iv . $cifrado);
    }

    public static function descifrar(string $guardado): string
    {
        if (!str_starts_with($guardado, 'v1:')) {
            throw new \RuntimeException('Clave guardada con un formato desconocido.');
        }
        $crudo = base64_decode(substr($guardado, 3), true) ?: '';
        $texto = openssl_decrypt(substr($crudo, 16), 'aes-256-cbc', self::llave(), OPENSSL_RAW_DATA, substr($crudo, 0, 16));
        if ($texto === false) {
            throw new ArcaRechazo('No se pudo leer la clave guardada (¿cambió ARCA_CLAVE o APP_SECRET en el servidor?). Generá un pedido de certificado nuevo.');
        }
        return $texto;
    }

    private static function llave(): string
    {
        $base = Entorno::get('ARCA_CLAVE', Entorno::get('APP_SECRET'));
        if ($base === '') {
            throw new \RuntimeException('Falta ARCA_CLAVE (o APP_SECRET) en el .env del servidor.');
        }
        return hash('sha256', 'kontia-arca|' . $base, true);
    }
}
