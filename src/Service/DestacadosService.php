<?php

namespace App\Service;

use App\Repository\DestacadosRepository;

/**
 * Tarjetas "Para vos" del Inicio: cosas que conviene saber hoy, sin tener que buscarlas.
 *
 * Reglas pedidas por Guido (2026-11-26):
 *  - Nunca se muestra un número negativo ni una comparación para abajo ("vendiste menos").
 *    Si el dato no es bueno o no sirve para actuar, no hay tarjeta.
 *  - Las que hablan de plata (ventas del negocio) las ve solo el administrador; los
 *    empleados ven las de stock.
 *
 * Cada tarjeta: {id, tipo, tono (exito|atencion|info), icono, titulo, texto, ruta?}.
 * Como mucho 4, en orden de importancia.
 */
class DestacadosService
{
    private const MAX = 4;
    private const DIAS_RITMO = 14;
    private const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public function __construct(private DestacadosRepository $repo)
    {
    }

    public function tarjetas(int $idNegocio, int $sucursal, bool $esTitular, ?\DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $hoy = $ahora->format('Y-m-d');
        $tarjetas = [];

        if ($esTitular) {
            array_push($tarjetas, ...$this->ventasDelDia($idNegocio, $ahora));
        }

        // Stock: ritmo de venta de las últimas 2 semanas en la sucursal.
        $desde = $ahora->modify('-' . self::DIAS_RITMO . ' days')->format('Y-m-d 00:00:00');
        $productos = $this->repo->ventasPorProducto($idNegocio, $sucursal, $desde);
        array_push($tarjetas, ...$this->seTerminan($productos, $hoy));
        array_push($tarjetas, ...$this->agotados($productos, $hoy));

        if ($esTitular) {
            array_push($tarjetas, ...$this->semana($idNegocio, $ahora));
        }
        array_push($tarjetas, ...$this->estrella($idNegocio, $sucursal, $ahora));

        return array_slice($tarjetas, 0, self::MAX);
    }

    /** Récord del último mes, o mejor que el mismo día de la semana pasada. Solo si es bueno. */
    private function ventasDelDia(int $idNegocio, \DateTimeImmutable $ahora): array
    {
        $hoy = $ahora->format('Y-m-d');
        $dias = $this->repo->totalesPorDia($idNegocio, $ahora->modify('-30 days')->format('Y-m-d 00:00:00'));
        $totalHoy = $dias[$hoy] ?? 0.0;
        if ($totalHoy <= 0) {
            return [];
        }
        $anteriores = array_filter($dias, fn ($d) => $d !== $hoy, ARRAY_FILTER_USE_KEY);
        if (count($anteriores) >= 7 && $totalHoy > max($anteriores)) {
            return [$this->tarjeta('record', $hoy, 'exito', 'trophy-outline', '¡Hoy es tu mejor día del mes!',
                'Ya vendiste más que cualquier otro día de los últimos 30.', '/ventas')];
        }
        $mismoDia = $ahora->modify('-7 days')->format('Y-m-d');
        $antes = $dias[$mismoDia] ?? 0.0;
        if ($antes > 0) {
            $suba = (int) floor(($totalHoy / $antes - 1) * 100);
            if ($suba >= 10) {
                $nombre = self::DIAS_SEMANA[(int) $ahora->format('w')];
                return [$this->tarjeta('mejor_dia', $hoy . $suba, 'exito', 'trending-up-outline', "Hoy vas {$suba}% arriba",
                    "Ya vendiste más que en todo el {$nombre} pasado.", '/ventas')];
            }
        }
        return [];
    }

    /** Productos que, al ritmo que se venden, se terminan en menos de una semana. */
    private function seTerminan(array $productos, string $hoy): array
    {
        $lista = [];
        foreach ($productos as $p) {
            $stock = (int) $p['quantity'];
            $vendidas = (int) $p['vendidas'];
            if ($stock <= 0 || $vendidas < 3) {
                continue;
            }
            $dias = $stock / ($vendidas / self::DIAS_RITMO);
            if ($dias < 7) {
                $lista[] = ['p' => $p, 'dias' => max(1, (int) round($dias))];
            }
        }
        usort($lista, fn ($a, $b) => $a['dias'] <=> $b['dias']);
        return array_map(function ($x) use ($hoy) {
            $p = $x['p'];
            $porSemana = max(1, (int) round((int) $p['vendidas'] / 2));
            $quedan = (int) $p['quantity'];
            return $this->tarjeta('se_termina', $hoy . $p['id'], 'atencion', 'hourglass-outline',
                $this->nombre($p) . ($x['dias'] === 1 ? ' se termina mañana' : " se termina en {$x['dias']} días"),
                "Vendés unas {$porSemana} por semana y " . ($quedan === 1 ? 'queda 1.' : "quedan {$quedan}."), '/stock');
        }, array_slice($lista, 0, 2));
    }

    /** Se agotó algo que se venía vendiendo bien. */
    private function agotados(array $productos, string $hoy): array
    {
        $lista = array_filter($productos, fn ($p) => (int) $p['quantity'] <= 0 && (int) $p['vendidas'] >= 3);
        usort($lista, fn ($a, $b) => (int) $b['vendidas'] <=> (int) $a['vendidas']);
        return array_map(fn ($p) => $this->tarjeta('agotado', $hoy . $p['id'], 'atencion', 'alert-circle-outline',
            'Se agotó ' . $this->nombre($p),
            'Vendiste ' . (int) $p['vendidas'] . ' en las últimas 2 semanas. Conviene reponer.', '/stock'), array_slice($lista, 0, 1));
    }

    /** Semana en curso (desde el lunes) mejor que el mismo tramo de la anterior. */
    private function semana(int $idNegocio, \DateTimeImmutable $ahora): array
    {
        $diaSemana = (int) $ahora->format('N'); // 1 = lunes
        if ($diaSemana < 3) {
            return []; // con uno o dos días no dice mucho
        }
        $lunes = $ahora->modify('-' . ($diaSemana - 1) . ' days');
        $dias = $this->repo->totalesPorDia($idNegocio, $lunes->modify('-7 days')->format('Y-m-d 00:00:00'));
        $esta = 0.0;
        $anterior = 0.0;
        for ($i = 0; $i < $diaSemana; $i++) {
            $esta += $dias[$lunes->modify("+$i days")->format('Y-m-d')] ?? 0;
            $anterior += $dias[$lunes->modify('+' . ($i - 7) . ' days')->format('Y-m-d')] ?? 0;
        }
        if ($anterior <= 0) {
            return [];
        }
        $suba = (int) floor(($esta / $anterior - 1) * 100);
        if ($suba < 10) {
            return [];
        }
        return [$this->tarjeta('semana', $lunes->format('Y-m-d') . $suba, 'exito', 'stats-chart-outline', "Esta semana vas {$suba}% arriba",
            'Comparado con los mismos días de la semana pasada.', '/ventas')];
    }

    /** Lo más vendido de los últimos 7 días. */
    private function estrella(int $idNegocio, int $sucursal, \DateTimeImmutable $ahora): array
    {
        $productos = $this->repo->ventasPorProducto($idNegocio, $sucursal, $ahora->modify('-7 days')->format('Y-m-d 00:00:00'));
        usort($productos, fn ($a, $b) => (int) $b['vendidas'] <=> (int) $a['vendidas']);
        $p = $productos[0] ?? null;
        if (!$p || (int) $p['vendidas'] < 3) {
            return [];
        }
        return [$this->tarjeta('estrella', $ahora->format('Y-m-d') . $p['id'], 'info', 'star-outline', 'Lo más vendido de la semana',
            $this->nombre($p) . ': ' . (int) $p['vendidas'] . ' unidades.', '/lista-productos')];
    }

    private function nombre(array $p): string
    {
        $talle = trim((string) ($p['size'] ?? ''));
        return trim((string) $p['description']) . ($talle !== '' ? " $talle" : '');
    }

    private function tarjeta(string $tipo, string $clave, string $tono, string $icono, string $titulo, string $texto, ?string $ruta): array
    {
        return [
            'id' => $tipo . '-' . substr(md5($clave), 0, 8),
            'tipo' => $tipo,
            'tono' => $tono,
            'icono' => $icono,
            'titulo' => $titulo,
            'texto' => $texto,
            'ruta' => $ruta,
        ];
    }
}
