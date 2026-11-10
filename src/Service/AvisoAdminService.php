<?php

namespace App\Service;

use App\Repository\BaseRepository;

/**
 * Mail a los dueños de Kontia (ADMIN_EMAILS, separados por coma) cuando pasa
 * algo con las suscripciones: alta de un negocio, plan activado o cambiado,
 * cobro (aprobado o rechazado) y cancelación. Lo dispara AuditoriaService al
 * registrar esas acciones. Si no hay ADMIN_EMAILS no hace nada, y si el mail
 * falla solo se registra en el log: nunca corta la operación.
 */
class AvisoAdminService
{
    /** Acción de auditoría => asunto del mail. */
    public const ACCIONES = [
        'registro' => 'Nuevo negocio registrado',
        'suscripcion_activada' => 'Suscripción activada',
        'cambio_plan' => 'Cambio de plan',
        'pago_suscripcion' => 'Cobro de suscripción',
        'cancelacion_plan' => 'Pidieron cancelar el plan',
        'suscripcion_cancelada' => 'Suscripción cancelada',
    ];

    private MailService $mail;
    private BaseRepository $repo;

    public function __construct(MailService $mail, BaseRepository $repo)
    {
        $this->mail = $mail;
        $this->repo = $repo;
    }

    public function notificar(string $accion, array $detalle, ?int $idNegocio): void
    {
        if (!isset(self::ACCIONES[$accion])) {
            return;
        }
        $destinatarios = Entorno::lista('ADMIN_EMAILS');
        if (!$destinatarios) {
            return;
        }
        try {
            [$asunto, $filas] = $this->armar($accion, $detalle, $idNegocio ? $this->datosNegocio($idNegocio) : []);
            $this->mail->enviarAdmin($destinatarios, $asunto, $filas);
        } catch (\Throwable $th) {
            error_log('Aviso admin: ' . $accion . ': ' . $th->getMessage());
        }
    }

    /**
     * @param array{nombre?: string, plan?: string, email?: string, id_negocio?: int} $negocio
     * @return array{0: string, 1: array<string, string>}
     */
    public function armar(string $accion, array $detalle, array $negocio): array
    {
        $asunto = self::ACCIONES[$accion];
        if ($accion === 'pago_suscripcion') {
            $estado = (string) ($detalle['estado'] ?? '');
            $asunto = $estado === 'approved' ? 'Cobro aprobado' : 'Cobro ' . ($estado === 'rejected' ? 'rechazado' : $estado);
        }
        $nombre = trim((string) ($negocio['nombre'] ?? ''));
        if ($nombre !== '') {
            $asunto .= ': ' . $nombre;
        }

        $filas = [];
        if ($nombre !== '') {
            $filas['Negocio'] = $nombre;
        }
        if (!empty($negocio['id_negocio'])) {
            $filas['N° de negocio'] = (string) $negocio['id_negocio'];
        }
        if (!empty($negocio['email'])) {
            $filas['Titular'] = (string) $negocio['email'];
        } elseif (!empty($detalle['email'])) {
            $filas['Email'] = (string) $detalle['email'];
        }
        if (!empty($detalle['plan'])) {
            $filas['Plan'] = ucfirst((string) $detalle['plan']);
        } elseif (!empty($negocio['plan'])) {
            $filas['Plan'] = (string) $negocio['plan'];
        }
        if (!empty($detalle['via'])) {
            $filas['Se registró con'] = $detalle['via'] === 'google' ? 'Google' : 'Email';
        }
        if (!empty($detalle['estado'])) {
            $filas['Estado del cobro'] = (string) $detalle['estado'];
        }
        if (!empty($detalle['mp_id'])) {
            $filas['ID en Mercado Pago'] = (string) $detalle['mp_id'];
        }
        $filas['Fecha'] = (new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('d/m/Y H:i');
        return [$asunto, $filas];
    }

    private function datosNegocio(int $idNegocio): array
    {
        $stmt = $this->repo->get_bbdd()->prepare(
            'SELECT n.nombre, p.nombre AS plan,
                    (SELECT u.email FROM user u WHERE u.id_negocio = n.id_negocio AND u.role LIKE \'%ROLE_TITULAR%\' AND (u.eliminado = 0 OR u.eliminado IS NULL) ORDER BY u.id LIMIT 1) AS email
             FROM negocio n
             LEFT JOIN suscripcion s ON s.id_negocio = n.id_negocio
             LEFT JOIN plan_suscripcion p ON p.id = s.id_plan
             WHERE n.id_negocio = :n
             ORDER BY n.sucursal
             LIMIT 1'
        );
        $stmt->execute([':n' => $idNegocio]);
        $fila = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $fila['id_negocio'] = $idNegocio;
        return $fila;
    }
}
