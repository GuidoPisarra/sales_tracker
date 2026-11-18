<?php

namespace App\Service\Fotos;

use App\Service\Entorno;

/**
 * Fotos en una carpeta del servidor (FOTOS_DIR), que Apache sirve como archivos
 * estáticos en FOTOS_URL_BASE (ver docs/SAAS.md, sección de fotos).
 */
class AlmacenLocal implements AlmacenFotos
{
    public function __construct(private string $projectDir)
    {
    }

    public function directorio(): string
    {
        return rtrim(Entorno::get('FOTOS_DIR', $this->projectDir . '/var/fotos'), '/');
    }

    public function guardar(string $clave, string $contenido): void
    {
        $ruta = $this->ruta($clave);
        $carpeta = dirname($ruta);
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
            throw new \RuntimeException('No se pudo crear la carpeta de fotos ' . $carpeta);
        }
        // Se escribe a un temporal y se renombra: nunca queda un archivo a medias.
        $temporal = $ruta . '.tmp';
        if (@file_put_contents($temporal, $contenido) === false || !@rename($temporal, $ruta)) {
            @unlink($temporal);
            throw new \RuntimeException('No se pudo guardar la foto en ' . $carpeta);
        }
        @chmod($ruta, 0664);
    }

    public function borrar(string $clave): void
    {
        $ruta = $this->ruta($clave);
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }

    public function url(string $clave): string
    {
        return rtrim(Entorno::get('FOTOS_URL_BASE', '/fotos'), '/') . '/' . $clave;
    }

    private function ruta(string $clave): string
    {
        if (!preg_match('#^[a-z0-9/_-]+\.webp$#', $clave) || str_contains($clave, '..')) {
            throw new \InvalidArgumentException('Clave de foto inválida.');
        }
        return $this->directorio() . '/' . $clave;
    }
}
