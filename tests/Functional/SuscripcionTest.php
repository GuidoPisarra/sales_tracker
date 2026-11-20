<?php

namespace App\Tests\Functional;

use App\Service\MercadoPagoClient;
use App\Tests\KontiaTestCase;

class SuscripcionTest extends KontiaTestCase
{
    private function mpFalso(array $preapprovals = [], array $cobros = [], array $pagos = []): \stdClass
    {
        $registro = new \stdClass();
        $registro->llamadas = [];
        $falso = new class($preapprovals, $cobros, $registro, $pagos) extends MercadoPagoClient {
            public function __construct(private array $pre, private array $cob, private \stdClass $reg, private array $pag = []) {}
            public function crearPagoMensual(int $idNegocio, array $plan): array
            {
                $this->reg->llamadas[] = ['mensual', $idNegocio, $plan['codigo']];
                return ['id' => 'pref-1', 'init_point' => 'https://mp.test/pago'];
            }
            public function obtenerPago(string $id): array { return $this->pag[$id]; }
            public function pagosRecientes(int $dias = 3): array { return array_values($this->pag); }
            public function pagosPorReferencia(string $referencia): array
            {
                return array_values(array_filter($this->pag, fn ($p) => ($p['external_reference'] ?? '') === $referencia));
            }
            public function configurado(): bool { return true; }
            public function crearSuscripcion(int $idNegocio, array $plan, string $email): array
            {
                $this->reg->llamadas[] = ['crear', $idNegocio, $plan['codigo'], $email];
                return ['id' => 'pre-nuevo', 'init_point' => 'https://mp.test/checkout'];
            }
            public function obtenerSuscripcion(string $id): array { return $this->pre[$id] ?? ['id' => $id, 'status' => 'pending']; }
            public function cambiarMonto(string $id, array $plan, int $idNegocio): array
            {
                $this->reg->llamadas[] = ['monto', $id, $plan['codigo']];
                return [];
            }
            public function cancelarSuscripcion(string $id): array
            {
                $this->reg->llamadas[] = ['cancelar', $id];
                return [];
            }
            public function obtenerCobro(string $id): array { return $this->cob[$id]; }
        };
        static::getContainer()->set(MercadoPagoClient::class, $falso);
        return $registro;
    }

    public function testLimiteDeProductosDelPlan(): void
    {
        $this->crearNegocio(1, [1], 'starter');
        $this->crearUsuario('a@test.com', 1);
        $this->pdo()->exec('UPDATE plan_suscripcion SET max_productos = 1 WHERE codigo = "starter"');
        $this->insertar("INSERT INTO product (description, code, activo, id_negocio, sucursal) VALUES ('Uno', 'U1', 0, 1, 1)");

        [$codigo, $json] = $this->pedir('POST', '/api/products/products', ['description' => 'Dos'], $this->tokenPara('a@test.com'));
        $this->assertSame(402, $codigo);
        $this->assertSame('limite_plan', $json['datos']['codigo']);
        $this->assertSame('productos', $json['datos']['recurso']);
    }

    public function testStarterNoIncluyeCuentasCorrientesNiAsistente(): void
    {
        $this->crearNegocio(1, [1], 'starter');
        $this->crearUsuario('a@test.com', 1);
        $token = $this->tokenPara('a@test.com');
        [$codigo] = $this->pedir('GET', '/api/cuenta_corriente/obtener_cuentas_corrientes', null, $token);
        $this->assertSame(402, $codigo);
        [$codigo, $json] = $this->pedir('POST', '/api/asistente/mensaje', ['mensaje' => 'hola'], $token);
        $this->assertSame(402, $codigo);
        $this->assertSame('mensajes_ia', $json['datos']['recurso']);
    }

    public function testLimiteDeSucursalesYUsuarios(): void
    {
        $this->crearNegocio(1, [1], 'starter'); // 1 sucursal, 2 usuarios
        $this->crearUsuario('a@test.com', 1);
        $this->crearUsuario('b@test.com', 1, false);
        $token = $this->tokenPara('a@test.com');
        [$codigo] = $this->pedir('POST', '/api/negocio/nuevo_negocio', ['nombre' => 'Suc 2', 'sucursal' => 2], $token);
        $this->assertSame(402, $codigo);
        [$codigo] = $this->pedir('POST', '/api/empleados/nuevo', ['name' => 'C', 'email' => 'c@test.com', 'password' => 'Clave1234'], $token);
        $this->assertSame(402, $codigo);
    }

    public function testClienteAnteriorAlSaasNoTieneLimites(): void
    {
        $this->crearNegocio(1, [1], 'starter');
        $this->pdo()->exec('UPDATE suscripcion SET exento_limites = 1 WHERE id_negocio = 1');
        $this->crearUsuario('a@test.com', 1);
        [$codigo] = $this->pedir('GET', '/api/cuenta_corriente/obtener_cuentas_corrientes', null, $this->tokenPara('a@test.com'));
        $this->assertSame(200, $codigo);
    }

    public function testPruebaVencidaQuedaEnSoloLectura(): void
    {
        $this->crearNegocio(1, [1], 'pro', 'prueba', date('Y-m-d H:i:s', strtotime('-1 day')));
        $this->crearUsuario('a@test.com', 1);
        $token = $this->tokenPara('a@test.com');

        [$codigo, $json] = $this->pedir('POST', '/api/products/products', ['description' => 'X'], $token);
        $this->assertSame(402, $codigo);
        $this->assertSame('solo_lectura', $json['datos']['codigo']);

        // Puede seguir viendo sus datos y su plan.
        [$codigo] = $this->pedir('GET', '/api/products/products_list', null, $token);
        $this->assertSame(200, $codigo);
        [$codigo, $json] = $this->pedir('GET', '/api/suscripcion', null, $token);
        $this->assertSame(200, $codigo);
        $this->assertSame('prueba_vencida', $json['datos']['estado']);
        $this->assertTrue($json['datos']['solo_lectura']);
        $this->assertArrayHasKey('uso', $json['datos']);
        $this->assertNotEmpty($json['datos']['planes']);
    }

    public function testMoraTieneDiasDeGracia(): void
    {
        $this->crearNegocio(1, [1], 'pro', 'en_mora');
        $this->crearUsuario('a@test.com', 1);
        $this->pdo()->exec("UPDATE suscripcion SET en_mora_desde = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id_negocio = 1");
        [$codigo] = $this->pedir('POST', '/api/proveedores/proveedor', ['nombre' => 'P'], $this->tokenPara('a@test.com'));
        $this->assertNotSame(402, $codigo);

        $this->pdo()->exec("UPDATE suscripcion SET en_mora_desde = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id_negocio = 1");
        [$codigo] = $this->pedir('POST', '/api/proveedores/proveedor', ['nombre' => 'P'], $this->tokenPara('a@test.com'));
        $this->assertSame(402, $codigo);
    }

    public function testCheckoutCreaSuscripcionEnMercadoPago(): void
    {
        $mp = $this->mpFalso();
        $this->crearNegocio(1, [1], 'pro', 'prueba', date('Y-m-d H:i:s', strtotime('+5 days')));
        $this->crearUsuario('a@test.com', 1);
        $this->crearUsuario('emp@test.com', 1, false);

        [$codigo] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'pro'], $this->tokenPara('emp@test.com'));
        $this->assertSame(403, $codigo, 'Solo el titular elige el plan');
        [$codigo] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'fundador'], $this->tokenPara('a@test.com'));
        $this->assertSame(400, $codigo, 'Fundador no está a la venta');

        [$codigo, $json] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'premium'], $this->tokenPara('a@test.com'));
        $this->assertSame(200, $codigo, json_encode($json));
        $this->assertSame('https://mp.test/checkout', $json['datos']['init_point']);
        $this->assertSame(['crear', 1, 'premium', 'a@test.com'], $mp->llamadas[0]);
        $this->assertSame('pre-nuevo', $this->pdo()->query('SELECT mp_preapproval_id FROM suscripcion WHERE id_negocio = 1')->fetchColumn());

        [$codigo] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'pro', 'email_pago' => 'no-es-email'], $this->tokenPara('a@test.com'));
        $this->assertSame(400, $codigo, 'Email de Mercado Pago inválido');
        [$codigo] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'pro', 'email_pago' => 'otro@mp.com'], $this->tokenPara('a@test.com'));
        $this->assertSame(200, $codigo);
        $this->assertSame(['crear', 1, 'pro', 'otro@mp.com'], $mp->llamadas[1]);
    }

    private function webhook(string $tipo, string $dataId, string $secreto = 'secreto-test'): int
    {
        $ts = (string) time();
        $requestId = 'req-' . uniqid();
        $firma = hash_hmac('sha256', "id:$dataId;request-id:$requestId;ts:$ts;", $secreto);
        $this->client->request('POST', '/api/webhooks/mercadopago?data.id=' . $dataId . '&type=' . $tipo, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => "ts=$ts,v1=$firma",
            'HTTP_X_REQUEST_ID' => $requestId,
        ], json_encode(['type' => $tipo, 'data' => ['id' => $dataId]]));
        return $this->client->getResponse()->getStatusCode();
    }

    public function testWebhookActivaLaSuscripcionYRegistraCobros(): void
    {
        $this->crearNegocio(1, [1], 'pro', 'prueba', date('Y-m-d H:i:s', strtotime('+5 days')));
        $this->pdo()->exec("UPDATE suscripcion SET mp_preapproval_id = 'pre-1' WHERE id_negocio = 1");
        $this->mpFalso(
            ['pre-1' => ['id' => 'pre-1', 'status' => 'authorized', 'external_reference' => 'kontia:1:premium', 'next_payment_date' => '2026-12-08T10:00:00.000-03:00']],
            [
                'cobro-ok' => ['id' => 'cobro-ok', 'preapproval_id' => 'pre-1', 'transaction_amount' => 55000, 'date_created' => '2026-11-08T10:00:00.000-03:00', 'payment' => ['status' => 'approved']],
                'cobro-mal' => ['id' => 'cobro-mal', 'preapproval_id' => 'pre-1', 'transaction_amount' => 55000, 'date_created' => '2026-12-08T10:00:00.000-03:00', 'payment' => ['status' => 'rejected']],
            ]
        );

        $this->assertSame(401, $this->webhook('subscription_preapproval', 'pre-1', 'secreto-falso'), 'Firma inválida');
        $this->assertSame(200, $this->webhook('subscription_preapproval', 'pre-1'));
        $s = $this->pdo()->query('SELECT s.*, p.codigo FROM suscripcion s JOIN plan_suscripcion p ON p.id = s.id_plan WHERE id_negocio = 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('activa', $s['estado']);
        $this->assertSame('premium', $s['codigo']);

        $this->assertSame(200, $this->webhook('subscription_authorized_payment', 'cobro-ok'));
        $this->assertSame(200, $this->webhook('subscription_authorized_payment', 'cobro-ok'), 'Repetido no duplica');
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM pago_suscripcion WHERE mp_id = 'cobro-ok'")->fetchColumn());

        $this->assertSame(200, $this->webhook('subscription_authorized_payment', 'cobro-mal'));
        $this->assertSame('en_mora', $this->pdo()->query('SELECT estado FROM suscripcion WHERE id_negocio = 1')->fetchColumn());
    }

    public function testPagoMensualCreaElPagoYNoPisaUnDebitoActivo(): void
    {
        $mp = $this->mpFalso(['pre-1' => ['id' => 'pre-1', 'status' => 'authorized']]);
        $this->crearNegocio(1, [1], 'pro', 'prueba', date('Y-m-d H:i:s', strtotime('+5 days')));
        $this->crearUsuario('a@test.com', 1);
        $token = $this->tokenPara('a@test.com');

        [$codigo] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'pro', 'modo' => 'otro'], $token);
        $this->assertSame(400, $codigo);
        [$codigo, $json] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'starter', 'modo' => 'mensual'], $token);
        $this->assertSame(200, $codigo, json_encode($json));
        $this->assertSame('https://mp.test/pago', $json['datos']['init_point']);
        $this->assertSame(['mensual', 1, 'starter'], $mp->llamadas[0]);
        $this->assertNull($this->pdo()->query('SELECT mp_preapproval_id FROM suscripcion WHERE id_negocio = 1')->fetchColumn(), 'No crea débito');

        // Con débito automático autorizado, primero hay que cancelarlo.
        $this->pdo()->exec("UPDATE suscripcion SET estado = 'activa', mp_preapproval_id = 'pre-1' WHERE id_negocio = 1");
        [$codigo] = $this->pedir('POST', '/api/suscripcion/checkout', ['plan' => 'pro', 'modo' => 'mensual'], $token);
        $this->assertSame(400, $codigo);
    }

    public function testPagoMensualSumaUnMesYVence(): void
    {
        $pruebaHasta = date('Y-m-d H:i:s', strtotime('+5 days'));
        $this->crearNegocio(1, [1], 'pro', 'prueba', $pruebaHasta);
        $this->crearUsuario('a@test.com', 1);
        $pago = fn (string $id, string $estado, float $monto = 30000) => [
            'id' => $id, 'status' => $estado, 'transaction_amount' => $monto,
            'external_reference' => 'kontia-mes:1:starter', 'date_approved' => date('c'),
        ];
        $this->mpFalso([], [], [
            'pay-1' => $pago('pay-1', 'approved'),
            'pay-2' => $pago('pay-2', 'approved'),
            'pay-mal' => $pago('pay-mal', 'rejected'),
            'pay-otro' => ['id' => 'pay-otro', 'status' => 'approved', 'transaction_amount' => 1, 'external_reference' => 'otra-cosa'],
        ]);

        $this->assertSame(200, $this->webhook('payment', 'pay-mal'));
        $this->assertSame('prueba', $this->pdo()->query('SELECT estado FROM suscripcion WHERE id_negocio = 1')->fetchColumn());
        $this->assertSame(200, $this->webhook('payment', 'pay-otro'), 'Pagos ajenos a Kontia se ignoran');

        // Pagar durante la prueba no le hace perder los días que le quedan.
        $this->assertSame(200, $this->webhook('payment', 'pay-1'));
        $s = $this->pdo()->query('SELECT s.*, p.codigo FROM suscripcion s JOIN plan_suscripcion p ON p.id = s.id_plan WHERE id_negocio = 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('activa', $s['estado']);
        $this->assertSame('mensual', $s['modo_pago']);
        $this->assertSame('starter', $s['codigo']);
        $esperado = (new \DateTimeImmutable($pruebaHasta))->modify('+1 month')->format('Y-m-d');
        $this->assertSame($esperado, substr($s['periodo_hasta'], 0, 10));

        // El mismo pago repetido (otro aviso o la conciliación) no suma dos veces.
        $this->assertSame(200, $this->webhook('payment', 'pay-1'));
        $this->assertSame($esperado, substr($this->pdo()->query('SELECT periodo_hasta FROM suscripcion WHERE id_negocio = 1')->fetchColumn(), 0, 10));

        // Un segundo pago suma otro mes desde el vencimiento.
        $this->assertSame(200, $this->webhook('payment', 'pay-2'));
        $esperado2 = (new \DateTimeImmutable($esperado))->modify('+1 month')->format('Y-m-d');
        $this->assertSame($esperado2, substr($this->pdo()->query('SELECT periodo_hasta FROM suscripcion WHERE id_negocio = 1')->fetchColumn(), 0, 10));

        // Vencido: días de gracia y después solo lectura.
        $token = $this->tokenPara('a@test.com');
        $this->pdo()->exec("UPDATE suscripcion SET periodo_hasta = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id_negocio = 1");
        [, $json] = $this->pedir('GET', '/api/suscripcion', null, $token);
        $this->assertSame('en_mora', $json['datos']['estado']);
        $this->assertSame('mensual', $json['datos']['modo_pago']);
        $this->assertFalse($json['datos']['con_debito_automatico']);
        $this->pdo()->exec("UPDATE suscripcion SET periodo_hasta = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE id_negocio = 1");
        [$codigo, $json] = $this->pedir('POST', '/api/proveedores/proveedor', ['nombre' => 'P'], $token);
        $this->assertSame(402, $codigo);
        $this->assertSame('solo_lectura', $json['datos']['codigo']);
    }

    public function testSincronizarAlVolverDeMercadoPago(): void
    {
        $this->crearNegocio(1, [1], 'pro', 'prueba', date('Y-m-d H:i:s', strtotime('-1 day')));
        $this->crearNegocio(2, [1], 'pro', 'prueba', date('Y-m-d H:i:s', strtotime('-1 day')));
        $this->crearUsuario('a@test.com', 1);
        $this->crearUsuario('b@test.com', 2);
        $this->mpFalso([], [], [
            '111' => ['id' => '111', 'status' => 'approved', 'transaction_amount' => 40000, 'external_reference' => 'kontia-mes:1:pro', 'date_approved' => date('c')],
        ]);

        // Otro negocio no puede aplicarse el pago ajeno.
        [$codigo, $json] = $this->pedir('POST', '/api/suscripcion/sincronizar', ['payment_id' => '111'], $this->tokenPara('b@test.com'));
        $this->assertSame(200, $codigo);
        $this->assertSame('prueba_vencida', $json['datos']['estado']);

        // Sin payment_id igual encuentra el pago por la referencia del negocio.
        [$codigo, $json] = $this->pedir('POST', '/api/suscripcion/sincronizar', [], $this->tokenPara('a@test.com'));
        $this->assertSame(200, $codigo);
        $this->assertSame('activa', $json['datos']['estado']);
        $this->assertSame('mensual', $json['datos']['modo_pago']);
    }

    public function testDebitoAbandonadoNoAfectaAlQuePagaMesAMes(): void
    {
        $this->crearNegocio(1, [1], 'pro');
        $hasta = date('Y-m-d H:i:s', strtotime('+10 days'));
        $this->pdo()->exec("UPDATE suscripcion SET modo_pago = 'mensual', periodo_hasta = '$hasta', mp_preapproval_id = 'pre-viejo' WHERE id_negocio = 1");
        $this->mpFalso(['pre-viejo' => ['id' => 'pre-viejo', 'status' => 'cancelled', 'external_reference' => 'kontia:1:pro']]);
        $this->assertSame(200, $this->webhook('subscription_preapproval', 'pre-viejo'));
        $this->assertSame('activa', $this->pdo()->query('SELECT estado FROM suscripcion WHERE id_negocio = 1')->fetchColumn());
    }

    public function testAvisosDePagoMensual(): void
    {
        $this->mpFalso();
        $this->crearNegocio(1, [1], 'pro');
        $this->crearUsuario('a@test.com', 1);
        $this->pdo()->exec("UPDATE suscripcion SET modo_pago = 'mensual', periodo_hasta = DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE id_negocio = 1");
        $cobros = static::getContainer()->get(\App\Service\CobrosService::class);
        $this->assertSame(1, $cobros->avisarPagosMensuales(3));
        $this->assertSame(0, $cobros->avisarPagosMensuales(3), 'Un solo aviso por vencimiento');
        $this->pdo()->exec("UPDATE suscripcion SET periodo_hasta = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id_negocio = 1");
        $this->assertSame(1, $cobros->avisarPagosMensuales(3));
        $this->assertSame(0, $cobros->avisarPagosMensuales(3));
    }

    public function testFirmaDeMercadoPago(): void
    {
        $ts = (string) time();
        $v1 = hash_hmac('sha256', "id:abc123;request-id:r1;ts:$ts;", 's');
        $this->assertTrue(MercadoPagoClient::firmaValida("ts=$ts,v1=$v1", 'r1', 'ABC123', 's'));
        $this->assertFalse(MercadoPagoClient::firmaValida("ts=$ts,v1=$v1", 'r2', 'abc123', 's'));
        $viejo = (string) (time() - 3600);
        $v1 = hash_hmac('sha256', "id:abc123;request-id:r1;ts:$viejo;", 's');
        $this->assertFalse(MercadoPagoClient::firmaValida("ts=$viejo,v1=$v1", 'r1', 'abc123', 's'), 'Aviso viejo (replay)');
    }

    public function testAccionSensibleQuedaAuditada(): void
    {
        $this->crearNegocio(1);
        $this->crearUsuario('a@test.com', 1);
        $this->insertar("INSERT INTO product (description, sale_price, code, activo, id_negocio, sucursal) VALUES ('P', 100, 'P1', 0, 1, 1)");
        [$codigo] = $this->pedir('GET', '/api/products/products/price_percent/10/0', null, $this->tokenPara('a@test.com'));
        $this->assertSame(200, $codigo);
        $fila = $this->pdo()->query("SELECT * FROM auditoria WHERE accion = 'aumento_precios_masivo'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($fila);
        $this->assertSame(1, (int) $fila['id_negocio']);
        $this->assertStringContainsString('"percentage":"10"', $fila['detalle']);
    }

    public function testPlanesPublicosSinLogin(): void
    {
        [$codigo, $json] = $this->pedir('GET', '/api/planes');
        $this->assertSame(200, $codigo);
        $this->assertSame(['starter', 'pro', 'premium'], array_column($json['datos'], 'codigo'));
    }
}
