<?php

namespace App\Service;

use App\Repository\PasskeyRepository;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;

/**
 * Ingreso con Face ID / huella desde el navegador (llaves de acceso, WebAuthn).
 * Es lo que hace de "huella" en el iPhone, donde Kontia se usa como PWA.
 *
 * La llave queda atada al dominio desde el que se creó (rp_id = host del
 * front). Solo se aceptan los orígenes de CORS_ALLOW_ORIGIN, en https (o
 * localhost para desarrollo). Los datos binarios viajan en base64url.
 */
class PasskeyService
{
    public function __construct(private PasskeyRepository $repo)
    {
    }

    /** Host del front si el origen es uno de los nuestros, si no null. */
    public static function rpId(?string $origen): ?string
    {
        if (!$origen) {
            return null;
        }
        $patron = Entorno::get('CORS_ALLOW_ORIGIN');
        if ($patron === '' || @preg_match('{' . $patron . '}', $origen) !== 1) {
            return null;
        }
        $esquema = parse_url($origen, PHP_URL_SCHEME);
        $host = parse_url($origen, PHP_URL_HOST);
        if (!$host || ($esquema !== 'https' && $host !== 'localhost')) {
            return null;
        }
        return strtolower($host);
    }

    private function webauthn(string $rpId): WebAuthn
    {
        $w = new WebAuthn('Kontia', $rpId, ['none', 'packed', 'apple', 'android-key', 'android-safetynet', 'tpm', 'fido-u2f'], true);
        ByteBuffer::$useBase64UrlEncoding = true;
        return $w;
    }

    /** @return array{desafio: string, publicKey: object} */
    public function opcionesRegistro(int $idUsuario, string $email, string $nombre, string $rpId): array
    {
        $w = $this->webauthn($rpId);
        $excluir = array_map(fn ($c) => self::desdeBase64Url($c), $this->repo->credencialesDeUsuario($idUsuario, $rpId));
        // Llave "descubrible" (se entra sin escribir el email) y con verificación (Face ID/huella).
        $args = $w->getCreateArgs((string) $idUsuario, $email, $nombre ?: $email, 120, true, true, false, $excluir);
        $id = $this->repo->guardarDesafio($w->getChallenge()->getBinaryString(), $idUsuario, $rpId);
        return ['desafio' => $id, 'publicKey' => $args->publicKey];
    }

    /** Guarda la llave nueva. Lanza \RuntimeException con un mensaje para el usuario si no vale. */
    public function registrar(int $idUsuario, string $rpId, array $datos): int
    {
        $desafio = $this->repo->tomarDesafio((string) ($datos['desafio'] ?? ''), $idUsuario, $rpId);
        if ($desafio === null) {
            throw new \RuntimeException('Pasó mucho tiempo. Probá de nuevo.');
        }
        try {
            $r = $this->webauthn($rpId)->processCreate(
                self::desdeBase64Url((string) ($datos['clientDataJSON'] ?? '')),
                self::desdeBase64Url((string) ($datos['attestationObject'] ?? '')),
                new ByteBuffer($desafio),
                true, true, false
            );
        } catch (\Throwable $th) {
            error_log('Passkey registro: ' . $th->getMessage());
            throw new \RuntimeException('No se pudo activar el ingreso con Face ID o huella.');
        }
        $nombre = mb_substr(trim((string) ($datos['nombre'] ?? '')), 0, 100) ?: 'Este dispositivo';
        return $this->repo->guardar($idUsuario, $rpId, self::aBase64Url($r->credentialId), $r->credentialPublicKey, (int) ($r->signatureCounter ?? 0), $nombre);
    }

    /** @return array{desafio: string, publicKey: object} */
    public function opcionesIngreso(string $rpId): array
    {
        $w = $this->webauthn($rpId);
        $args = $w->getGetArgs([], 120, true, true, true, true, true, true);
        $id = $this->repo->guardarDesafio($w->getChallenge()->getBinaryString(), null, $rpId);
        return ['desafio' => $id, 'publicKey' => $args->publicKey];
    }

    /** Verifica la firma y devuelve el email del usuario. Lanza \RuntimeException si no vale. */
    public function ingresar(string $rpId, array $datos): string
    {
        $desafio = $this->repo->tomarDesafio((string) ($datos['desafio'] ?? ''), null, $rpId);
        if ($desafio === null) {
            throw new \RuntimeException('Pasó mucho tiempo. Probá de nuevo.');
        }
        $llave = $this->repo->porCredencial((string) ($datos['id'] ?? ''), $rpId);
        if ($llave === null) {
            throw new \RuntimeException('Esta llave ya no está activa. Ingresá con tu email y contraseña.');
        }
        $w = $this->webauthn($rpId);
        try {
            $w->processGet(
                self::desdeBase64Url((string) ($datos['clientDataJSON'] ?? '')),
                self::desdeBase64Url((string) ($datos['authenticatorData'] ?? '')),
                self::desdeBase64Url((string) ($datos['signature'] ?? '')),
                $llave['clave_publica'],
                new ByteBuffer($desafio),
                (int) $llave['contador'] ?: null,
                true
            );
        } catch (\Throwable $th) {
            error_log('Passkey ingreso: ' . $th->getMessage());
            throw new \RuntimeException('No se pudo verificar. Ingresá con tu email y contraseña.');
        }
        $this->repo->marcarUso((int) $llave['id'], (int) ($w->getSignatureCounter() ?? 0));
        return $llave['email'];
    }

    public static function desdeBase64Url(string $texto): string
    {
        return (string) base64_decode(strtr($texto, '-_', '+/') . str_repeat('=', (4 - strlen($texto) % 4) % 4));
    }

    public static function aBase64Url(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }
}
