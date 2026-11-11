<?php

namespace App\Service;

use App\Repository\CajaRepository;

/**
 * Apertura y cierre de caja. Es opcional: hasta que el administrador la activa,
 * nada cambia en las ventas. Con la caja activa, cada sucursal abre un turno con
 * un monto inicial, puede registrar retiros e ingresos de efectivo, y al cerrar
 * se compara lo contado con lo esperado:
 *
 *   esperado en efectivo = monto inicial + ventas en efectivo + ingresos - retiros
 *
 * Las ventas con otras formas de pago se muestran como resumen (no se cuentan en la caja).
 * No se bloquean las ventas con la caja cerrada: la app avisa y ofrece abrirla.
 */
class CajaService
{
    public const TIPOS_MOVIMIENTO = ['ingreso', 'retiro'];

    public function __construct(private CajaRepository $repo)
    {
    }

    public static function ahora(): string
    {
        return (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');
    }

    public function activa(int $idNegocio): bool
    {
        return (int) ($this->repo->config($idNegocio)['caja_activa'] ?? 0) === 1;
    }

    public function activar(int $idNegocio, bool $activa): void
    {
        $this->repo->guardarConfig($idNegocio, ['caja_activa' => $activa ? 1 : 0]);
    }

    /** Estado de la caja de una sucursal: si se usa, y el turno abierto con su resumen. */
    public function estado(int $idNegocio, int $sucursal): array
    {
        $turno = $this->repo->turnoAbierto($idNegocio, $sucursal);
        return [
            'activa' => $this->activa($idNegocio),
            'turno' => $turno ? $this->conResumen($turno) : null,
        ];
    }

    /** @return array|string el turno abierto, o un mensaje de error */
    public function abrir(int $idNegocio, int $sucursal, int $usuario, float $montoInicial, ?string $observacion)
    {
        if (!$this->activa($idNegocio)) {
            return 'La apertura y cierre de caja no está activada en este negocio.';
        }
        if ($montoInicial < 0) {
            return 'El monto inicial no puede ser negativo.';
        }
        $id = $this->repo->abrir($idNegocio, $sucursal, $usuario, round($montoInicial, 2), $this->texto($observacion), self::ahora());
        if ($id === null) {
            return 'La caja de esta sucursal ya está abierta.';
        }
        return $this->conResumen($this->repo->turno($id, $idNegocio));
    }

    /** @return array|string el turno con el movimiento, o un mensaje de error */
    public function movimiento(int $idNegocio, int $sucursal, int $usuario, string $tipo, float $monto, ?string $motivo)
    {
        if (!in_array($tipo, self::TIPOS_MOVIMIENTO, true)) {
            return 'El movimiento tiene que ser un ingreso o un retiro.';
        }
        if ($monto <= 0) {
            return 'El monto tiene que ser mayor a 0.';
        }
        $turno = $this->repo->turnoAbierto($idNegocio, $sucursal);
        if (!$turno) {
            return 'La caja está cerrada.';
        }
        $this->repo->agregarMovimiento((int) $turno['id'], $idNegocio, $tipo, round($monto, 2), $this->texto($motivo), $usuario, self::ahora());
        return $this->conResumen($this->repo->turno((int) $turno['id'], $idNegocio));
    }

    /** @return array|string el turno cerrado, o un mensaje de error */
    public function cerrar(int $idNegocio, int $sucursal, int $usuario, float $contado, ?string $observacion)
    {
        if ($contado < 0) {
            return 'Lo contado no puede ser negativo.';
        }
        $turno = $this->repo->turnoAbierto($idNegocio, $sucursal);
        if (!$turno) {
            return 'La caja ya está cerrada.';
        }
        $ahora = self::ahora();
        $resumen = $this->resumen($turno, $ahora);
        if (!$this->repo->cerrar((int) $turno['id'], $idNegocio, $usuario, $ahora, $resumen['esperado_efectivo'], round($contado, 2), $this->texto($observacion))) {
            return 'La caja ya está cerrada.';
        }
        return $this->conResumen($this->repo->turno((int) $turno['id'], $idNegocio));
    }

    public function turnos(int $idNegocio, ?string $desde, ?string $hasta): array
    {
        return array_map(fn ($t) => $this->normalizar($t), $this->repo->turnos($idNegocio, $desde, $hasta));
    }

    public function turno(int $id, int $idNegocio): ?array
    {
        $t = $this->repo->turno($id, $idNegocio);
        return $t ? $this->conResumen($t) : null;
    }

    private function conResumen(array $turno): array
    {
        $resumen = $this->resumen($turno, $turno['cierre'] ?? self::ahora());
        $t = $this->normalizar($turno);
        $t['resumen'] = $resumen;
        $t['movimientos'] = array_map(fn ($m) => [
            'id' => (int) $m['id'],
            'tipo' => $m['tipo'],
            'monto' => (float) $m['monto'],
            'motivo' => $m['motivo'],
            'fecha' => $m['fecha'],
            'usuario' => $m['usuario_nombre'],
        ], $this->repo->movimientos((int) $turno['id'], (int) $turno['id_negocio']));
        return $t;
    }

    private function resumen(array $turno, string $hasta): array
    {
        $formas = [];
        $ventasEfectivo = 0.0;
        $totalVentas = 0.0;
        $cantidad = 0;
        foreach ($this->repo->ventasPorFormaPago((int) $turno['id_negocio'], (int) $turno['sucursal'], $turno['apertura'], $hasta) as $f) {
            $total = round((float) $f['total'], 2);
            $formas[] = ['forma_pago' => $f['forma_pago'], 'total' => $total, 'ventas' => (int) $f['ventas']];
            $totalVentas += $total;
            $cantidad += (int) $f['ventas'];
            if ($f['forma_pago'] === 'efectivo') {
                $ventasEfectivo += $total;
            }
        }
        $ingresos = 0.0;
        $retiros = 0.0;
        foreach ($this->repo->movimientos((int) $turno['id'], (int) $turno['id_negocio']) as $m) {
            if ($m['tipo'] === 'ingreso') {
                $ingresos += (float) $m['monto'];
            } else {
                $retiros += (float) $m['monto'];
            }
        }
        $inicial = (float) $turno['monto_inicial'];
        return [
            'por_forma_pago' => $formas,
            'total_ventas' => round($totalVentas, 2),
            'cantidad_ventas' => $cantidad,
            'ventas_efectivo' => round($ventasEfectivo, 2),
            'ingresos' => round($ingresos, 2),
            'retiros' => round($retiros, 2),
            'esperado_efectivo' => round($inicial + $ventasEfectivo + $ingresos - $retiros, 2),
        ];
    }

    private function normalizar(array $t): array
    {
        $num = fn ($v) => $v === null ? null : (float) $v;
        return [
            'id' => (int) $t['id'],
            'sucursal' => (int) $t['sucursal'],
            'abierta' => empty($t['cierre']),
            'apertura' => $t['apertura'],
            'abierta_por' => $t['abierta_por_nombre'] ?? null,
            'monto_inicial' => (float) $t['monto_inicial'],
            'observacion_apertura' => $t['observacion_apertura'] ?? null,
            'cierre' => $t['cierre'] ?? null,
            'cerrada_por' => $t['cerrada_por_nombre'] ?? null,
            'esperado_efectivo' => $num($t['esperado_efectivo'] ?? null),
            'contado_efectivo' => $num($t['contado_efectivo'] ?? null),
            'diferencia' => $num($t['diferencia'] ?? null),
            'observacion_cierre' => $t['observacion_cierre'] ?? null,
        ];
    }

    private function texto(?string $s): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : mb_substr($s, 0, 300);
    }
}
