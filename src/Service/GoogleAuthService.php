<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Valida el "ID token" que entrega Google Identity Services (botón "Continuar con
 * Google" en la web o el plugin nativo en Android) y devuelve los datos de la
 * persona. Se valida contra el endpoint tokeninfo de Google, que verifica la firma;
 * acá se controla además audiencia, emisor, vencimiento y email verificado.
 */
class GoogleAuthService
{
    private HttpClientInterface $http;

    public function __construct(HttpClientInterface $http)
    {
        $this->http = $http;
    }

    public function configurado(): bool
    {
        return Entorno::lista('GOOGLE_CLIENT_IDS') !== [];
    }

    /**
     * @return array{sub: string, email: string, nombre: string}|null
     */
    public function verificar(string $idToken): ?array
    {
        if (!$this->configurado() || $idToken === '') {
            return null;
        }
        try {
            $respuesta = $this->http->request('GET', 'https://oauth2.googleapis.com/tokeninfo', [
                'query' => ['id_token' => $idToken],
                'timeout' => 10,
            ]);
            if ($respuesta->getStatusCode() !== 200) {
                return null;
            }
            return $this->validarDatos($respuesta->toArray(false));
        } catch (\Throwable $th) {
            error_log('Google: no se pudo validar el token: ' . $th->getMessage());
            return null;
        }
    }

    /**
     * @return array{sub: string, email: string, nombre: string}|null
     */
    public function validarDatos(array $datos): ?array
    {
        $audiencias = Entorno::lista('GOOGLE_CLIENT_IDS');
        $emisorOk = in_array($datos['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
        $verificado = ($datos['email_verified'] ?? false) === true || ($datos['email_verified'] ?? '') === 'true';
        if (!$emisorOk
            || !in_array($datos['aud'] ?? '', $audiencias, true)
            || (int) ($datos['exp'] ?? 0) < time()
            || empty($datos['sub'])
            || empty($datos['email'])
            || !$verificado
        ) {
            return null;
        }
        return [
            'sub' => (string) $datos['sub'],
            'email' => strtolower((string) $datos['email']),
            'nombre' => (string) ($datos['name'] ?? $datos['given_name'] ?? strstr($datos['email'], '@', true)),
        ];
    }
}
