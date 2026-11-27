<?php

namespace App\Tests\Functional;

use App\Service\PasskeyService;
use App\Tests\KontiaTestCase;

/**
 * Ingreso con Face ID / huella (llaves de acceso). Se simula el autenticador
 * del teléfono con una clave EC P-256 generada en el test.
 */
class PasskeyTest extends KontiaTestCase
{
    private const ORIGEN = 'http://localhost';

    private \OpenSSLAsymmetricKey $clave;
    private string $credencial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1);
        $this->crearUsuario('titular@test.com', 1);
        $this->clave = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credencial = random_bytes(16);
    }

    private function pedirDesde(string $metodo, string $url, ?array $body, ?string $token = null, string $origen = self::ORIGEN): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => $origen];
        if ($token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($metodo, $url, [], [], $headers, $body !== null ? json_encode($body) : null);
        $r = $this->client->getResponse();
        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    // ---- CBOR mínimo para armar lo que manda el autenticador ----
    private static function cborCabecera(int $tipo, int $n): string
    {
        if ($n < 24) {
            return chr(($tipo << 5) | $n);
        }
        if ($n < 256) {
            return chr(($tipo << 5) | 24) . chr($n);
        }
        return chr(($tipo << 5) | 25) . pack('n', $n);
    }

    private static function cbor(mixed $v): string
    {
        if (is_int($v)) {
            return $v >= 0 ? self::cborCabecera(0, $v) : self::cborCabecera(1, -1 - $v);
        }
        if (is_array($v) && array_key_exists('bytes', $v)) {
            return self::cborCabecera(2, strlen($v['bytes'])) . $v['bytes'];
        }
        if (is_string($v)) {
            return self::cborCabecera(3, strlen($v)) . $v;
        }
        $s = self::cborCabecera(5, count($v['mapa']));
        foreach ($v['mapa'] as [$k, $valor]) {
            $s .= self::cbor($k) . self::cbor($valor);
        }
        return $s;
    }

    private function datosAutenticador(bool $conCredencial, int $contador): string
    {
        $datos = hash('sha256', 'localhost', true) . chr($conCredencial ? 0x45 : 0x05) . pack('N', $contador);
        if ($conCredencial) {
            $ec = openssl_pkey_get_details($this->clave)['ec'];
            $cose = self::cbor(['mapa' => [[1, 2], [3, -7], [-1, 1], [-2, ['bytes' => $ec['x']]], [-3, ['bytes' => $ec['y']]]]]);
            $datos .= str_repeat("\0", 16) . pack('n', strlen($this->credencial)) . $this->credencial . $cose;
        }
        return $datos;
    }

    private static function clientData(string $tipo, string $desafio): string
    {
        return json_encode(['type' => $tipo, 'challenge' => $desafio, 'origin' => self::ORIGEN]);
    }

    private function activar(string $token): void
    {
        [$estado, $r] = $this->pedirDesde('POST', '/api/passkey/opciones', [], $token);
        $this->assertSame(200, $estado);
        $this->assertSame('localhost', $r['datos']['publicKey']['rp']['id']);
        $this->assertSame('required', $r['datos']['publicKey']['authenticatorSelection']['userVerification']);
        $atestacion = self::cbor(['mapa' => [['fmt', 'none'], ['attStmt', ['mapa' => []]], ['authData', ['bytes' => $this->datosAutenticador(true, 0)]]]]);
        [$estado] = $this->pedirDesde('POST', '/api/passkey', [
            'desafio' => $r['datos']['desafio'],
            'clientDataJSON' => PasskeyService::aBase64Url(self::clientData('webauthn.create', $r['datos']['publicKey']['challenge'])),
            'attestationObject' => PasskeyService::aBase64Url($atestacion),
            'nombre' => 'iPhone',
        ], $token);
        $this->assertSame(201, $estado);
    }

    /** @return array{0:int,1:?array} */
    private function ingresar(int $contador = 0, ?callable $alterar = null): array
    {
        [, $r] = $this->pedirDesde('POST', '/api/publico/passkey/opciones', []);
        $datos = $this->datosAutenticador(false, $contador);
        $cliente = self::clientData('webauthn.get', $r['datos']['publicKey']['challenge']);
        openssl_sign($datos . hash('sha256', $cliente, true), $firma, $this->clave, OPENSSL_ALGO_SHA256);
        $body = [
            'desafio' => $r['datos']['desafio'],
            'id' => PasskeyService::aBase64Url($this->credencial),
            'clientDataJSON' => PasskeyService::aBase64Url($cliente),
            'authenticatorData' => PasskeyService::aBase64Url($datos),
            'signature' => PasskeyService::aBase64Url($firma),
        ];
        if ($alterar) {
            $body = $alterar($body);
        }
        return $this->pedirDesde('POST', '/api/publico/passkey/ingresar', $body);
    }

    public function testActivarEIngresarSinContrasena(): void
    {
        $this->activar($this->tokenPara('titular@test.com'));

        [$estado, $r] = $this->ingresar();
        $this->assertSame(200, $estado);
        $this->assertNotEmpty($r['token']);
        $this->assertSame(1, (int) $r['id_negocio']);

        [$estado, $r] = $this->pedirDesde('GET', '/api/passkey', null, $this->tokenPara('titular@test.com'));
        $this->assertSame('iPhone', $r['datos'][0]['nombre']);
        $this->assertNotNull($r['datos'][0]['usada']);
    }

    public function testRechazaFirmaFalsaDesafioRepetidoYLlaveBorrada(): void
    {
        $token = $this->tokenPara('titular@test.com');
        $this->activar($token);

        // Firma de otra clave.
        [$estado] = $this->ingresar(0, function (array $b) {
            $otra = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            openssl_sign('cualquier cosa', $firma, $otra, OPENSSL_ALGO_SHA256);
            $b['signature'] = PasskeyService::aBase64Url($firma);
            return $b;
        });
        $this->assertSame(401, $estado);

        // Desafío inventado.
        [$estado] = $this->ingresar(0, fn (array $b) => ['desafio' => str_repeat('a', 32)] + $b);
        $this->assertSame(401, $estado);

        // Origen que no es el nuestro.
        [$estado] = $this->pedirDesde('POST', '/api/publico/passkey/opciones', [], null, 'https://otro-sitio.com');
        $this->assertSame(409, $estado);

        // Se borra la llave: ya no entra.
        [, $r] = $this->pedirDesde('GET', '/api/passkey', null, $token);
        [$estado] = $this->pedirDesde('DELETE', '/api/passkey/' . $r['datos'][0]['id'], null, $token);
        $this->assertSame(200, $estado);
        [$estado] = $this->ingresar();
        $this->assertSame(401, $estado);
    }

    public function testUsuarioDadoDeBajaNoEntra(): void
    {
        $this->activar($this->tokenPara('titular@test.com'));
        $this->pdo()->exec("UPDATE user SET eliminado = 1 WHERE email = 'titular@test.com'");
        [$estado] = $this->ingresar();
        $this->assertSame(401, $estado);
    }
}
