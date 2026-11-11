<?php

namespace App\Service\Facturas;

/**
 * Lee una factura de proveedor (foto o PDF) y devuelve sus datos. Es una
 * interfaz para poder cambiar de proveedor de IA sin tocar el resto
 * (hoy: Gemini, ver GeminiLectorFacturas y services.yaml).
 */
interface LectorFacturas
{
    public function configurado(): bool;

    /**
     * @return array{proveedor: ?string, cuit_proveedor: ?string, fecha: ?string, numero: ?string,
     *   precios_sin_iva: bool, items: array<int, array{codigo: ?string, descripcion: string, cantidad: float, precio_unitario: float}>}
     *
     * @throws \RuntimeException con un mensaje para el usuario si no se pudo leer
     */
    public function leer(string $contenido, string $mime): array;
}
