<?php

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Mails transaccionales: verificación de cuenta, recuperación de clave y avisos
 * de la suscripción. Si el envío falla se registra y se sigue: nunca rompe el
 * flujo que lo llamó.
 */
class MailService
{
    private MailerInterface $mailer;

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
    }

    private function urlFront(string $ruta): string
    {
        return rtrim(Entorno::get('APP_URL_FRONT', 'http://localhost:8100'), '/') . $ruta;
    }

    public function enviarVerificacion(string $email, string $nombre, string $token): bool
    {
        $link = $this->urlFront('/verificar-email?token=' . urlencode($token));
        return $this->enviar($email, 'Confirmá tu cuenta de Kontia', $this->plantilla(
            'Hola ' . $nombre . ',',
            'Gracias por registrarte en Kontia. Confirmá tu email para empezar a usar tu cuenta.',
            'Confirmar mi email',
            $link
        ));
    }

    public function enviarRestablecerClave(string $email, string $nombre, string $token): bool
    {
        $link = $this->urlFront('/restablecer-clave?token=' . urlencode($token));
        return $this->enviar($email, 'Restablecé tu clave de Kontia', $this->plantilla(
            'Hola ' . $nombre . ',',
            'Pediste cambiar tu clave. El link vale por 1 hora. Si no fuiste vos, ignorá este mail.',
            'Elegir una clave nueva',
            $link
        ));
    }

    /** Bienvenida al confirmar el email (o al crear la cuenta con Google). */
    public function enviarBienvenida(string $email, string $nombre): bool
    {
        $dias = Entorno::int('DIAS_PRUEBA', 7);
        return $this->enviar($email, 'Bienvenido a Kontia', $this->plantilla(
            'Hola ' . ($nombre !== '' ? $nombre : '') . ',',
            'Tu cuenta ya está lista y tenés ' . $dias . ' días para probar Kontia gratis, sin tarjeta. '
            . 'Para arrancar: 1) cargá o importá tus productos desde Excel, 2) hacé tu primera venta en Caja, '
            . '3) sumá a tus empleados y 4) si querés, activá tu catálogo online para recibir pedidos por WhatsApp. '
            . 'En Inicio vas a ver estos pasos para seguirlos de a uno.',
            'Entrar a Kontia',
            $this->urlFront('/index')
        ));
    }

    /** Confirmación al cliente de un pedido de arrepentimiento o de baja hecho desde la web. */
    public function enviarConfirmacionSolicitud(string $email, string $tipo, string $codigo): bool
    {
        $que = $tipo === 'arrepentimiento' ? 'arrepentimiento (revocación de la contratación)' : 'baja del servicio';
        return $this->enviar($email, 'Recibimos tu pedido de ' . ($tipo === 'arrepentimiento' ? 'arrepentimiento' : 'baja'), $this->plantilla(
            'Hola,',
            'Recibimos tu pedido de ' . $que . '. Tu número de trámite es ' . $codigo . '. '
            . 'Lo vamos a procesar dentro de las 24 horas hábiles y te avisamos por este medio. '
            . 'Si tenías débito automático en Mercado Pago, lo damos de baja y no se te va a volver a cobrar.',
            'Ir a Kontia',
            $this->urlFront('/login')
        ));
    }

    public function enviarAviso(string $email, string $asunto, string $mensaje, string $boton = 'Ver mi plan'): bool
    {
        return $this->enviar($email, $asunto, $this->plantilla('Hola,', $mensaje, $boton, $this->urlFront('/mi-plan')));
    }

    /**
     * Aviso interno para los dueños de Kontia (ver AvisoAdminService).
     *
     * @param string[] $para
     * @param array<string, string> $filas
     */
    public function enviarAdmin(array $para, string $asunto, array $filas): bool
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $tabla = '';
        foreach ($filas as $clave => $valor) {
            $tabla .= '<tr><td style="padding:4px 12px 4px 0;color:#777">' . $e($clave) . '</td>'
                . '<td style="padding:4px 0"><strong>' . $e($valor) . '</strong></td></tr>';
        }
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#222">'
            . '<h2 style="color:#2a8253">Kontia</h2>'
            . '<p>' . $e($asunto) . '</p>'
            . '<table style="border-collapse:collapse;font-size:14px">' . $tabla . '</table>'
            . '</div>';
        return $this->enviar($para, '[Kontia] ' . $asunto, $html);
    }

    /** @param string|string[] $para */
    private function enviar(string|array $para, string $asunto, string $html): bool
    {
        $destino = is_array($para) ? implode(', ', $para) : $para;
        try {
            $email = (new Email())
                ->from(Address::create(Entorno::get('MAIL_FROM', 'Kontia <no-responder@ventas-xpress.com.ar>')))
                ->to(...(array) $para)
                ->subject($asunto)
                ->html($html)
                ->text(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
            $this->mailer->send($email);
            return true;
        } catch (\Throwable $th) {
            error_log('Mail: no se pudo enviar "' . $asunto . '" a ' . $destino . ': ' . $th->getMessage());
            return false;
        }
    }

    private function plantilla(string $saludo, string $texto, string $boton, string $link): string
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#222">'
            . '<h2 style="color:#2a8253">Kontia</h2>'
            . '<p>' . $e($saludo) . '</p><p>' . $e($texto) . '</p>'
            . '<p><a href="' . $e($link) . '" style="display:inline-block;background:#2a8253;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none">' . $e($boton) . '</a></p>'
            . '<p style="font-size:12px;color:#777">Si el botón no funciona, copiá este link: ' . $e($link) . '</p>'
            . $this->pie()
            . '</div>';
    }

    /** Pie con los datos de soporte (SOPORTE_EMAIL / SOPORTE_WHATSAPP). */
    private function pie(): string
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $partes = [];
        if ($mail = Entorno::get('SOPORTE_EMAIL')) {
            $partes[] = 'escribinos a ' . $e($mail);
        }
        if ($wa = preg_replace('/\D/', '', Entorno::get('SOPORTE_WHATSAPP'))) {
            $partes[] = 'por WhatsApp al <a href="https://wa.me/' . $e($wa) . '" style="color:#2a8253">+' . $e($wa) . '</a>';
        }
        if (!$partes) {
            return '';
        }
        return '<p style="font-size:12px;color:#777;border-top:1px solid #eee;padding-top:8px">¿Necesitás ayuda? '
            . implode(' o ', $partes) . '.</p>';
    }
}
