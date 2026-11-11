<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifica el captcha de Cloudflare Turnstile del formulario de registro.
 * Sin TURNSTILE_SECRET configurado, el captcha queda desactivado.
 */
class CaptchaService
{
    private HttpClientInterface $http;

    public function __construct(HttpClientInterface $http)
    {
        $this->http = $http;
    }

    public function activo(): bool
    {
        return Entorno::get('TURNSTILE_SECRET') !== '';
    }

    public function verificar(?string $token, ?string $ip): bool
    {
        if (!$this->activo()) {
            return true;
        }
        if (!$token) {
            return false;
        }
        try {
            $respuesta = $this->http->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => ['secret' => Entorno::get('TURNSTILE_SECRET'), 'response' => $token, 'remoteip' => $ip],
                'timeout' => 10,
            ])->toArray(false);
            return !empty($respuesta['success']);
        } catch (\Throwable $th) {
            error_log('Captcha: ' . $th->getMessage());
            return false;
        }
    }
}
