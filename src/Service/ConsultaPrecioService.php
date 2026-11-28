<?php

namespace App\Service;

use App\Repository\CarritoClienteRepository;
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
    /** Lo que lleva el QR que muestra la clienta en la caja (seguido del token). */
    public const PREFIJO_QR = 'KONTIA-CARRITO-';

    public function __construct(private NegocioConfigRepository $repo, private CatalogoService $catalogo, private FotosService $fotos, private CarritoClienteRepository $carritos)
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
            'id' => (int) $p['id'],
            'nombre' => $p['description'],
            'codigo' => $p['code'],
            'talle' => $p['size'],
            'precio' => (float) $p['sale_price'],
            'disponible' => (int) $p['quantity'] > 0,
            'fotos' => $fotos,
            'opciones' => array_map(fn ($o) => [
                'id' => (int) $o['id'],
                'talle' => $o['size'],
                'precio' => (float) $o['sale_price'],
                'disponible' => (int) $o['quantity'] > 0,
            ], $otros),
        ];
    }

    /**
     * Guarda el carrito de la clienta y devuelve el código corto y el del QR.
     * null si el link no existe; string con el error si el carrito no sirve.
     * @param array $items [{id, cantidad}]
     * @return array|string|null
     */
    public function crearCarrito(string $slug, array $items)
    {
        $n = $this->negocio($slug);
        if (!$n) {
            return null;
        }
        [$idNegocio, , $sucursal] = $n;
        $cantidades = [];
        foreach ($items as $i) {
            $id = (int) ($i['id'] ?? 0);
            $cantidad = (int) ($i['cantidad'] ?? 0);
            if ($id <= 0 || $cantidad < 1 || $cantidad > 99) {
                return 'Revisá las cantidades del carrito.';
            }
            $cantidades[$id] = min(99, ($cantidades[$id] ?? 0) + $cantidad);
        }
        if (!$cantidades) {
            return 'El carrito está vacío.';
        }
        if (count($cantidades) > 50) {
            return 'El carrito tiene demasiados productos.';
        }
        $productos = $this->carritos->productos($idNegocio, $sucursal, array_keys($cantidades));
        if (count($productos) !== count($cantidades)) {
            return 'Algún producto del carrito ya no está disponible. Volvé a escanearlo.';
        }
        $codigo = null;
        for ($i = 0; $i < 30; $i++) {
            $candidato = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (!$this->carritos->codigoEnUso($idNegocio, $candidato)) {
                $codigo = $candidato;
                break;
            }
        }
        if ($codigo === null) {
            return 'Hay demasiados carritos abiertos. Probá de nuevo en un rato.';
        }
        $token = bin2hex(random_bytes(12));
        $lista = [];
        foreach ($cantidades as $id => $cantidad) {
            $lista[] = ['id' => $id, 'cantidad' => $cantidad];
        }
        $this->carritos->crear($idNegocio, $sucursal, $codigo, $token, $lista);
        $total = 0.0;
        foreach ($cantidades as $id => $cantidad) {
            $total += (float) $productos[$id]['sale_price'] * $cantidad;
        }
        return [
            'codigo' => $codigo,
            'qr' => self::PREFIJO_QR . $token,
            'total' => round($total, 2),
            'vence_horas' => CarritoClienteRepository::HORAS,
        ];
    }

    /**
     * Carrito para cargar en Caja, por el código de 4 números o el texto del QR.
     * Devuelve los productos con el precio de ahora (en Caja se recalcula igual).
     */
    public function carritoParaCaja(int $idNegocio, string $valor): ?array
    {
        $valor = trim($valor);
        if (str_starts_with(strtoupper($valor), self::PREFIJO_QR)) {
            $c = $this->carritos->vigente($idNegocio, null, strtolower(substr($valor, strlen(self::PREFIJO_QR))));
        } elseif (preg_match('/^\d{4}$/', $valor)) {
            $c = $this->carritos->vigente($idNegocio, $valor, null);
        } else {
            return null;
        }
        if (!$c) {
            return null;
        }
        $items = json_decode((string) $c['items'], true) ?: [];
        $productos = $this->carritos->productos($idNegocio, (int) $c['sucursal'], array_column($items, 'id'));
        $this->carritos->marcarTomado((int) $c['id']);
        return [
            'codigo' => $c['codigo'],
            'sucursal' => (int) $c['sucursal'],
            'creado_en' => $c['creado_en'],
            'ya_cargado' => $c['tomado_en'] !== null,
            'items' => array_values(array_map(fn ($i) => [
                'id' => (int) $i['id'],
                'cantidad' => (int) $i['cantidad'],
                'codigo' => $productos[(int) $i['id']]['code'] ?? null,
                'nombre' => $productos[(int) $i['id']]['description'] ?? null,
                'talle' => $productos[(int) $i['id']]['size'] ?? null,
                'precio' => isset($productos[(int) $i['id']]) ? (float) $productos[(int) $i['id']]['sale_price'] : null,
                'disponible' => isset($productos[(int) $i['id']]),
            ], $items)),
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
