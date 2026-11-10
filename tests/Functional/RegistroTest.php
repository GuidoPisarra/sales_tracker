<?php

namespace App\Tests\Functional;

use App\Service\GoogleAuthService;
use App\Tests\KontiaTestCase;

class RegistroTest extends KontiaTestCase
{
    private function datosRegistro(array $cambios = []): array
    {
        return $cambios + [
            'nombre_comercio' => 'Kiosco Pepe',
            'nombre' => 'Pepe',
            'email' => 'pepe@test.com',
            'password' => 'Clave1234',
            'acepta_terminos' => true,
        ];
    }

    private function login(string $email, string $clave): array
    {
        return $this->pedir('POST', '/api/usuario/login', ['username' => $email, 'password' => $clave]);
    }

    public function testRegistroCreaNegocioTitularYPrueba(): void
    {
        $this->crearNegocio(7);
        [$codigo, $json] = $this->pedir('POST', '/api/registro', $this->datosRegistro());
        $this->assertSame(201, $codigo, json_encode($json));

        $u = $this->pdo()->query("SELECT * FROM user WHERE email = 'pepe@test.com'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(8, (int) $u['id_negocio'], 'Toma el siguiente id_negocio libre');
        $this->assertSame('["ROLE_TITULAR"]', $u['role']);
        $this->assertSame(0, (int) $u['email_verificado']);
        $this->assertNotSame('Clave1234', $u['password']);

        $s = $this->pdo()->query('SELECT s.*, p.codigo FROM suscripcion s JOIN plan_suscripcion p ON p.id = s.id_plan WHERE id_negocio = 8')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('prueba', $s['estado']);
        $this->assertSame('pro', $s['codigo']);
        $this->assertGreaterThan(new \DateTime('+13 days'), new \DateTime($s['prueba_hasta']));

        $this->assertSame('Kiosco Pepe', $this->pdo()->query('SELECT nombre FROM negocio WHERE id_negocio = 8')->fetchColumn());
    }

    public function testAvisaPorMailALosAdministradoresDeKontia(): void
    {
        $_ENV['ADMIN_EMAILS'] = 'admin1@test.com, admin2@test.com';
        try {
            [$codigo] = $this->pedir('POST', '/api/registro', $this->datosRegistro());
            $this->assertSame(201, $codigo);
            $avisos = array_values(array_filter(
                $this->getMailerMessages(),
                fn ($m) => str_starts_with((string) $m->getSubject(), '[Kontia] Nuevo negocio registrado')
            ));
            $this->assertCount(1, $avisos);
            $this->assertSame('[Kontia] Nuevo negocio registrado: Kiosco Pepe', $avisos[0]->getSubject());
            $this->assertSame(['admin1@test.com', 'admin2@test.com'], array_map(fn ($a) => $a->getAddress(), $avisos[0]->getTo()));
            $this->assertStringContainsString('pepe@test.com', (string) $avisos[0]->getHtmlBody());
        } finally {
            unset($_ENV['ADMIN_EMAILS']);
        }
    }

    public function testSinAdminEmailsNoSeMandaAviso(): void
    {
        unset($_ENV['ADMIN_EMAILS']);
        $this->pedir('POST', '/api/registro', $this->datosRegistro());
        foreach ($this->getMailerMessages() as $m) {
            $this->assertStringStartsNotWith('[Kontia]', (string) $m->getSubject());
        }
    }

    public function testAsuntoDelAvisoDeCobro(): void
    {
        $aviso = static::getContainer()->get(\App\Service\AvisoAdminService::class);
        [$asunto, $filas] = $aviso->armar('pago_suscripcion', ['estado' => 'rejected', 'mp_id' => 'X1'], ['nombre' => 'Kiosco', 'id_negocio' => 3]);
        $this->assertSame('Cobro rechazado: Kiosco', $asunto);
        $this->assertSame('X1', $filas['ID en Mercado Pago']);
        [$asunto] = $aviso->armar('pago_suscripcion', ['estado' => 'approved'], []);
        $this->assertSame('Cobro aprobado', $asunto);
    }

    public function testNoEntraHastaVerificarElEmail(): void
    {
        $this->pedir('POST', '/api/registro', $this->datosRegistro());
        [$codigo, $json] = $this->login('pepe@test.com', 'Clave1234');
        $this->assertSame(401, $codigo);
        $this->assertStringContainsString('Confirmá tu email', json_encode($json, JSON_UNESCAPED_UNICODE));

        $token = $this->pdo()->query("SELECT token_verificacion FROM user WHERE email = 'pepe@test.com'")->fetchColumn();
        [$codigo] = $this->pedir('POST', '/api/registro/verificar', ['token' => $token]);
        $this->assertSame(200, $codigo);
        [$codigo] = $this->pedir('POST', '/api/registro/verificar', ['token' => $token]);
        $this->assertSame(400, $codigo, 'El token no se puede usar dos veces');

        [$codigo, $json] = $this->login('pepe@test.com', 'Clave1234');
        $this->assertSame(200, $codigo, json_encode($json));
        $this->assertNotEmpty($json['token']);
        $this->assertSame('pro', $json['suscripcion']['plan']['codigo']);
    }

    public function testValidacionesYEmailRepetido(): void
    {
        [$codigo] = $this->pedir('POST', '/api/registro', $this->datosRegistro(['password' => 'corta']));
        $this->assertSame(400, $codigo);
        [$codigo] = $this->pedir('POST', '/api/registro', $this->datosRegistro(['acepta_terminos' => false]));
        $this->assertSame(400, $codigo);

        $this->crearNegocio(1);
        $this->crearUsuario('pepe@test.com', 1);
        [$codigo] = $this->pedir('POST', '/api/registro', $this->datosRegistro());
        $this->assertSame(409, $codigo);
    }

    public function testRestablecerClave(): void
    {
        $this->crearNegocio(1);
        $this->crearUsuario('ana@test.com', 1);
        [$codigo] = $this->pedir('POST', '/api/usuario/olvide-clave', ['email' => 'ana@test.com']);
        $this->assertSame(200, $codigo);
        [$codigo] = $this->pedir('POST', '/api/usuario/olvide-clave', ['email' => 'nadie@test.com']);
        $this->assertSame(200, $codigo, 'No revela si el email existe');

        // En la base queda solo el hash; el test simula el link del mail con un token conocido.
        $token = str_repeat('a', 64);
        $this->pdo()->exec("UPDATE user SET token_reset = '" . hash('sha256', $token) . "', token_reset_expira = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = 'ana@test.com'");
        [$codigo] = $this->pedir('POST', '/api/usuario/restablecer-clave', ['token' => $token, 'password' => 'NuevaClave99']);
        $this->assertSame(200, $codigo);
        [$codigo] = $this->login('ana@test.com', 'NuevaClave99');
        $this->assertSame(200, $codigo);
        [$codigo] = $this->pedir('POST', '/api/usuario/restablecer-clave', ['token' => $token, 'password' => 'OtraClave99']);
        $this->assertSame(400, $codigo, 'El link se usa una sola vez');
    }

    public function testEmpleadoDadoDeBajaNoPuedeUsarSuToken(): void
    {
        $this->crearNegocio(1);
        $id = $this->crearUsuario('emp@test.com', 1, false);
        $token = $this->tokenPara('emp@test.com');
        $this->pdo()->exec("UPDATE user SET eliminado = 1 WHERE id = $id");
        [$codigo] = $this->pedir('GET', '/api/products/products_list', null, $token);
        $this->assertSame(401, $codigo);
    }

    private function simularGoogle(?array $datos): void
    {
        $falso = new class($datos) extends GoogleAuthService {
            public function __construct(private ?array $datos) {}
            public function configurado(): bool { return true; }
            public function verificar(string $idToken): ?array { return $this->datos; }
        };
        static::getContainer()->set(GoogleAuthService::class, $falso);
    }

    public function testGoogleSinCuentaPideDatosYDespuesLaCrea(): void
    {
        $this->simularGoogle(['sub' => 'g-123', 'email' => 'nuevo@gmail.com', 'nombre' => 'Nuevo']);
        [$codigo, $json] = $this->pedir('POST', '/api/usuario/google', ['id_token' => 'x']);
        $this->assertSame(404, $codigo);
        $this->assertTrue($json['datos']['requiere_registro']);

        [$codigo, $json] = $this->pedir('POST', '/api/usuario/google', ['id_token' => 'x', 'nombre_comercio' => 'Almacén', 'acepta_terminos' => true]);
        $this->assertSame(200, $codigo, json_encode($json));
        $this->assertNotEmpty($json['token']);
        $u = $this->pdo()->query("SELECT google_id, email_verificado FROM user WHERE email = 'nuevo@gmail.com'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('g-123', $u['google_id']);
        $this->assertSame(1, (int) $u['email_verificado']);
    }

    public function testGoogleConEmailExistenteVinculaYEntra(): void
    {
        $this->crearNegocio(1);
        $this->crearUsuario('ana@gmail.com', 1, true, 'Clave1234', 1, false);
        $this->simularGoogle(['sub' => 'g-999', 'email' => 'ana@gmail.com', 'nombre' => 'Ana']);
        [$codigo, $json] = $this->pedir('POST', '/api/usuario/google', ['id_token' => 'x']);
        $this->assertSame(200, $codigo, json_encode($json));
        $this->assertSame(1, (int) $json['id_negocio']);
        $this->assertSame('g-999', $this->pdo()->query("SELECT google_id FROM user WHERE email = 'ana@gmail.com'")->fetchColumn());
    }

    public function testGoogleTokenInvalido(): void
    {
        $this->simularGoogle(null);
        [$codigo] = $this->pedir('POST', '/api/usuario/google', ['id_token' => 'x']);
        $this->assertSame(401, $codigo);
    }

    public function testValidacionDelTokenDeGoogle(): void
    {
        $g = new GoogleAuthService(new \Symfony\Component\HttpClient\MockHttpClient());
        $base = ['iss' => 'https://accounts.google.com', 'aud' => 'cliente-google-test', 'exp' => time() + 60, 'sub' => '1', 'email' => 'A@Gmail.com', 'email_verified' => 'true', 'name' => 'A'];
        $this->assertSame('a@gmail.com', $g->validarDatos($base)['email']);
        $this->assertNull($g->validarDatos(['aud' => 'otro-cliente'] + $base));
        $this->assertNull($g->validarDatos(['exp' => time() - 1] + $base));
        $this->assertNull($g->validarDatos(['email_verified' => 'false'] + $base));
        $this->assertNull($g->validarDatos(['iss' => 'malo.com'] + $base));
    }
}
