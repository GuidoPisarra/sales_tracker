<?php

namespace App\Service\Fotos;

use App\Service\Entorno;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fotos en Cloudflare R2 (API compatible con S3, sin costo por tráfico de descarga).
 * Firma los pedidos con AWS Signature V4, sin SDK. Variables:
 *   R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET
 *   FOTOS_URL_BASE = dominio público del bucket (ej. https://fotos.kontia.com.ar)
 */
class AlmacenR2 implements AlmacenFotos
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    public function guardar(string $clave, string $contenido): void
    {
        $respuesta = $this->pedir('PUT', $clave, $contenido, [
            'content-type' => 'image/webp',
            'cache-control' => 'public, max-age=31536000, immutable',
        ]);
        if ($respuesta >= 300) {
            throw new \RuntimeException('R2 respondió ' . $respuesta . ' al guardar ' . $clave);
        }
    }

    public function borrar(string $clave): void
    {
        $respuesta = $this->pedir('DELETE', $clave, '');
        if ($respuesta >= 300 && $respuesta !== 404) {
            throw new \RuntimeException('R2 respondió ' . $respuesta . ' al borrar ' . $clave);
        }
    }

    public function url(string $clave): string
    {
        return rtrim(Entorno::get('FOTOS_URL_BASE'), '/') . '/' . $clave;
    }

    /** @param array<string,string> $extra encabezados en minúscula */
    private function pedir(string $metodo, string $clave, string $cuerpo, array $extra = []): int
    {
        $cuenta = Entorno::get('R2_ACCOUNT_ID');
        $bucket = Entorno::get('R2_BUCKET');
        $idClave = Entorno::get('R2_ACCESS_KEY_ID');
        $secreto = Entorno::get('R2_SECRET_ACCESS_KEY');
        if ($cuenta === '' || $bucket === '' || $idClave === '' || $secreto === '') {
            throw new \RuntimeException('Faltan variables R2_* para guardar fotos en R2.');
        }
        $host = $cuenta . '.r2.cloudflarestorage.com';
        $ruta = '/' . rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $clave)));
        $encabezados = self::firmar($metodo, $host, $ruta, $cuerpo, $extra, $idClave, $secreto, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return $this->http->request($metodo, 'https://' . $host . $ruta, [
            'headers' => $encabezados,
            'body' => $cuerpo,
            'timeout' => 20,
        ])->getStatusCode();
    }

    /**
     * AWS Signature V4 para S3 (región "auto" en R2). Pública y estática para poder testearla.
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    public static function firmar(string $metodo, string $host, string $ruta, string $cuerpo, array $extra, string $idClave, string $secreto, \DateTimeImmutable $ahora, string $region = 'auto'): array
    {
        $fecha = $ahora->format('Ymd\THis\Z');
        $dia = $ahora->format('Ymd');
        $hashCuerpo = hash('sha256', $cuerpo);
        $encabezados = array_merge($extra, [
            'host' => $host,
            'x-amz-content-sha256' => $hashCuerpo,
            'x-amz-date' => $fecha,
        ]);
        ksort($encabezados);
        $canonicos = '';
        foreach ($encabezados as $k => $v) {
            $canonicos .= $k . ':' . trim($v) . "\n";
        }
        $firmados = implode(';', array_keys($encabezados));
        $pedido = implode("\n", [$metodo, $ruta, '', $canonicos, $firmados, $hashCuerpo]);
        $alcance = "$dia/$region/s3/aws4_request";
        $aFirmar = implode("\n", ['AWS4-HMAC-SHA256', $fecha, $alcance, hash('sha256', $pedido)]);

        $k = hash_hmac('sha256', $dia, 'AWS4' . $secreto, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $firma = hash_hmac('sha256', $aFirmar, $k);

        unset($encabezados['host']);
        $encabezados['authorization'] = "AWS4-HMAC-SHA256 Credential=$idClave/$alcance, SignedHeaders=$firmados, Signature=$firma";
        return $encabezados;
    }
}
