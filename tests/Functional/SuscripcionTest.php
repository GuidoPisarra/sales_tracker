<?php

namespace App\Tests\Functional;

use App\Service\MercadoPagoClient;
use App\Tests\KontiaTestCase;

class SuscripcionTest extends KontiaTestCase
{
    private function mpFalso(array $preapprovals = [], array $cobros = []): \stdClass
    {
        $registro = new \stdClass();
        $registro->llamadas = [];
        $falso = new class($preapprovals, $cobros, $registro) extends MercadoPagoClient {
            public function __construct(private array $pre, private array $cob, private \stdClass $reg) {}
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
