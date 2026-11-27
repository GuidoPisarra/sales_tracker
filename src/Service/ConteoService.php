<?php

namespace App\Service;

use App\Repository\ConteoRepository;

/**
 * Conteo de stock desde el celular. Cualquiera del negocio puede mandar un conteo
 * (lo que contó de cada producto); queda pendiente hasta que el administrador lo
 * aplica o lo descarta. Si lo manda el administrador puede aplicarlo en el momento.
 *
 * Al aplicar se suma al stock actual la diferencia (contado - sistema al contar), no
 * se pisa con lo contado: si entre el conteo y la aprobación se vendió algo, esa venta
 * se respeta.
 *
 * La diferencia en plata se calcula al costo; si el producto no tiene costo, al precio de venta.
 */
class ConteoService
{
    public const MAX_ITEMS = 5000;

    public function __construct(private ConteoRepository $repo)
    {
    }

    /**
     * @param array $items [{id, contado}]
     * @return array|string el conteo creado, o un mensaje de error
     */
    public function crear(int $idNegocio, int $sucursal, int $usuario, bool $esTitular, array $items, ?string $alcance, ?string $observacion, bool $aplicar)
    {
        $contados = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $contado = $item['contado'] ?? null;
            if ($id <= 0 || !is_numeric($contado) || (int) $contado != $contado || (int) $contado < 0 || (int) $contado > 1000000) {
                return 'Hay una cantidad inválida: tiene que ser un número entero, 0 o más.';
            }
            $contados[$id] = (int) $contado;
        }
        if (!$contados) {
            return 'El conteo está vacío.';
        }
        if (count($contados) > self::MAX_ITEMS) {
            return 'Se pueden contar hasta ' . self::MAX_ITEMS . ' productos por conteo.';
        }
        $productos = $this->repo->productos($idNegocio, $sucursal, array_keys($contados));
        if (count($productos) !== count($contados)) {
            return 'Hay productos que no existen o no son de esta sucursal. Actualizá la lista y volvé a intentar.';
        }
        $renglones = [];
        foreach ($contados as $id => $contado) {
            $p = $productos[$id];
            $renglones[] = [
                'id_producto' => $id,
                'descripcion' => $p['description'],
                'codigo' => $p['code'],
                'sistema' => (int) $p['quantity'],
                'contado' => $contado,
                'costo' => (float) $p['cost_price'],
                'precio' => (float) $p['sale_price'],
            ];
        }
        $id = $this->repo->crear($idNegocio, $sucursal, $usuario, $this->texto($alcance, 120), $this->texto($observacion, 300), $renglones, CajaService::ahora());
        if ($aplicar && $esTitular) {
            $this->repo->aplicar($id, $idNegocio, $usuario, CajaService::ahora());
        }
        return $this->conteo($id, $idNegocio, null);
    }

    /** Un conteo con sus renglones. $soloDe limita a los conteos de ese usuario (empleados). */
    public function conteo(int $id, int $idNegocio, ?int $soloDe): ?array
    {
        $c = $this->repo->conteo($id, $idNegocio);
        if (!$c || ($soloDe !== null && (int) $c['creado_por'] !== $soloDe)) {
            return null;
        }
        $items = $this->repo->items([$id])[$id] ?? [];
        $conteo = $this->normalizar($c, $items);
        $conteo['items'] = array_map(fn ($i) => [
            'id' => (int) $i['id_producto'],
            'descripcion' => $i['descripcion'],
            'codigo' => $i['codigo'],
            'sistema' => (int) $i['sistema'],
            'contado' => (int) $i['contado'],
            'diferencia' => (int) $i['contado'] - (int) $i['sistema'],
            'valor' => round(((int) $i['contado'] - (int) $i['sistema']) * $this->valorUnitario($i), 2),
        ], $items);
        return $conteo;
    }

    public function conteos(int $idNegocio, ?int $soloDe, ?string $estado): array
    {
        $filas = $this->repo->conteos($idNegocio, $soloDe, in_array($estado, ['pendiente', 'aplicado', 'descartado'], true) ? $estado : null);
        $items = $this->repo->items(array_map(fn ($c) => (int) $c['id'], $filas));
        return array_map(fn ($c) => $this->normalizar($c, $items[(int) $c['id']] ?? []), $filas);
    }

    /** @return array|string el conteo aplicado, o un mensaje de error */
    public function aplicar(int $id, int $idNegocio, int $usuario)
    {
        if (!$this->repo->conteo($id, $idNegocio)) {
            return 'No se encontró ese conteo.';
        }
        if (!$this->repo->aplicar($id, $idNegocio, $usuario, CajaService::ahora())) {
            return 'Ese conteo ya fue aplicado o descartado.';
        }
        return $this->conteo($id, $idNegocio, null);
    }

    /** @return array|string el conteo descartado, o un mensaje de error */
    public function descartar(int $id, int $idNegocio, int $usuario)
    {
        if (!$this->repo->conteo($id, $idNegocio)) {
            return 'No se encontró ese conteo.';
        }
        if (!$this->repo->descartar($id, $idNegocio, $usuario, CajaService::ahora())) {
            return 'Ese conteo ya fue aplicado o descartado.';
        }
        return $this->conteo($id, $idNegocio, null);
    }

    private function valorUnitario(array $item): float
    {
        return (float) $item['costo'] > 0 ? (float) $item['costo'] : (float) $item['precio'];
    }

    private function normalizar(array $c, array $items): array
    {
        $conDiferencia = 0;
        $faltantes = 0;
        $sobrantes = 0;
        $valorFaltante = 0.0;
        $valorSobrante = 0.0;
        foreach ($items as $i) {
            $dif = (int) $i['contado'] - (int) $i['sistema'];
            if ($dif === 0) {
                continue;
            }
            $conDiferencia++;
            if ($dif < 0) {
                $faltantes += -$dif;
                $valorFaltante += -$dif * $this->valorUnitario($i);
            } else {
                $sobrantes += $dif;
                $valorSobrante += $dif * $this->valorUnitario($i);
            }
        }
        return [
            'id' => (int) $c['id'],
            'sucursal' => (int) $c['sucursal'],
            'estado' => $c['estado'],
            'alcance' => $c['alcance'],
            'observacion' => $c['observacion'],
            'creado' => $c['creado'],
            'creado_por' => $c['creado_por_nombre'] ?? null,
            'resuelto' => $c['resuelto'],
            'resuelto_por' => $c['resuelto_por_nombre'] ?? null,
            'resumen' => [
                'productos' => count($items),
                'con_diferencia' => $conDiferencia,
                'unidades_faltantes' => $faltantes,
                'unidades_sobrantes' => $sobrantes,
                'valor_faltante' => round($valorFaltante, 2),
                'valor_sobrante' => round($valorSobrante, 2),
            ],
        ];
    }

    private function texto(?string $s, int $largo): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : mb_substr($s, 0, $largo);
    }
}
