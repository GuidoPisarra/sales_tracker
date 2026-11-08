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

    public function enviarAviso(string $email, string $asunto, string $mensaje, string $boton = 'Ver mi plan'): bool
    {
        return $this->enviar($email, $asunto, $this->plantilla('Hola,', $mensaje, $boton, $this->urlFront('/mi-plan')));
    }

    private function enviar(string $para, string $asunto, string $html): bool
    {
        try {
            $email = (new Email())
                ->from(Address::create(Entorno::get('MAIL_FROM', 'Kontia <no-responder@ventas-xpress.com.ar>')))
                ->to($para)
                ->subject($asunto)
                ->html($html)
                ->text(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
            $this->mailer->send($email);
            return true;
        } catch (\Throwable $th) {
            error_log('Mail: no se pudo enviar "' . $asunto . '" a ' . $para . ': ' . $th->getMessage());
            return false;
        }
    }

    private function plantilla(string $saludo, string $texto, string $boton, string $link): string
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#222">'
            . '<h2 style="color:#3880ff">Kontia</h2>'
            . '<p>' . $e($saludo) . '</p><p>' . $e($texto) . '</p>'
            . '<p><a href="' . $e($link) . '" style="display:inline-block;background:#3880ff;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none">' . $e($boton) . '</a></p>'
            . '<p style="font-size:12px;color:#777">Si el botón no funciona, copiá este link: ' . $e($link) . '</p>'
            . '</div>';
    }
}
