<?php

namespace App\Service\Facturas;

use App\Repository\FacturaIaRepository;

/**
 * "Cargar factura": la IA lee la factura del proveedor (LectorFacturas) y acá
 * se normaliza lo que devolvió, se sugiere a qué producto va cada renglón y,
 * al confirmar, se suma el stock, se crean los productos nuevos y se aprende
 * el emparejamiento para la próxima factura del mismo proveedor.
 */
class FacturaIaService
{
    public const MAXIMO_RENGLONES = 200;
    /** Puntaje mínimo (0 a 1) para sugerir un producto por parecido del nombre. */
    private const UMBRAL_NOMBRE = 0.5;

    public function __construct(private LectorFacturas $lector, private FacturaIaRepository $repo)
    {
    }

    public function configurado(): bool
    {
        return $this->lector->configurado();
    }

    /** Lee la factura y devuelve los renglones con la sugerencia de producto de cada uno. */
    public function leer(int $idNegocio, int $sucursal, string $contenido, string $mime): array
    {
        $factura = self::normalizar($this->lector->leer($contenido, $mime));
        if (!$factura['items']) {
            throw new \RuntimeException('No se encontraron productos en la imagen. Probá con una foto más nítida, derecha y con buena luz.');
        }
        return $this->sugerir($idNegocio, $sucursal, $factura);
    }

    /** Pasa lo que devuelve la IA a tipos seguros (nunca se confía en su forma). */
    public static function normalizar(array $c): array
    {
        $texto = fn ($v, int $max) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $items = [];
        foreach (array_slice(is_array($c['items'] ?? null) ? $c['items'] : [], 0, self::MAXIMO_RENGLONES) as $i) {
            if (!is_array($i)) {
                continue;
            }
            $descripcion = $texto($i['descripcion'] ?? null, 255);
            $cantidad = is_numeric($i['cantidad'] ?? null) ? (float) $i['cantidad'] : 0;
            $precio = is_numeric($i['precio_unitario'] ?? null) ? (float) $i['precio_unitario'] : 0;
            if ($descripcion === null || $cantidad <= 0 || $precio < 0) {
                continue;
            }
            $items[] = [
                'codigo' => $texto($i['codigo'] ?? null, 100),
                'descripcion' => $descripcion,
                'cantidad' => $cantidad,
                'precio_unitario' => round($precio, 2),
            ];
        }
        $cuit = preg_replace('/\D/', '', (string) ($c['cuit_proveedor'] ?? ''));
        $fecha = $texto($c['fecha'] ?? null, 10);
        return [
            'proveedor' => $texto($c['proveedor'] ?? null, 100),
            'cuit_proveedor' => strlen($cuit) === 11 ? $cuit : null,
            'fecha' => $fecha !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : null,
            'numero' => $texto($c['numero'] ?? null, 40),
            'precios_sin_iva' => !empty($c['precios_sin_iva']),
            'items' => $items,
        ];
    }

    /** Clave del proveedor para recordar emparejamientos: el CUIT, o si no, el nombre normalizado. */
    public static function claveProveedor(?string $cuit, ?string $nombre): string
    {
        if ($cuit) {
            return $cuit;
        }
        return mb_substr(self::normalizarTexto((string) $nombre), 0, 100);
    }

    public static function hashTexto(string $descripcion): string
    {
        return hash('sha256', self::normalizarTexto($descripcion));
    }

    public static function normalizarTexto(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', ',' => '.']);
        $s = preg_replace('/[^a-z0-9.]+/', ' ', $s);
        $s = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** @return string[] */
    private static function tokens(string $s): array
    {
        return array_values(array_unique(array_filter(explode(' ', self::normalizarTexto($s)), fn ($t) => strlen($t) >= 2)));
    }

    /**
     * Parecido entre dos nombres (0 a 1). Una palabra coincide si es igual o si
     * una empieza con la otra (las facturas abrevian: "GALL" por "galletitas").
     */
    public static function parecido(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0;
        }
        $coincide = function (string $x, array $lista): bool {
            foreach ($lista as $y) {
                if ($x === $y || (strlen($x) >= 3 && strlen($y) >= 3 && (str_starts_with($y, $x) || str_starts_with($x, $y)))) {
                    return true;
                }
            }
            return false;
        };
        $enA = count(array_filter($a, fn ($t) => $coincide($t, $b)));
        $enB = count(array_filter($b, fn ($t) => $coincide($t, $a)));
        return ($enA / count($a) + $enB / count($b)) / 2;
    }

    private function sugerir(int $idNegocio, int $sucursal, array $factura): array
    {
        $catalogo = $this->repo->catalogo($idNegocio, $sucursal);
        $porCodigo = [];
        foreach ($catalogo as $i => $p) {
            foreach (['code', 'barcode', 'codigoInterno'] as $campo) {
                if ($p[$campo] !== null && $p[$campo] !== '' && !isset($porCodigo[(string) $p[$campo]])) {
                    $porCodigo[(string) $p[$campo]] = $i;
                }
            }
            $catalogo[$i]['_tokens'] = self::tokens($p['description']);
        }
        $proveedor = self::claveProveedor($factura['cuit_proveedor'], $factura['proveedor']);
        $aprendidos = $proveedor !== '' ? $this->repo->alias($idNegocio, $proveedor) : [];

        foreach ($factura['items'] as &$item) {
            $sugerencia = null;
            $codigoAprendido = $aprendidos[self::hashTexto($item['descripcion'])] ?? null;
            if ($codigoAprendido !== null && isset($porCodigo[$codigoAprendido])) {
                $sugerencia = [$porCodigo[$codigoAprendido], 'aprendido', 1.0];
            } elseif ($item['codigo'] !== null && isset($porCodigo[$item['codigo']])) {
                $sugerencia = [$porCodigo[$item['codigo']], 'codigo', 1.0];
            } else {
                $tokens = self::tokens($item['descripcion']);
                $mejor = null;
                foreach ($catalogo as $i => $p) {
                    $puntaje = self::parecido($tokens, $p['_tokens']);
                    if ($puntaje >= self::UMBRAL_NOMBRE && ($mejor === null || $puntaje > $mejor[2])) {
                        $mejor = [$i, 'nombre', round($puntaje, 2)];
                    }
                }
                $sugerencia = $mejor;
            }
            if ($sugerencia) {
                $p = $catalogo[$sugerencia[0]];
                unset($p['_tokens']);
                $item['sugerencia'] = $p + ['motivo' => $sugerencia[1], 'confianza' => $sugerencia[2]];
            } else {
                $item['sugerencia'] = null;
            }
        }
        unset($item);
        $factura['proveedor_clave'] = $proveedor;
        return $factura;
    }

    /**
     * Valida los renglones que confirma el usuario. Devuelve [renglones, errores].
     * Cada renglón: {accion: 'sumar', id, ...} o {accion: 'crear', descripcion, codigo?, ...},
     * con cantidad (entera, >= 1), costo y precio_venta (>= 0) y texto_factura (para aprender).
     */
    public static function validarConfirmacion(array $crudos): array
    {
        $renglones = [];
        $errores = [];
        foreach (array_values($crudos) as $n => $r) {
            $fila = $n + 1;
            $accion = $r['accion'] ?? null;
            $cantidad = is_numeric($r['cantidad'] ?? null) ? (int) round((float) $r['cantidad']) : 0;
            $costo = is_numeric($r['costo'] ?? null) ? round((float) $r['costo'], 2) : -1;
            $venta = is_numeric($r['precio_venta'] ?? null) ? round((float) $r['precio_venta'], 2) : -1;
            if (!in_array($accion, ['sumar', 'crear'], true)) {
                $errores[] = "Renglón $fila: acción inválida.";
                continue;
            }
            if ($cantidad < 1 || $costo < 0 || $venta < 0) {
                $errores[] = "Renglón $fila: revisá la cantidad y los precios.";
                continue;
            }
            $renglon = [
                'accion' => $accion,
                'cantidad' => $cantidad,
                'costo' => $costo,
                'precio_venta' => $venta,
                'texto_factura' => is_string($r['texto_factura'] ?? null) ? mb_substr(trim($r['texto_factura']), 0, 255) : '',
            ];
            if ($accion === 'sumar') {
                $renglon['id'] = (int) ($r['id'] ?? 0);
                if ($renglon['id'] <= 0) {
                    $errores[] = "Renglón $fila: falta elegir el producto.";
                    continue;
                }
            } else {
                $renglon['descripcion'] = mb_substr(trim((string) ($r['descripcion'] ?? '')), 0, 255);
                $renglon['codigo'] = mb_substr(trim((string) ($r['codigo'] ?? '')), 0, 100);
                if ($renglon['descripcion'] === '') {
                    $errores[] = "Renglón $fila: falta el nombre del producto nuevo.";
                    continue;
                }
            }
            $renglones[] = $renglon;
        }
        return [$renglones, $errores];
    }

    /** @return array{sumados: int, creados: int} */
    public function confirmar(int $idNegocio, int $sucursal, string $proveedor, array $renglones, string $fecha): array
    {
        return $this->repo->enTransaccion(function () use ($idNegocio, $sucursal, $proveedor, $renglones, $fecha) {
            $sumados = 0;
            $creados = 0;
            foreach ($renglones as $n => $r) {
                if ($r['accion'] === 'sumar') {
                    $producto = $this->repo->producto($r['id'], $idNegocio, $sucursal);
                    if (!$producto) {
                        throw new \DomainException('Renglón ' . ($n + 1) . ': el producto ya no existe en esta sucursal.');
                    }
                    $this->repo->sumarStock($r['id'], $r['cantidad'], $r['costo'], $r['precio_venta'], $fecha);
                    $codigo = (string) $producto['code'];
                    $sumados++;
                } else {
                    [$codigo, $barcode] = $this->codigosNuevo($idNegocio, $sucursal, $r['codigo']);
                    $this->repo->crear($idNegocio, $sucursal, $r + ['codigo' => $codigo, 'barcode' => $barcode], $fecha);
                    $creados++;
                }
                if ($proveedor !== '' && $r['texto_factura'] !== '' && $codigo !== '') {
                    $this->repo->guardarAlias($idNegocio, $proveedor, self::hashTexto($r['texto_factura']), $codigo);
                }
            }
            return ['sumados' => $sumados, 'creados' => $creados];
        });
    }

    /**
     * Código para un producto nuevo: el de la factura si está libre (y si parece
     * un código de barras, también va como código de barras); si no, uno generado.
     *
     * @return array{0: string, 1: ?string}
     */
    private function codigosNuevo(int $idNegocio, int $sucursal, string $codigoFactura): array
    {
        $barcode = preg_match('/^\d{8,14}$/', $codigoFactura) ? $codigoFactura : null;
        if ($codigoFactura !== '' && !$this->repo->codigoUsado($idNegocio, $sucursal, $codigoFactura)) {
            return [$codigoFactura, $barcode];
        }
        do {
            $codigo = 'F' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
        } while ($this->repo->codigoUsado($idNegocio, $sucursal, $codigo));
        return [$codigo, $barcode];
    }
}
