<?php

namespace App\Service;

use App\Repository\NegocioConfigRepository;

/**
 * Catálogo online compartible: el administrador lo activa y elige un link
 * (/c/<slug>). Cualquiera con el link ve los productos con su precio (nunca el
 * costo) y arma un pedido que se manda por WhatsApp al número del negocio.
 * No descuenta stock ni crea ventas: el pedido llega como mensaje.
 */
class CatalogoService
{
    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/';
    private const RESERVADOS = ['admin', 'api', 'app', 'kontia', 'login', 'registro', 'soporte', 'ayuda'];

    public function __construct(private NegocioConfigRepository $repo)
    {
    }

    /** Configuración del catálogo para la pantalla del administrador. */
    public function config(int $idNegocio, array $sucursales = []): array
    {
        $c = $this->repo->config($idNegocio);
        $c['catalogo_sucursal'] = self::sucursalEfectiva((int) $c['catalogo_sucursal'], $sucursales);
        return [
            'activo' => (int) $c['catalogo_activo'] === 1,
            'slug' => $c['catalogo_slug'],
            'titulo' => $c['catalogo_titulo'],
            'mensaje' => $c['catalogo_mensaje'],
            'whatsapp' => $c['catalogo_whatsapp'],
            'sucursal' => (int) $c['catalogo_sucursal'],
            'mostrar_stock' => (int) $c['catalogo_mostrar_stock'] === 1,
            'sin_stock' => (int) $c['catalogo_sin_stock'] === 1,
            'nombre_sugerido' => $this->repo->nombreNegocio($idNegocio, 1),
        ];
    }

    public static function slugDesde(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $t = preg_replace('/[^a-z0-9]+/', '-', $t);
        return substr(trim((string) $t, '-'), 0, 40);
    }

    /**
     * Si la sucursal guardada no es del negocio (por ejemplo el 1 por defecto en un
     * negocio cuyas sucursales tienen otro número), usa la primera del negocio.
     *
     * @param int[] $sucursales sucursales del negocio
     */
    private static function sucursalEfectiva(int $guardada, array $sucursales): int
    {
        if ($sucursales === [] || in_array($guardada, $sucursales, true)) {
            return $guardada;
        }
        return min($sucursales);
    }

    /**
     * Guarda la configuración. $sucursales son las sucursales del negocio.
     * @param int[] $sucursales
     * @return array|string la configuración guardada, o un mensaje de error
     */
    public function guardar(int $idNegocio, array $d, array $sucursales)
    {
        $actual = $this->repo->config($idNegocio);
        $slug = array_key_exists('slug', $d) ? self::slugDesde((string) $d['slug']) : (string) $actual['catalogo_slug'];
        $activo = array_key_exists('activo', $d) ? !empty($d['activo']) : (int) $actual['catalogo_activo'] === 1;
        $whatsapp = array_key_exists('whatsapp', $d) ? preg_replace('/\D+/', '', (string) $d['whatsapp']) : (string) $actual['catalogo_whatsapp'];
        $sucursal = array_key_exists('sucursal', $d) ? (int) $d['sucursal'] : self::sucursalEfectiva((int) $actual['catalogo_sucursal'], $sucursales);

        if ($slug !== '' && (!preg_match(self::SLUG, $slug) || in_array($slug, self::RESERVADOS, true))) {
            return 'El link tiene que tener entre 3 y 40 letras, números o guiones.';
        }
        if ($activo && $slug === '') {
            return 'Elegí un link para el catálogo.';
        }
        if ($whatsapp !== '' && (strlen($whatsapp) < 8 || strlen($whatsapp) > 15)) {
            return 'El número de WhatsApp no parece válido. Escribilo con código de área, por ejemplo 5491122334455.';
        }
        if ($activo && $whatsapp === '') {
            return 'Falta el número de WhatsApp donde te llegan los pedidos.';
        }
        if (!in_array($sucursal, $sucursales, true)) {
            return 'Esa sucursal no es de tu negocio.';
        }
        $duenio = $slug !== '' ? $this->repo->negocioDelSlug($slug) : null;
        if ($duenio !== null && $duenio !== $idNegocio) {
            return 'Ese link ya lo usa otro negocio. Probá con otro.';
        }

        $texto = fn ($k, $max, $col) => array_key_exists($k, $d)
            ? (trim((string) $d[$k]) === '' ? null : mb_substr(trim((string) $d[$k]), 0, $max))
            : $actual[$col];
        $this->repo->guardarConfig($idNegocio, [
            'catalogo_activo' => $activo ? 1 : 0,
            'catalogo_slug' => $slug === '' ? null : $slug,
            'catalogo_titulo' => $texto('titulo', 120, 'catalogo_titulo'),
            'catalogo_mensaje' => $texto('mensaje', 300, 'catalogo_mensaje'),
            'catalogo_whatsapp' => $whatsapp === '' ? null : $whatsapp,
            'catalogo_sucursal' => $sucursal,
            'catalogo_mostrar_stock' => (array_key_exists('mostrar_stock', $d) ? !empty($d['mostrar_stock']) : (int) $actual['catalogo_mostrar_stock'] === 1) ? 1 : 0,
            'catalogo_sin_stock' => (array_key_exists('sin_stock', $d) ? !empty($d['sin_stock']) : (int) $actual['catalogo_sin_stock'] === 1) ? 1 : 0,
        ]);
        return $this->config($idNegocio, $sucursales);
    }

    /** Catálogo público por slug, o null si no existe o está apagado. */
    public function publico(string $slug): ?array
    {
        $slug = strtolower($slug);
        if (!preg_match(self::SLUG, $slug)) {
            return null;
        }
        $idNegocio = $this->repo->negocioDelSlug($slug);
        if ($idNegocio === null) {
            return null;
        }
        $c = $this->repo->config($idNegocio);
        if ((int) $c['catalogo_activo'] !== 1 || empty($c['catalogo_whatsapp'])) {
            return null;
        }
        $mostrarStock = (int) $c['catalogo_mostrar_stock'] === 1;
        $sucursal = (int) $c['catalogo_sucursal'];
        $productos = array_map(function ($p) use ($mostrarStock) {
            $item = [
                'id' => (int) $p['id'],
                'nombre' => $p['description'],
                'codigo' => $p['code'],
                'talle' => $p['size'],
                'precio' => (float) $p['sale_price'],
                'disponible' => (int) $p['quantity'] > 0,
            ];
            if ($mostrarStock) {
                $item['stock'] = max(0, (int) $p['quantity']);
            }
            return $item;
        }, $this->repo->productosCatalogo($idNegocio, $sucursal, (int) $c['catalogo_sin_stock'] !== 1));

        return [
            'slug' => $slug,
            'titulo' => $c['catalogo_titulo'] ?: ($this->repo->nombreNegocio($idNegocio, $sucursal) ?? 'Catálogo'),
            'mensaje' => $c['catalogo_mensaje'],
            'whatsapp' => $c['catalogo_whatsapp'],
            'mostrar_stock' => $mostrarStock,
            'productos' => $productos,
        ];
    }
}
