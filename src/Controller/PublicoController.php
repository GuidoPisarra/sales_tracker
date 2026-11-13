<?php

namespace App\Controller;

use App\Repository\AdminRepository;
use App\Service\AuditoriaService;
use App\Service\Entorno;
use App\Service\MailService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Datos públicos del servicio (titular, soporte) para las páginas de Términos,
 * Privacidad y Ayuda, y los botones de arrepentimiento y de baja que exige
 * Defensa del Consumidor: se usan sin iniciar sesión.
 */
class PublicoController extends BaseController
{
    public const TIPOS_SOLICITUD = ['arrepentimiento', 'baja'];

    #[Route('/api/publico/info', name: 'app_publico_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        $wa = preg_replace('/\D/', '', Entorno::get('SOPORTE_WHATSAPP'));
        return $this->respuesta(200, [
            'legal' => [
                'titular' => Entorno::get('LEGAL_TITULAR', 'Vipi Labs'),
                'cuit' => Entorno::get('LEGAL_CUIT'),
                'provincia' => Entorno::get('LEGAL_PROVINCIA', 'Buenos Aires'),
                'email' => Entorno::get('SOPORTE_EMAIL'),
            ],
            'soporte' => [
                'email' => Entorno::get('SOPORTE_EMAIL') ?: null,
                'whatsapp' => $wa ?: null,
            ],
            'dias_prueba' => Entorno::int('DIAS_PRUEBA', 7),
        ], []);
    }

    #[Route('/api/publico/solicitud', name: 'app_publico_solicitud', methods: ['POST'])]
    public function solicitud(Request $request, AdminRepository $repo, AuditoriaService $auditoria, MailService $mail, RateLimiterFactory $solicitudPublicaLimiter): JsonResponse
    {
        if (!$solicitudPublicaLimiter->create($request->getClientIp() ?? 'sin-ip')->consume()->isAccepted()) {
            return $this->respuesta(429, [], ['Demasiados pedidos. Probá de nuevo en un rato.'], 429);
        }
        $d = json_decode($request->getContent(), true) ?: [];
        $tipo = (string) ($d['tipo'] ?? '');
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (!in_array($tipo, self::TIPOS_SOLICITUD, true)) {
            return $this->respuesta(400, [], ['Elegí si es un arrepentimiento o una baja.'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respuesta(400, [], ['Escribí el email con el que usás Kontia.'], 400);
        }
        $codigo = strtoupper(substr($tipo, 0, 1)) . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $idNegocio = $repo->idNegocioPorEmailTitular($email);
        $auditoria->registrar('solicitud_' . $tipo, [
            'codigo' => $codigo,
            'email_contacto' => $email,
            'nombre_comercio' => mb_substr(trim((string) ($d['nombre_comercio'] ?? '')), 0, 150),
            'motivo' => mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 1000),
            'cuenta_encontrada' => $idNegocio !== null,
        ], $idNegocio);
        $mail->enviarConfirmacionSolicitud($email, $tipo, $codigo);
        return $this->respuesta(201, ['codigo' => $codigo], [], 201);
    }
}
