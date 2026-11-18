<?php

namespace App\Service\Fotos;

/**
 * Dónde viven los archivos de las fotos. La base solo guarda la clave
 * (ej. n/42/9f3c1a7b.webp): cambiar de almacén es copiar los archivos y cambiar
 * FOTOS_ALMACEN / FOTOS_URL_BASE, sin tocar la base ni el front.
 */
interface AlmacenFotos
{
    public function guardar(string $clave, string $contenido): void;

    public function borrar(string $clave): void;

    /** URL pública del archivo. */
    public function url(string $clave): string;
}
