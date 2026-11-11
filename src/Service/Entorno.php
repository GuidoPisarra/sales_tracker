<?php

namespace App\Service;

/**
 * Lectura de variables de entorno. Igual que BaseRepository y ChatIaService, se
 * lee de $_ENV en tiempo de ejecución (sin %env()%), así una variable que falta
 * no rompe la compilación del contenedor en producción.
 */
final class Entorno
{
    public static function get(string $clave, string $porDefecto = ''): string
    {
        $valor = $_ENV[$clave] ?? $_SERVER[$clave] ?? getenv($clave);
        return ($valor === false || $valor === null || $valor === '') ? $porDefecto : (string) $valor;
    }

    public static function int(string $clave, int $porDefecto): int
    {
        $valor = self::get($clave);
        return $valor === '' ? $porDefecto : (int) $valor;
    }

    /** @return string[] */
    public static function lista(string $clave): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::get($clave)))));
    }
}
