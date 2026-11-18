<?php

namespace App\Service\Fotos;

/**
 * Convierte la foto que sube el negocio en dos WebP: una grande (lado mayor
 * LADO_GRANDE) para la galería y una miniatura cuadrada (LADO_MINI) para la lista.
 *
 * Siempre se vuelve a codificar la imagen: así se borran los datos EXIF (incluida
 * la ubicación GPS del celular) y no puede colarse un archivo disfrazado de foto.
 * Usa GD con soporte WebP (ver `php -r "print_r(gd_info());"` en el servidor).
 */
class ProcesadorFotos
{
    public const LADO_GRANDE = 1200;
    public const LADO_MINI = 400;
    public const CALIDAD = 80;
    public const BYTES_MAXIMOS = 10 * 1024 * 1024;
    /** Tope de píxeles de la imagen original (unos 25 megapíxeles) para no quedarse sin memoria. */
    public const PIXELES_MAXIMOS = 25_000_000;

    private const TIPOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public static function disponible(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagewebp')
            && (imagetypes() & IMG_WEBP) !== 0;
    }

    /**
     * @return array{grande: string, mini: string, ancho: int, alto: int}
     * @throws FotoInvalida con un mensaje para mostrar al usuario
     */
    public function procesar(string $ruta): array
    {
        if (!self::disponible()) {
            throw new \RuntimeException('GD sin soporte WebP: no se pueden procesar fotos.');
        }
        $tamanio = @filesize($ruta);
        if ($tamanio === false || $tamanio === 0) {
            throw new FotoInvalida('No llegó la foto. Probá de nuevo.');
        }
        if ($tamanio > self::BYTES_MAXIMOS) {
            throw new FotoInvalida('La foto pesa más de 10 MB. Probá con una más liviana.');
        }
        $info = @getimagesize($ruta);
        if ($info === false || !in_array($info[2], self::TIPOS, true)) {
            throw new FotoInvalida('Ese archivo no es una foto compatible. Usá JPG, PNG o WebP.');
        }
        [$ancho, $alto, $tipo] = $info;
        if ($ancho < 1 || $alto < 1 || $ancho * $alto > self::PIXELES_MAXIMOS) {
            throw new FotoInvalida('La foto es demasiado grande. Probá con una de menor resolución.');
        }

        $this->asegurarMemoria();
        $imagen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($ruta),
            IMAGETYPE_PNG => @imagecreatefrompng($ruta),
            IMAGETYPE_WEBP => @imagecreatefromwebp($ruta),
        };
        if (!$imagen) {
            throw new FotoInvalida('No se pudo leer la foto. Probá con otra.');
        }

        try {
            if ($tipo === IMAGETYPE_JPEG) {
                $imagen = $this->enderezar($imagen, $ruta);
            }
            $ancho = imagesx($imagen);
            $alto = imagesy($imagen);

            $escala = min(1, self::LADO_GRANDE / max($ancho, $alto));
            $anchoGrande = max(1, (int) round($ancho * $escala));
            $altoGrande = max(1, (int) round($alto * $escala));
            $grande = $this->lienzo($anchoGrande, $altoGrande);
            imagecopyresampled($grande, $imagen, 0, 0, 0, 0, $anchoGrande, $altoGrande, $ancho, $alto);

            // Miniatura: recorte cuadrado del centro.
            $lado = min($ancho, $alto);
            $ladoMini = min(self::LADO_MINI, $lado);
            $mini = $this->lienzo($ladoMini, $ladoMini);
            imagecopyresampled($mini, $imagen, 0, 0, (int) (($ancho - $lado) / 2), (int) (($alto - $lado) / 2), $ladoMini, $ladoMini, $lado, $lado);

            $resultado = [
                'grande' => $this->webp($grande),
                'mini' => $this->webp($mini),
                'ancho' => $anchoGrande,
                'alto' => $altoGrande,
            ];
            imagedestroy($grande);
            imagedestroy($mini);
            return $resultado;
        } finally {
            imagedestroy($imagen);
        }
    }

    private function lienzo(int $ancho, int $alto): \GdImage
    {
        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
        return $lienzo;
    }

    private function webp(\GdImage $imagen): string
    {
        ob_start();
        imagewebp($imagen, null, self::CALIDAD);
        return (string) ob_get_clean();
    }

    /** Las fotos del celular suelen venir "acostadas" con la rotación en el EXIF. */
    private function enderezar(\GdImage $imagen, string $ruta): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $imagen;
        }
        $exif = @exif_read_data($ruta);
        $orientacion = (int) ($exif['Orientation'] ?? 1);
        $grados = [3 => 180, 6 => -90, 8 => 90][$orientacion] ?? 0;
        if ($grados === 0) {
            return $imagen;
        }
        $rotada = imagerotate($imagen, $grados, 0);
        if (!$rotada) {
            return $imagen;
        }
        imagedestroy($imagen);
        return $rotada;
    }

    /** Una foto de 25 MP ocupa unos 100 MB en memoria al abrirla. */
    private function asegurarMemoria(): void
    {
        $actual = (string) ini_get('memory_limit');
        if ($actual === '-1') {
            return;
        }
        $bytes = (int) $actual;
        $unidad = strtolower(substr($actual, -1));
        $bytes *= ['g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024][$unidad] ?? 1;
        if ($bytes < 384 * 1024 * 1024) {
            @ini_set('memory_limit', '384M');
        }
    }
}
