<?php

namespace App\Service;

use App\Repository\PlanRepository;
use App\Repository\SuscripcionRepository;

/**
 * Estado de la suscripción de un negocio y límites de su plan.
 *
 * Estados guardados: prueba, activa, en_mora, cancelada.
 * Estado efectivo (calculado): además "prueba_vencida" y "suspendida" (mora
 * vencida la gracia). En esos dos y en "cancelada" el negocio queda en modo
 * solo lectura: puede ver y exportar sus datos, pero no cargar ni vender.
 */
class SuscripcionService
{
    public const RECURSOS = ['sucursales', 'usuarios', 'productos', 'mensajes_ia'];

    private SuscripcionRepository $repo;
    private PlanRepository $planes;

    public function __construct(SuscripcionRepository $repo, PlanRepository $planes)
    {
        $this->repo = $repo;
        $this->planes = $planes;
    }

    public function suscripcion(int $idNegocio): ?array
    {
        return $this->repo->porNegocio($idNegocio);
    }

    public function estadoEfectivo(?array $s, ?\DateTimeImmutable $ahora = null): string
    {
        if ($s === null) {
            return 'activa'; // Negocio sin suscripción (base sin migrar): no se bloquea.
        }
        $ahora ??= new \DateTimeImmutable();
        switch ($s['estado']) {
            case 'prueba':
                return ($s['prueba_hasta'] && new \DateTimeImmutable($s['prueba_hasta']) < $ahora) ? 'prueba_vencida' : 'prueba';
            case 'en_mora':
                $desde = new \DateTimeImmutable($s['en_mora_desde'] ?? 'now');
                $fin = $desde->modify('+' . Entorno::int('DIAS_GRACIA_MORA', 7) . ' days');
                return $fin < $ahora ? 'suspendida' : 'en_mora';
            case 'cancelada':
                // Si canceló pero tiene el mes pagado, sigue activa hasta el fin del período.
                return ($s['periodo_hasta'] && new \DateTimeImmutable($s['periodo_hasta']) > $ahora) ? 'activa' : 'cancelada';
            default:
                return 'activa';
        }
    }

    public function soloLectura(int $idNegocio): bool
    {
        return in_array($this->estadoEfectivo($this->repo->porNegocio($idNegocio)), ['prueba_vencida', 'suspendida', 'cancelada'], true);
    }

    /**
     * Resumen para el front (login y pantalla "Mi plan").
     */
    public function resumen(int $idNegocio, bool $conUso = false): array
    {
        $s = $this->repo->porNegocio($idNegocio);
        $plan = $this->planes->obtenerPorNegocio($idNegocio);
        $estado = $this->estadoEfectivo($s);
        $diasPrueba = null;
        if ($s && $s['estado'] === 'prueba' && $s['prueba_hasta']) {
            $diasPrueba = max(0, (int) ceil((strtotime($s['prueba_hasta']) - time()) / 86400));
        }
        $resumen = [
            'plan' => $plan ? $this->formatoPlan($plan) : null,
            'estado' => $estado,
            'solo_lectura' => in_array($estado, ['prueba_vencida', 'suspendida', 'cancelada'], true),
            'prueba_hasta' => $s['prueba_hasta'] ?? null,
            'dias_restantes_prueba' => $diasPrueba,
            'periodo_hasta' => $s['periodo_hasta'] ?? null,
            'con_debito_automatico' => !empty($s['mp_preapproval_id']) && ($s['estado'] ?? '') !== 'cancelada',
        ];
        if ($conUso) {
            $resumen['uso'] = $this->repo->uso($idNegocio);
        }
        return $resumen;
    }

    public function formatoPlan(array $p): array
    {
        $int = fn ($v) => $v === null ? null : (int) $v;
        return [
            'id' => (int) $p['id'],
            'codigo' => $p['codigo'] ?? null,
            'nombre' => $p['nombre'],
            'precio_mensual' => isset($p['precio_mensual']) ? (float) $p['precio_mensual'] : null,
            'limites' => [
                'sucursales' => $int($p['max_sucursales'] ?? null),
                'usuarios' => $int($p['max_usuarios'] ?? null),
                'productos' => $int($p['max_productos'] ?? null),
                'mensajes_ia' => $int($p['max_mensajes_ia'] ?? null),
            ],
            'cuentas_corrientes' => !array_key_exists('cuentas_corrientes', $p) || (bool) $p['cuentas_corrientes'],
        ];
    }

    public function planesPublicos(): array
    {
        return array_map([$this, 'formatoPlan'], $this->planes->publicos());
    }

    /**
     * Devuelve null si el negocio puede agregar uno más del recurso; si no, el
     * mensaje para el usuario. $cantidadNueva = cuántos se quieren agregar.
     */
    public function excedeLimite(int $idNegocio, string $recurso, int $cantidadNueva = 1): ?string
    {
        if (!in_array($recurso, self::RECURSOS, true)) {
            throw new \InvalidArgumentException('Recurso desconocido: ' . $recurso);
        }
        $plan = $this->planes->obtenerPorNegocio($idNegocio);
        if (!$plan || !array_key_exists('max_' . $recurso, $plan)) {
            return null; // Sin datos del plan: no se limita.
        }
        $max = $plan['max_' . $recurso];
        if ($max === null) {
            return null; // Ilimitado.
        }
        $uso = $this->repo->uso($idNegocio)[$recurso];
        if ($uso + $cantidadNueva <= (int) $max) {
            return null;
        }
        $nombres = [
            'sucursales' => 'sucursales',
            'usuarios' => 'usuarios',
            'productos' => 'productos',
            'mensajes_ia' => 'mensajes al asistente este mes',
        ];
        if ((int) $max === 0) {
            return sprintf('Tu plan %s no incluye %s. Mejorá tu plan para usarlo.', $plan['nombre'], $recurso === 'mensajes_ia' ? 'el asistente de IA' : $nombres[$recurso]);
        }
        return sprintf('Llegaste al límite de tu plan %s (%d %s). Mejorá tu plan para agregar más.', $plan['nombre'], (int) $max, $nombres[$recurso]);
    }

    public function incluyeCuentasCorrientes(int $idNegocio): bool
    {
        $plan = $this->planes->obtenerPorNegocio($idNegocio);
        return !$plan || !array_key_exists('cuentas_corrientes', $plan) || (bool) $plan['cuentas_corrientes'];
    }

    public function registrarMensajeIa(int $idNegocio): void
    {
        $this->repo->sumarMensajeIa($idNegocio);
    }
}
