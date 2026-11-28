<?php

namespace App\Service;

use App\Repository\NegocioConfigRepository;
use App\Service\Fotos\FotosService;

/**
 * "Consultá el precio": el local pega un cartel con un QR fijo que abre
 * <web>/precio/<slug>. Desde esa página el cliente escanea la etiqueta de un
 * producto con la cámara y ve nombre, precio, fotos y los otros talles o colores
 * (si hay o no, nunca cuántos). El link es el mismo del catálogo online
 * (negocio_config.catalogo_slug), pero se prende y apaga por separado.
 */
class ConsultaPrecioService
{
    public function __construct(private NegocioConfigRepository $repo, private CatalogoService $catalogo, private FotosService $fotos)
    {
    }

    /** @param int[] $sucursales */
    public function config(int $idNegocio, array $sucursales): array
    {
        $c = $this->repo->config($idNegocio);
        $cat = $this->catalogo->config($idNegocio, $sucursales);
        return [
            'activo' => (int) $c['precios_activo'] === 1,
            'slug' => $c['catalogo_slug'],
            'sucursal' => $this->sucursal($c, $cat, $sucursales),
            'nombre_sugerido' => $cat['nombre_sugerido'],
            'titulo' => $c['catalogo_titulo'] ?: $cat['nombre_sugerido'],
            'catalogo_activo' => $cat['activo'],
        ];
    }

    /**
     * @param int[] $sucursales
     * @return array|string la configuración guardada, o un mensaje de error
     */
    public function guardar(int $idNegocio, array $d, array $sucursales)
    {
        $actual = $this->repo->config($idNegocio);
        $activo = array_key_exists('activo', $d) ? !empty($d['activo']) : (int) $actual['precios_activo'] === 1;
        $sucursal = array_key_exists('sucursal', $d) ? (int) $d['sucursal'] : null;
        if ($sucursal !== null && !in_array($sucursal, $sucursales, true)) {
            return 'Esa sucursal no es de tu negocio.';
        }
        // El link se guarda con las mismas reglas que el del catálogo (y lo comparte).
        if (array_key_exists('slug', $d)) {
            $slug = CatalogoService::slugDesde((string) $d['slug']);
            if ($slug === '' && (int) $actual['catalogo_activo'] === 1) {
                return 'El catálogo online usa este link: no se puede borrar mientras esté prendido.';
            }
            $r = $this->catalogo->guardar($idNegocio, ['slug' => $slug], $sucursales);
            if (is_string($r)) {
                return $r;
            }
        }
        $slugFinal = $this->repo->config($idNegocio)['catalogo_slug'];
        if ($activo && empty($slugFinal)) {
            return 'Elegí un link para la consulta de precios.';
        }
        $campos = ['precios_activo' => $activo ? 1 : 0];
        if ($sucursal !== null) {
            $campos['precios_sucursal'] = $sucursal;
        }
        $this->repo->guardarConfig($idNegocio, $campos);
        return $this->config($idNegocio, $sucursales);
    }

    /** Datos del cartel público, o null si no existe o está apagado. */
    public function publico(string $slug): ?array
    {
        $n = $this->negocio($slug);
        if (!$n) {
            return null;
        }
        [$idNegocio, $c, $sucursal] = $n;
        return [
            'slug' => strtolower($slug),
            'titulo' => $c['catalogo_titulo'] ?: ($this->repo->nombreNegocio($idNegocio, $sucursal) ?? 'Consultá el precio'),
            'catalogo' => (int) $c['catalogo_activo'] === 1 && !empty($c['catalogo_whatsapp']),
        ];
    }

    /**
     * Producto por el código de la etiqueta. null si el link no existe;
     * ['no_encontrado' => true] si el código no es de un producto del local.
     */
    public function producto(string $slug, string $codigo): ?array
    {
        $n = $this->negocio($slug);
        if (!$n) {
            return null;
        }
        [$idNegocio, , $sucursal] = $n;
        $codigo = trim($codigo);
        // Algunas etiquetas guardan un link en el QR: se usa la última parte.
        if (preg_match('#^https?://#i', $codigo)) {
            $partes = explode('/', rtrim((string) parse_url($codigo, PHP_URL_PATH), '/'));
            $codigo = urldecode((string) end($partes));
        }
        if ($codigo === '' || mb_strlen($codigo) > 100) {
            return ['no_encontrado' => true];
        }
        $p = $this->repo->productoPorCodigo($idNegocio, $sucursal, $codigo);
        if (!$p || (float) $p['sale_price'] <= 0) {
            return ['no_encontrado' => true];
        }
        $otros = $this->repo->mismaDescripcion($idNegocio, $sucursal, (string) $p['description'], (int) $p['id']);
        try {
            $fotos = $this->fotos->paraCatalogo($idNegocio, [(int) $p['id']])[(int) $p['id']] ?? [];
        } catch (\Throwable $th) {
            error_log('Consulta de precio sin fotos: ' . $th->getMessage());
            $fotos = [];
        }
        return [
            'nombre' => $p['description'],
            'codigo' => $p['code'],
            'talle' => $p['size'],
            'precio' => (float) $p['sale_price'],
            'disponible' => (int) $p['quantity'] > 0,
            'fotos' => $fotos,
            'opciones' => array_map(fn ($o) => [
                'talle' => $o['size'],
                'precio' => (float) $o['sale_price'],
                'disponible' => (int) $o['quantity'] > 0,
            ], $otros),
        ];
    }

    /** @return array{0:int,1:array,2:int}|null */
    private function negocio(string $slug): ?array
    {
        $slug = strtolower($slug);
        if (!preg_match(CatalogoService::SLUG, $slug)) {
            return null;
        }
        $idNegocio = $this->repo->negocioDelSlug($slug);
        if ($idNegocio === null) {
            return null;
        }
        $c = $this->repo->config($idNegocio);
        if ((int) $c['precios_activo'] !== 1) {
            return null;
        }
        $sucursal = $c['precios_sucursal'] !== null ? (int) $c['precios_sucursal'] : (int) $c['catalogo_sucursal'];
        return [$idNegocio, $c, $sucursal];
    }

    /** @param int[] $sucursales */
    private function sucursal(array $c, array $cat, array $sucursales): int
    {
        $s = $c['precios_sucursal'] !== null ? (int) $c['precios_sucursal'] : (int) $cat['sucursal'];
        return $sucursales === [] || in_array($s, $sucursales, true) ? $s : min($sucursales);
    }
}
