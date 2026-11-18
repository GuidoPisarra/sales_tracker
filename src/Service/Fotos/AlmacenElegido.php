<?php

namespace App\Service\Fotos;

use App\Service\Entorno;

/**
 * El almacén que se usa según FOTOS_ALMACEN: "local" (por defecto, carpeta del VPS)
 * o "r2" (Cloudflare R2). Se decide en cada llamada para no depender de la
 * compilación del contenedor.
 */
class AlmacenElegido implements AlmacenFotos
{
    public function __construct(private AlmacenLocal $local, private AlmacenR2 $r2)
    {
    }

    private function actual(): AlmacenFotos
    {
        return strtolower(Entorno::get('FOTOS_ALMACEN', 'local')) === 'r2' ? $this->r2 : $this->local;
    }

    public function guardar(string $clave, string $contenido): void
    {
        $this->actual()->guardar($clave, $contenido);
    }

    public function borrar(string $clave): void
    {
        $this->actual()->borrar($clave);
    }

    public function url(string $clave): string
    {
        return $this->actual()->url($clave);
    }
}
