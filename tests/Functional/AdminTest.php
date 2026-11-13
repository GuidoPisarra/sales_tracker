<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

class AdminTest extends KontiaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_EMAILS'] = 'Dueno@Kontia.test, otro@kontia.test';
        $this->crearNegocio(1, [1], 'fundador');
        $this->crearUsuario('dueno@kontia.test', 1);
        $this->crearNegocio(2, [1, 2], 'pro', 'prueba', date('Y-m-d H:i:s', strtotime('+2 days')));
        $this->crearUsuario('cliente@test.com', 2);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_EMAILS']);
        parent::tearDown();
    }

    public function testSoloEntranLosEmailsDeAdminEmails(): void
    {
        [$codigo] = $this->pedir('GET', '/api/admin/negocios', null, $this->tokenPara('cliente@test.com'));
        $this->assertSame(403, $codigo);
        [$codigo] = $this->pedir('GET', '/api/admin/negocios');
        $this->assertSame(401, $codigo);

        [$codigo, $json] = $this->pedir('GET', '/api/admin/negocios', null, $this->tokenPara('dueno@kontia.test'));
        $this->assertSame(200, $codigo);
        $this->assertCount(2, $json['datos']);
        $n2 = array_values(array_filter($json['datos'], fn ($f) => (int) $f['id_negocio'] === 2))[0];
        $this->assertSame('cliente@test.com', $n2['email_titular']);
        $this->assertSame(2, (int) $n2['sucursales']);
        $this->assertSame('pro', $n2['plan']);

        [$codigo, $json] = $this->pedir('GET', '/api/suscripcion', null, $this->tokenPara('dueno@kontia.test'));
        $this->assertTrue($json['datos']['admin_kontia']);
        [, $json] = $this->pedir('GET', '/api/suscripcion', null, $this->tokenPara('cliente@test.com'));
        $this->assertFalse($json['datos']['admin_kontia']);
    }

    public function testResumenYBusqueda(): void
    {
        $token = $this->tokenPara('dueno@kontia.test');
        [$codigo, $json] = $this->pedir('GET', '/api/admin/resumen', null, $token);
        $this->assertSame(200, $codigo);
        $this->assertSame(2, $json['datos']['negocios']);
        $this->assertSame(1, $json['datos']['por_estado']['prueba']);

        [, $json] = $this->pedir('GET', '/api/admin/negocios?q=cliente', null, $token);
        $this->assertCount(1, $json['datos']);
        [, $json] = $this->pedir('GET', '/api/admin/negocios?estado=prueba', null, $token);
        $this->assertCount(1, $json['datos']);

        [$codigo, $json] = $this->pedir('GET', '/api/admin/negocios/2', null, $token);
        $this->assertSame(200, $codigo);
        $this->assertSame(2, (int) $json['datos']['negocio']['id_negocio']);
        [$codigo] = $this->pedir('GET', '/api/admin/negocios/99', null, $token);
        $this->assertSame(404, $codigo);
    }

    public function testExtenderPruebaCambiarPlanYCancelar(): void
    {
        $token = $this->tokenPara('dueno@kontia.test');
        [$codigo, $json] = $this->pedir('POST', '/api/admin/negocios/2/prueba', ['dias' => 10], $token);
        $this->assertSame(200, $codigo);
        $hasta = new \DateTimeImmutable($json['datos']['prueba_hasta']);
        $this->assertGreaterThan(new \DateTimeImmutable('+11 days'), $hasta);

        [$codigo] = $this->pedir('POST', '/api/admin/negocios/2/plan', ['plan' => 'premium', 'sin_limites' => true], $token);
        $this->assertSame(200, $codigo);
        $s = $this->pdo()->query('SELECT s.estado, s.exento_limites, p.codigo FROM suscripcion s JOIN plan_suscripcion p ON p.id = s.id_plan WHERE id_negocio = 2')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['estado' => 'activa', 'exento_limites' => 1, 'codigo' => 'premium'], ['estado' => $s['estado'], 'exento_limites' => (int) $s['exento_limites'], 'codigo' => $s['codigo']]);

        [$codigo] = $this->pedir('POST', '/api/admin/negocios/2/plan', ['plan' => 'inventado'], $token);
        $this->assertSame(400, $codigo);

        [$codigo] = $this->pedir('POST', '/api/admin/negocios/2/cancelar', [], $token);
        $this->assertSame(200, $codigo);
        $this->assertSame('cancelada', $this->pdo()->query('SELECT estado FROM suscripcion WHERE id_negocio = 2')->fetchColumn());

        $acciones = $this->pdo()->query("SELECT accion FROM auditoria WHERE accion LIKE 'admin_%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['admin_extender_prueba', 'admin_cambio_plan', 'admin_cancelar'], $acciones);
    }

    public function testUnClienteNoPuedeCambiarseElPlan(): void
    {
        [$codigo] = $this->pedir('POST', '/api/admin/negocios/2/plan', ['plan' => 'premium', 'sin_limites' => true], $this->tokenPara('cliente@test.com'));
        $this->assertSame(403, $codigo);
    }
}
