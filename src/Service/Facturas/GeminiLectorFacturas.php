<?php

namespace App\Service\Facturas;

use App\Service\Entorno;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lee facturas con la API de Gemini (Google). Variables de entorno:
 *   GEMINI_API_KEY  la clave de Google AI Studio (sin ella, la función queda apagada)
 *   GEMINI_MODEL    el modelo (por defecto gemini-3.8-flash)
 * Se pide la respuesta como JSON con un esquema fijo, así no hay que interpretar texto libre.
 */
class GeminiLectorFacturas implements LectorFacturas
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const MODELO_POR_DEFECTO = 'gemini-3.8-flash';

    private const INSTRUCCIONES = <<<'TXT'
Sos un asistente que lee facturas, remitos y tickets de proveedores de comercios de Argentina.
Leé el comprobante de la imagen y devolvé sus datos.

- proveedor: razón social o nombre de fantasía de quien emite el comprobante (no el cliente).
- cuit_proveedor: CUIT del emisor, solo dígitos, si figura.
- fecha: fecha del comprobante en formato AAAA-MM-DD, si figura.
- numero: número del comprobante tal como figura (ej. 0003-00012345), si figura.
- precios_sin_iva: true si los precios unitarios de los renglones NO incluyen IVA (típico de Factura A, con el IVA discriminado al pie). false si ya lo incluyen (Factura B o C, tickets, remitos con precio final).
- items: un elemento por cada renglón de producto, en el orden en que aparecen.
  - codigo: código del artículo o código de barras si el renglón lo trae; si no, null.
  - descripcion: la descripción tal como está escrita, sin agregar nada.
  - cantidad: la cantidad facturada en ese renglón.
  - precio_unitario: el precio de UNA unidad del renglón, ya restada la bonificación o descuento del renglón si lo hay. Si solo figura el importe total del renglón, dividilo por la cantidad.

No incluyas renglones que no son productos (subtotales, IVA, percepciones, fletes, totales).
Si no podés leer un número con seguridad, poné tu mejor lectura; no inventes renglones.
Si la imagen no es una factura, remito o ticket, devolvé items vacío.
TXT;

    public function __construct(private HttpClientInterface $http)
    {
    }

    public function configurado(): bool
    {
        return Entorno::get('GEMINI_API_KEY') !== '';
    }

    public function leer(string $contenido, string $mime): array
    {
        $esquemaTexto = ['type' => 'STRING', 'nullable' => true];
        $cuerpo = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($contenido)]],
                    ['text' => self::INSTRUCCIONES],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'proveedor' => $esquemaTexto,
                        'cuit_proveedor' => $esquemaTexto,
                        'fecha' => $esquemaTexto,
                        'numero' => $esquemaTexto,
                        'precios_sin_iva' => ['type' => 'BOOLEAN'],
                        'items' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'codigo' => $esquemaTexto,
                                    'descripcion' => ['type' => 'STRING'],
                                    'cantidad' => ['type' => 'NUMBER'],
                                    'precio_unitario' => ['type' => 'NUMBER'],
                                ],
                                'required' => ['descripcion', 'cantidad', 'precio_unitario'],
                            ],
                        ],
                    ],
                    'required' => ['items', 'precios_sin_iva'],
                ],
            ],
        ];

        $modelo = Entorno::get('GEMINI_MODEL', self::MODELO_POR_DEFECTO);
        try {
            $respuesta = $this->http->request('POST', sprintf(self::URL, rawurlencode($modelo)), [
                'headers' => ['x-goog-api-key' => Entorno::get('GEMINI_API_KEY'), 'Content-Type' => 'application/json'],
                'json' => $cuerpo,
                'timeout' => 90,
            ]);
            $estado = $respuesta->getStatusCode();
            $datos = $respuesta->toArray(false);
        } catch (\Throwable $th) {
            error_log('Gemini: ' . $th->getMessage());
            throw new \RuntimeException('No se pudo conectar con el servicio que lee las facturas. Probá de nuevo en unos minutos.');
        }

        if ($estado === 429) {
            throw new \RuntimeException('Se alcanzó el límite de lecturas del servicio de IA por ahora. Probá de nuevo en un rato.');
        }
        if ($estado >= 400) {
            error_log('Gemini HTTP ' . $estado . ': ' . substr(json_encode($datos), 0, 500));
            throw new \RuntimeException('El servicio que lee las facturas devolvió un error. Probá de nuevo en unos minutos.');
        }

        $texto = $datos['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $json = is_string($texto) ? json_decode($texto, true) : null;
        if (!is_array($json)) {
            error_log('Gemini: respuesta sin JSON: ' . substr(json_encode($datos), 0, 500));
            throw new \RuntimeException('No se pudo leer la factura. Probá con una foto más nítida y derecha.');
        }
        return $json;
    }
}
