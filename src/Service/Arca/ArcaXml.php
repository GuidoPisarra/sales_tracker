<?php

namespace App\Service\Arca;

/** Lectura simple de las respuestas SOAP de ARCA (sin depender de ext-soap). */
final class ArcaXml
{
    public static function cargar(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$ok) {
            throw new ArcaSinRespuesta('ARCA respondió algo que no es XML.');
        }
        return $doc;
    }

    /** Texto del primer elemento con ese nombre (sin importar el prefijo), o null. */
    public static function texto(\DOMNode $raiz, string $nombre): ?string
    {
        $nodo = self::nodo($raiz, $nombre);
        return $nodo ? trim($nodo->textContent) : null;
    }

    public static function nodo(\DOMNode $raiz, string $nombre): ?\DOMElement
    {
        $lista = self::nodos($raiz, $nombre);
        return $lista[0] ?? null;
    }

    /** @return \DOMElement[] */
    public static function nodos(\DOMNode $raiz, string $nombre): array
    {
        $doc = $raiz instanceof \DOMDocument ? $raiz : $raiz->ownerDocument;
        $xp = new \DOMXPath($doc);
        $resultado = [];
        foreach ($xp->query('.//*[local-name()="' . $nombre . '"]', $raiz) ?: [] as $n) {
            $resultado[] = $n;
        }
        return $resultado;
    }

    /** Mensaje de un SOAP Fault, o null si no hay. */
    public static function falla(\DOMDocument $doc): ?string
    {
        if (!self::nodo($doc, 'Fault')) {
            return null;
        }
        return self::texto($doc, 'faultstring') ?? 'Error de ARCA sin detalle.';
    }

    /**
     * Errores (Errors/Err) u observaciones (Observaciones/Obs) dentro de un nodo.
     *
     * @return array<int, array{codigo: int, mensaje: string}>
     */
    public static function mensajes(\DOMNode $raiz, string $contenedor, string $item): array
    {
        $resultado = [];
        foreach (self::nodos($raiz, $contenedor) as $c) {
            foreach (self::nodos($c, $item) as $e) {
                $resultado[] = ['codigo' => (int) self::texto($e, 'Code'), 'mensaje' => (string) self::texto($e, 'Msg')];
            }
        }
        return $resultado;
    }

    public static function esc(string $texto): string
    {
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
