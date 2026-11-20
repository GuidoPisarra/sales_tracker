<?php

namespace App\Service;

use App\Repository\PlanRepository;
use App\Repository\SuscripcionRepository;

/**
 * Alta, cambio y baja del débito automático en Mercado Pago, y aplicación de lo
 * que Mercado Pago informa (webhook o conciliación diaria) sobre la suscripción.
 */
class CobrosService
{
    private MercadoPagoClient $mp;
    private SuscripcionRepository $repo;
    private PlanRepository $planes;
    private AuditoriaService $auditoria;
    private MailService $mail;

    public function __construct(MercadoPagoClient $mp, SuscripcionRepository $repo, PlanRepository $planes, AuditoriaService $auditoria, MailService $mail)
    {
        $this->mp = $mp;
        $this->repo = $repo;
        $this->planes = $planes;
        $this->auditoria = $auditoria;
        $this->mail = $mail;
    }

    /**
     * Devuelve ['init_point' => url] si hay que ir al checkout, o
     * ['actualizado' => true] si ya tenía débito y solo se cambió el monto.
     */
    public function elegirPlan(int $idNegocio, string $codigoPlan, string $emailPagador, string $modo = 'debito'): array
    {
        if (!$this->mp->configurado()) {
            throw new \DomainException('Los pagos todavía no están habilitados.');
        }
        $plan = $this->planes->porCodigo($codigoPlan);
        if (!$plan || !(int) $plan['publico'] || (float) $plan['precio_mensual'] <= 0) {
            throw new \DomainException('El plan elegido no existe.');
        }
        $s = $this->repo->porNegocio($idNegocio);

        if ($modo === 'mensual') {
            return $this->pagoMensual($idNegocio, $plan, $s);
        }

        // Ya tiene débito autorizado: se cambia el monto y el plan rige desde ahora.
        if ($s && $s['mp_preapproval_id'] && $s['estado'] === 'activa') {
            $actual = $this->mp->obtenerSuscripcion($s['mp_preapproval_id']);
            if (($actual['status'] ?? '') === 'authorized') {
                $this->mp->cambiarMonto($s['mp_preapproval_id'], $plan, $idNegocio);
                $this->repo->actualizar($idNegocio, ['id_plan' => (int) $plan['id'], 'id_plan_pendiente' => null]);
                $this->auditoria->registrar('cambio_plan', ['plan' => $codigoPlan], $idNegocio);
                return ['actualizado' => true];
            }
        }

        $mp = $this->mp->crearSuscripcion($idNegocio, $plan, $emailPagador);
        $this->repo->actualizar($idNegocio, [
            'mp_preapproval_id' => (string) $mp['id'],
            'id_plan_pendiente' => (int) $plan['id'],
        ]);
        $this->auditoria->registrar('checkout_plan', ['plan' => $codigoPlan, 'preapproval' => $mp['id']], $idNegocio);
        return ['init_point' => $mp['init_point'] ?? $mp['sandbox_init_point'] ?? null];
    }

    public function cancelar(int $idNegocio): void
    {
        $s = $this->repo->porNegocio($idNegocio);
        if (!$s || !$s['mp_preapproval_id'] || ($s['modo_pago'] ?? null) === 'mensual') {
            throw new \DomainException('No tenés un débito automático activo.');
        }
        $this->mp->cancelarSuscripcion($s['mp_preapproval_id']);
        $this->repo->actualizar($idNegocio, ['estado' => 'cancelada', 'cancelada_en' => date('Y-m-d H:i:s')]);
        $this->auditoria->registrar('cancelacion_plan', [], $idNegocio);
    }

    /**
     * Aplica el estado de una suscripción de Mercado Pago (objeto preapproval).
     */
    public function aplicarSuscripcion(array $pre): void
    {
        $ref = MercadoPagoClient::leerReferencia($pre['external_reference'] ?? null);
        $s = $this->repo->porPreapproval((string) ($pre['id'] ?? ''));
        if (!$s && $ref) {
            $s = $this->repo->porNegocio($ref[0]);
            // Un preapproval que no es el vigente del negocio (por ejemplo uno viejo) se ignora.
            if ($s && $s['mp_preapproval_id'] && $s['mp_preapproval_id'] !== (string) $pre['id']) {
                return;
            }
        }
        if (!$s) {
            error_log('MP: suscripción desconocida ' . ($pre['id'] ?? '?'));
            return;
        }
        $idNegocio = (int) $s['id_negocio'];
        $plan = $ref ? $this->planes->porCodigo($ref[1]) : null;

        // Quien paga mes a mes y dejó a medias un alta de débito: ese débito no
        // autorizado (que Mercado Pago después pausa o cancela) no le cambia nada.
        if (($s['modo_pago'] ?? null) === 'mensual' && ($pre['status'] ?? '') !== 'authorized') {
            return;
        }

        switch ($pre['status'] ?? '') {
            case 'authorized':
                $cambios = [
                    'estado' => 'activa',
                    'mp_preapproval_id' => (string) $pre['id'],
                    'en_mora_desde' => null,
                    'cancelada_en' => null,
                    'id_plan_pendiente' => null,
                    'exento_limites' => 0,
                    'modo_pago' => 'debito',
                ];
                if ($plan) {
                    $cambios['id_plan'] = (int) $plan['id'];
                }
                if (!empty($pre['next_payment_date'])) {
                    $cambios['periodo_hasta'] = date('Y-m-d H:i:s', strtotime($pre['next_payment_date']));
                }
                $this->repo->actualizar($idNegocio, $cambios);
                if ($s['estado'] !== 'activa' || (int) $s['id_plan'] !== (int) ($plan['id'] ?? 0)) {
                    $this->auditoria->registrar('suscripcion_activada', ['plan' => $ref[1] ?? null], $idNegocio);
                }
                break;
            case 'paused':
                if ($s['estado'] !== 'en_mora') {
                    $this->repo->actualizar($idNegocio, ['estado' => 'en_mora', 'en_mora_desde' => date('Y-m-d H:i:s')]);
                }
                break;
            case 'cancelled':
                if ($s['estado'] !== 'cancelada') {
                    $this->repo->actualizar($idNegocio, ['estado' => 'cancelada', 'cancelada_en' => date('Y-m-d H:i:s')]);
                    $this->auditoria->registrar('suscripcion_cancelada', [], $idNegocio);
                }
                break;
        }
    }

    /**
     * Aplica un cobro mensual (objeto authorized_payment).
     */
    public function aplicarCobro(array $cobro): void
    {
        $s = $this->repo->porPreapproval((string) ($cobro['preapproval_id'] ?? ''));
        if (!$s) {
            error_log('MP: cobro de suscripción desconocida ' . ($cobro['id'] ?? '?'));
            return;
        }
        $idNegocio = (int) $s['id_negocio'];
        $estadoPago = $cobro['payment']['status'] ?? $cobro['status'] ?? 'desconocido';
        $fecha = date('Y-m-d H:i:s', strtotime($cobro['date_created'] ?? $cobro['debit_date'] ?? 'now'));
        $this->repo->registrarPago($idNegocio, (string) $cobro['id'], (float) ($cobro['transaction_amount'] ?? 0), $estadoPago, $fecha, $cobro);

        if ($estadoPago === 'approved') {
            $hasta = (new \DateTimeImmutable($fecha))->modify('+1 month');
            if ($s['periodo_hasta'] && new \DateTimeImmutable($s['periodo_hasta']) > $hasta) {
                $hasta = new \DateTimeImmutable($s['periodo_hasta']);
            }
            $this->repo->actualizar($idNegocio, [
                'estado' => 'activa',
                'en_mora_desde' => null,
                'periodo_hasta' => $hasta->format('Y-m-d H:i:s'),
                'exento_limites' => 0,
            ]);
        } elseif (in_array($estadoPago, ['rejected', 'cancelled'], true) && $s['estado'] !== 'en_mora') {
            $this->repo->actualizar($idNegocio, ['estado' => 'en_mora', 'en_mora_desde' => date('Y-m-d H:i:s')]);
            $this->avisarTitular($idNegocio, 'No pudimos cobrar tu plan de Kontia',
                'Mercado Pago rechazó el último cobro. Revisá tu medio de pago en los próximos ' . Entorno::int('DIAS_GRACIA_MORA', 7)
                . ' días para no perder el acceso a la Caja.');
        }
        $this->auditoria->registrar('pago_suscripcion', ['mp_id' => $cobro['id'] ?? null, 'estado' => $estadoPago], $idNegocio);
    }

    /**
     * Pago mes a mes: crea el pago único de un mes en Mercado Pago.
     */
    private function pagoMensual(int $idNegocio, array $plan, ?array $s): array
    {
        if ($s && $s['mp_preapproval_id'] && ($s['modo_pago'] ?? null) !== 'mensual' && $s['estado'] === 'activa') {
            $actual = $this->mp->obtenerSuscripcion($s['mp_preapproval_id']);
            if (($actual['status'] ?? '') === 'authorized') {
                throw new \DomainException('Tenés el débito automático activo. Cancelalo primero para pasar a pagar mes a mes (no perdés los días ya pagados).');
            }
        }
        $pref = $this->mp->crearPagoMensual($idNegocio, $plan);
        $this->auditoria->registrar('checkout_mensual', ['plan' => $plan['codigo'], 'preferencia' => $pref['id'] ?? null], $idNegocio);
        return ['init_point' => $pref['init_point'] ?? $pref['sandbox_init_point'] ?? null];
    }

    /**
     * Aplica un pago suelto (objeto payment). Solo cuenta los pagos mensuales de
     * Kontia (external_reference "kontia-mes:..."); los demás se ignoran.
     * Cada pago aprobado suma un mes desde el vencimiento actual (o desde hoy si ya
     * venció), así quien paga antes no pierde días.
     */
    public function aplicarPagoMensual(array $pago): void
    {
        $ref = MercadoPagoClient::leerReferenciaMensual($pago['external_reference'] ?? null);
        if (!$ref || empty($pago['id'])) {
            return;
        }
        [$idNegocio, $codigoPlan] = $ref;
        $s = $this->repo->porNegocio($idNegocio);
        $plan = $this->planes->porCodigo($codigoPlan);
        if (!$s || !$plan) {
            error_log('MP: pago mensual de negocio o plan desconocido ' . $pago['id']);
            return;
        }
        $mpId = (string) $pago['id'];
        $estadoPago = (string) ($pago['status'] ?? 'desconocido');
        $yaAprobado = $this->repo->estadoPago($mpId) === 'approved';
        $fecha = date('Y-m-d H:i:s', strtotime($pago['date_approved'] ?? $pago['date_created'] ?? 'now'));
        $this->repo->registrarPago($idNegocio, $mpId, (float) ($pago['transaction_amount'] ?? 0), $estadoPago, $fecha, $pago);

        if ($estadoPago !== 'approved' || $yaAprobado) {
            if ($estadoPago !== 'approved') {
                $this->auditoria->registrar('pago_mensual', ['mp_id' => $mpId, 'estado' => $estadoPago], $idNegocio);
            }
            return;
        }
        if ((float) ($pago['transaction_amount'] ?? 0) + 0.01 < (float) $plan['precio_mensual']) {
            error_log('MP: pago mensual ' . $mpId . ' por menos que el precio del plan');
            return;
        }

        $ahora = new \DateTimeImmutable();
        $desde = $ahora;
        foreach ([$s['periodo_hasta'] ?? null, ($s['estado'] ?? '') === 'prueba' ? ($s['prueba_hasta'] ?? null) : null] as $fechaHasta) {
            if ($fechaHasta && new \DateTimeImmutable($fechaHasta) > $desde) {
                $desde = new \DateTimeImmutable($fechaHasta);
            }
        }
        $hasta = $desde->modify('+1 month');
        $this->repo->actualizar($idNegocio, [
            'estado' => 'activa',
            'modo_pago' => 'mensual',
            'id_plan' => (int) $plan['id'],
            'id_plan_pendiente' => null,
            'periodo_hasta' => $hasta->format('Y-m-d H:i:s'),
            'en_mora_desde' => null,
            'cancelada_en' => null,
            'exento_limites' => 0,
            'aviso_vencimiento' => 0,
        ]);
        $this->auditoria->registrar('pago_mensual', ['mp_id' => $mpId, 'estado' => 'approved', 'plan' => $codigoPlan, 'hasta' => $hasta->format('Y-m-d')], $idNegocio);
        $this->avisarTitular($idNegocio, 'Recibimos tu pago de Kontia',
            'Gracias. Tu plan ' . $plan['nombre'] . ' queda pago hasta el ' . $hasta->format('d/m/Y') . '. Te vamos a avisar unos días antes de que venza.');
    }

    /**
     * Pagos mensuales: avisa 3 días antes del vencimiento y el día que vence.
     */
    public function avisarPagosMensuales(int $dias = 3): int
    {
        $n = 0;
        foreach ($this->repo->mensualesPorVencer($dias) as $fila) {
            $this->mail->enviarAviso($fila['email'], 'Tu plan de Kontia vence pronto',
                'Tu plan está pago hasta el ' . date('d/m/Y', strtotime($fila['periodo_hasta']))
                . '. Pagá el próximo mes desde Mi plan para seguir sin cortes. Si pagás antes, no perdés ningún día.', 'Pagar el próximo mes');
            $this->repo->marcarAvisoVencimiento((int) $fila['id_negocio'], 1);
            $n++;
        }
        foreach ($this->repo->mensualesVencidos() as $fila) {
            $this->mail->enviarAviso($fila['email'], 'Tu plan de Kontia venció',
                'Tu plan venció el ' . date('d/m/Y', strtotime($fila['periodo_hasta'])) . '. Tenés '
                . Entorno::int('DIAS_GRACIA_MORA', 7) . ' días para pagar el próximo mes antes de que la Caja quede en solo lectura. Tus datos quedan guardados.', 'Pagar el próximo mes');
            $this->repo->marcarAvisoVencimiento((int) $fila['id_negocio'], 2);
            $n++;
        }
        return $n;
    }

    /**
     * Revisa contra Mercado Pago todas las suscripciones con débito (por si se perdió
     * un webhook). Devuelve cuántas revisó.
     */
    public function conciliar(): int
    {
        $n = 0;
        foreach ($this->repo->conMercadoPago() as $s) {
            try {
                $this->aplicarSuscripcion($this->mp->obtenerSuscripcion($s['mp_preapproval_id']));
                $n++;
            } catch (\Throwable $th) {
                error_log('MP conciliación ' . $s['id_negocio'] . ': ' . $th->getMessage());
            }
        }
        // Pagos mensuales de los últimos días, por si se perdió algún aviso.
        try {
            foreach ($this->mp->pagosRecientes(3) as $pago) {
                if (MercadoPagoClient::leerReferenciaMensual($pago['external_reference'] ?? null)) {
                    $this->aplicarPagoMensual($pago);
                    $n++;
                }
            }
        } catch (\Throwable $th) {
            error_log('MP conciliación de pagos mensuales: ' . $th->getMessage());
        }
        return $n;
    }

    public function avisarPruebasPorVencer(int $dias = 3): int
    {
        $n = 0;
        foreach ($this->repo->pruebasPorVencer($dias) as $fila) {
            $this->mail->enviarAviso($fila['email'], 'Tu prueba de Kontia termina pronto',
                'Tu prueba gratis termina el ' . date('d/m/Y', strtotime($fila['prueba_hasta']))
                . '. Elegí un plan para seguir vendiendo sin cortes. Tus datos quedan guardados.', 'Elegir un plan');
            $this->repo->marcarAvisoPrueba((int) $fila['id_negocio']);
            $n++;
        }
        return $n;
    }

    private function avisarTitular(int $idNegocio, string $asunto, string $mensaje): void
    {
        $email = $this->repo->emailTitular($idNegocio);
        if ($email) {
            $this->mail->enviarAviso($email, $asunto, $mensaje);
        }
    }
}
