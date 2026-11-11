<?php

namespace App\Service;

use App\Repository\EscaneoRepository;

/**
 * "Escanear con el celular" desde Caja, al estilo WhatsApp Web:
 *   1. La PC (logueada) abre una sesión y muestra un QR con un token de un solo uso.
 *   2. El celular abre ese link (sin loguearse), canjea el token por una clave
 *      propia y desde ahí manda cada código de barras que lee con la cámara.
 *   3. La PC pide cada segundo los códigos nuevos y los suma al carrito.
 * En la base se guarda solo el hash del token y de la clave.
 */
class EscaneoService
{
    /** Minutos que vale el QR para vincular el celular. */
    public const MINUTOS_VINCULO = 5;
    /** Minutos que dura la sesión una vez vinculado el celular (un turno largo). */
    public const MINUTOS_SESION = 12 * 60;
    /** Las sesiones (y sus códigos) se borran después de este tiempo. */
    public const HORAS_RETENCION = 48;
    public const MAXIMO_CODIGOS = 2000;
    public const LARGO_MAXIMO_CODIGO = 100;

    public function __construct(private EscaneoRepository $repo)
    {
    }

    public static function nuevoSecreto(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $secreto): string
    {
        return hash('sha256', $secreto);
    }

    /** Un secreto con la forma correcta (evita ir a la base con cualquier cosa). */
    public static function conForma(mixed $valor): bool
    {
        return is_string($valor) && preg_match('/^[A-Za-z0-9_-]{43}$/', $valor) === 1;
    }

    public static function estado(array $sesion): string
    {
        if ($sesion['cerrada_en'] !== null) {
            return 'cerrada';
        }
        if ($sesion['vinculada_en'] === null) {
            return (int) $sesion['vinculo_vigente'] === 1 ? 'esperando' : 'vencida';
        }
        return (int) $sesion['sesion_vigente'] === 1 ? 'vinculada' : 'vencida';
    }

    /** Descripción corta del celular a partir del User-Agent, sin guardarlo entero. */
    public static function dispositivo(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $sistema = match (true) {
            (bool) preg_match('/iPhone|iPad/', $ua) => 'iPhone',
            (bool) preg_match('/Android/', $ua) => 'Android',
            (bool) preg_match('/Windows/', $ua) => 'Windows',
            (bool) preg_match('/Mac OS/', $ua) => 'Mac',
            default => 'Celular',
        };
        $navegador = match (true) {
            (bool) preg_match('/EdgA?\//', $ua) => 'Edge',
            (bool) preg_match('/SamsungBrowser/', $ua) => 'Samsung Internet',
            (bool) preg_match('/CriOS|Chrome\//', $ua) => 'Chrome',
            (bool) preg_match('/FxiOS|Firefox\//', $ua) => 'Firefox',
            (bool) preg_match('/Safari\//', $ua) => 'Safari',
            default => 'navegador',
        };
        return "$sistema · $navegador";
    }

    /** Fotos de facturas por sesión (cada una se lee por separado desde la PC). */
    public const MAXIMO_FOTOS = 50;
    public const MODOS = ['productos', 'factura'];

    /**
     * @param string $modo 'productos' (Caja: el celular escanea códigos) o 'factura'
     *                     (Cargar factura: el celular saca la foto de la factura)
     * @return array{id: int, token: string, vinculo_minutos: int, modo: string}
     */
    public function crear(int $idNegocio, int $sucursal, int $idUsuario, string $modo = 'productos'): array
    {
        $this->repo->purgar(self::HORAS_RETENCION);
        $token = self::nuevoSecreto();
        $id = $this->repo->crear($idNegocio, $sucursal, $idUsuario, self::hash($token), self::MINUTOS_VINCULO, $modo);
        return ['id' => $id, 'token' => $token, 'vinculo_minutos' => self::MINUTOS_VINCULO, 'modo' => $modo];
    }

    public function agregarFoto(array $sesion, string $mime, string $datos): ?int
    {
        return $this->repo->agregarFoto((int) $sesion['id'], $mime, $datos, self::MAXIMO_FOTOS);
    }

    /** Estado de la sesión y códigos nuevos para la PC. null si no es del usuario. */
    public function estadoParaPc(int $id, int $idUsuario, int $desde): ?array
    {
        $sesion = $this->repo->delUsuario($id, $idUsuario);
        if (!$sesion) {
            return null;
        }
        return [
            'id' => (int) $sesion['id'],
            'estado' => self::estado($sesion),
            'modo' => $sesion['modo'],
            'dispositivo' => $sesion['dispositivo'],
            'codigos' => $this->repo->codigosDesde((int) $sesion['id'], max(0, $desde)),
            'fotos' => $sesion['modo'] === 'factura' ? $this->repo->fotos((int) $sesion['id']) : [],
        ];
    }

    public function cerrar(int $id, int $idUsuario): bool
    {
        $sesion = $this->repo->delUsuario($id, $idUsuario);
        if (!$sesion) {
            return false;
        }
        $this->repo->cerrar((int) $sesion['id']);
        return true;
    }

    /** Canjea el token por la clave del celular. null si el QR ya se usó o venció. */
    public function vincular(string $token, ?string $userAgent): ?string
    {
        $clave = self::nuevoSecreto();
        $ok = $this->repo->vincular(self::hash($token), self::hash($clave), self::dispositivo($userAgent), self::MINUTOS_SESION);
        return $ok ? $clave : null;
    }

    public function sesionDelCelular(string $clave): ?array
    {
        return $this->repo->porClave(self::hash($clave));
    }

    public function cantidadCodigos(int $idSesion): int
    {
        return $this->repo->cantidadCodigos($idSesion);
    }

    /**
     * Guarda el código para que lo levante la PC. Devuelve el producto que
     * le corresponde (para mostrarlo en el celular) o null si no existe; la
     * PC igual recibe el código y avisa si no lo encuentra.
     *
     * @return array{guardado: bool, producto: ?array}
     */
    public function agregarCodigo(array $sesion, string $codigo): array
    {
        if (!$this->repo->agregarCodigo((int) $sesion['id'], $codigo, self::MAXIMO_CODIGOS)) {
            return ['guardado' => false, 'producto' => null];
        }
        return [
            'guardado' => true,
            'producto' => $this->repo->producto((int) $sesion['id_negocio'], (int) $sesion['sucursal'], $codigo),
        ];
    }
}
