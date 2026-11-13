<?php

namespace App\Controller;

use App\Model\Usuario;
use App\Repository\AdminRepository;
use App\Repository\PlanRepository;
use App\Repository\SuscripcionRepository;
use App\Service\AuditoriaService;
use App\Service\CobrosService;
use App\Service\Entorno;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Panel de administración de Kontia (para los dueños del SaaS). Solo entra un
 * usuario logueado cuyo email esté en ADMIN_EMAILS: ve todos los negocios,
 * extiende pruebas, cambia planes y da de baja suscripciones.
 */
#[Route('/api/admin')]
class AdminController extends BaseController
{
    public static function esAdminKontia(?string $email): bool
    {
        if (!$email) {
            return false;
        }
        $admins = array_map('strtolower', Entorno::lista('ADMIN_EMAILS'));
        return in_array(strtolower(trim($email)), $admins, true);
    }

    private function soloAdmin(): ?JsonResponse
    {
        $u = $this->getUser();
        if ($u instanceof Usuario && self::esAdminKontia($u->get_email())) {
            return null;
        }
        return $this->respuesta(403, [], ['Solo los administradores de Kontia pueden entrar acá.'], 403);
    }

    private function body(Request $request): array
    {
        return json_decode($request->getContent(), true) ?: [];
    }

    #[Route('/resumen', name: 'app_admin_resumen', methods: ['GET'])]
    public function resumen(AdminRepository $repo): JsonResponse
    {
        if ($r = $this->soloAdmin()) {
            return $r;
        }
        return $this->respuesta(200, $repo->resumen(), []);
    }

    #[Route('/negocios', name: 'app_admin_negocios', methods: ['GET'])]
    public function negocios(Request $request, AdminRepository $repo): JsonResponse
    {
        if ($r = $this->soloAdmin()) {
            return $r;
        }
        $estado = (string) $request->query->get('estado', '');
        if ($estado !== '' && !in_array($estado, AdminRepository::ESTADOS, true)) {
            $estado = '';
        }
        return $this->respuesta(200, $repo->negocios(trim((string) $request->query->get('q', '')), $estado), []);
    }

    #[Route('/negocios/{id}', name: 'app_admin_negocio', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function negocio(int $id, AdminRepository $repo): JsonResponse
    {
        if ($r = $this->soloAdmin()) {
            return $r;
        }
        if (!$repo->existeNegocio($id)) {
            return $this->respuesta(404, [], ['No existe ese negocio.'], 404);
        }
        $fila = array_values(array_filter($repo->negocios((string) $id), fn ($f) => (int) $f['id_negocio'] === $id))[0] ?? null;
        return $this->respuesta(200, [
            'negocio' => $fila,
            'pagos' => $repo->pagos($id),
            'actividad' => $repo->actividad($id),
        ], []);
    }

    /** Suma días de prueba (desde hoy o desde el vencimiento, lo que sea más tarde). */
    #[Route('/negocios/{id}/prueba', name: 'app_admin_extender_prueba', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function extenderPrueba(int $id, Request $request, SuscripcionRepository $suscripciones, AuditoriaService $auditoria): JsonResponse
    {
        if ($r = $this->soloAdmin()) {
            return $r;
        }
        $dias = (int) ($this->body($request)['dias'] ?? 0);
        if ($dias < 1 || $dias > 365) {
            return $this->respuesta(400, [], ['Indicá entre 1 y 365 días.'], 400);
        }
        $s = $suscripciones->porNegocio($id);
        if (!$s) {
            return $this->respuesta(404, [], ['Ese negocio no tiene suscripción.'], 404);
        }
        $desde = new \DateTimeImmutable();
        if ($s['prueba_hasta'] && new \DateTimeImmutable($s['prueba_hasta']) > $desde) {
            $desde = new \DateTimeImmutable($s['prueba_hasta']);
        }
        $hasta = $desde->modify("+$dias days")->format('Y-m-d H:i:s');
        $suscripciones->actualizar($id, ['estado' => 'prueba', 'prueba_hasta' => $hasta, 'aviso_prueba_enviado' => 0]);
        $auditoria->registrar('admin_extender_prueba', ['dias' => $dias, 'hasta' => $hasta], $id);
        return $this->respuesta(200, ['prueba_hasta' => $hasta], []);
    }

    /**
     * Cambia el plan a mano (sin Mercado Pago). Con "sin_limites" queda como
     * cliente de por vida / cortesía: activo, sin vencimiento y sin límites.
     */
    #[Route('/negocios/{id}/plan', name: 'app_admin_plan', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cambiarPlan(int $id, Request $request, SuscripcionRepository $suscripciones, PlanRepository $planes, AuditoriaService $auditoria): JsonResponse
    {
        if ($r = $this->soloAdmin()) {
            return $r;
        }
        $d = $this->body($request);
        $plan = $planes->porCodigo((string) ($d['plan'] ?? ''));
        if (!$plan) {
            return $this->respuesta(400, [], ['Ese plan no existe.'], 400);
        }
        if (!$suscripciones->porNegocio($id)) {
            return $this->respuesta(404, [], ['Ese negocio no tiene suscripción.'], 404);
        }
        $sinLimites = !empty($d['sin_limites']);
        $campos = [
            'id_plan' => (int) $plan['id'],
            'estado' => 'activa',
            'exento_limites' => $sinLimites ? 1 : 0,
            'en_mora_desde' => null,
            'cancelada_en' => null,
        ];
        if (!empty($d['hasta'])) {
            $hasta = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $d['hasta']);
            if (!$hasta) {
                return $this->respuesta(400, [], ['La fecha tiene que ser AAAA-MM-DD.'], 400);
            }
            $campos['periodo_hasta'] = $hasta->format('Y-m-d 23:59:59');
        } elseif ($sinLimites) {
            $campos['periodo_hasta'] = null;
        }
        $suscripciones->actualizar($id, $campos);
        $auditoria->registrar('admin_cambio_plan', ['plan' => $plan['codigo'], 'sin_limites' => $sinLimites, 'hasta' => $campos['periodo_hasta'] ?? null], $id);
        return $this->respuesta(200, ['ok' => true], []);
    }

    /** Da de baja: cancela el débito en Mercado Pago si lo tiene y deja la suscripción cancelada. */
    #[Route('/negocios/{id}/cancelar', name: 'app_admin_cancelar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancelar(int $id, SuscripcionRepository $suscripciones, CobrosService $cobros, AuditoriaService $auditoria): JsonResponse
    {
        if ($r = $this->soloAdmin()) {
            return $r;
        }
        $s = $suscripciones->porNegocio($id);
        if (!$s) {
            return $this->respuesta(404, [], ['Ese negocio no tiene suscripción.'], 404);
        }
        try {
            if ($s['mp_preapproval_id'] && $s['estado'] !== 'cancelada') {
                $cobros->cancelar($id);
            } else {
                $suscripciones->actualizar($id, ['estado' => 'cancelada', 'cancelada_en' => date('Y-m-d H:i:s')]);
            }
        } catch (\Throwable $th) {
            error_log('Admin cancelar: ' . $th->getMessage());
            return $this->respuesta(502, [], ['No pudimos cancelar en Mercado Pago. Probá de nuevo o cancelalo desde tu cuenta de Mercado Pago.'], 502);
        }
        $auditoria->registrar('admin_cancelar', [], $id);
        return $this->respuesta(200, ['cancelada' => true], []);
    }

    #[Route('/solicitudes', name: 'app_admin_solicitudes', methods: ['GET'])]
    public function solicitudes(AdminRepository $repo): JsonResponse
    {
        if ($r = $this->soloAdmin()) {
            return $r;
        }
        return $this->respuesta(200, $repo->solicitudes(), []);
    }
}
