<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** Reporte de margen, recordatorio de fiado y promos. */
class MargenFiadoPromosTest extends KontiaTestCase
{
    private string $token;
    private string $tokenEmpleado;
    private string $tokenOtro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1]);
        $this->crearNegocio(2, [1]);
        $this->crearUsuario('titular@test.com', 1);
        $this->crearUsuario('empleado@test.com', 1, false);
        $this->crearUsuario('otro@test.com', 2);
        $this->token = $this->tokenPara('titular@test.com');
        $this->tokenEmpleado = $this->tokenPara('empleado@test.com');
        $this->tokenOtro = $this->tokenPara('otro@test.com');
    }

    private function producto(int $negocio, string $nombre, float $costo, float $precio = 100, int $stock = 50): int
    {
        return $this->insertar(
            'INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES (?, ?, ?, ?, ?, 0, ?, 1)',
            [$nombre, $costo, $precio, $stock, 'C' . $nombre, $negocio]
        );
    }

    private function vender(int $id, float $precio, int $cantidad): void
    {
        [$estado, $r] = $this->pedir('POST', '/api/salesProduct/salesProduct', [['id' => $id, 'salePrice' => $precio, 'cantidad' => $cantidad, 'typePayment' => 'efectivo', 'sucursal' => 1]], $this->tokenEmpleado);
        $this->assertSame(200, $estado, json_encode($r));
    }

    public function testMargenUsaElCostoDelMomentoDeLaVenta(): void
    {
        $a = $this->producto(1, 'Gaseosa', 60);
        $b = $this->producto(1, 'Alfajor', 0); // sin costo cargado
        $this->vender($a, 100, 2);
        $this->vender($b, 50, 1);
        // Sube el costo después de vender: la venta ya hecha mantiene su margen.
        $this->pdo()->exec("UPDATE product SET cost_price = 90 WHERE id = $a");
        $this->assertSame('60.00', $this->pdo()->query("SELECT cost FROM sales_product WHERE id_product = $a")->fetchColumn());

        $hoy = (new \DateTimeImmutable('today', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
        [$estado] = $this->pedir('GET', "/api/report/margen?desde=$hoy&hasta=$hoy", null, $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado, $r] = $this->pedir('GET', "/api/report/margen?desde=$hoy&hasta=$hoy", null, $this->token);
        $this->assertSame(200, $estado);
        $t = $r['datos']['totales'];
        $this->assertEquals(250, $t['ventas']);
        $this->assertEquals(120, $t['costo']);
        $this->assertEquals(80, $t['ganancia']);
        $this->assertEquals(40, $t['margen']);
        $this->assertEquals(50, $t['ventas_sin_costo']);
        $this->assertSame(1, $t['productos_sin_costo']);
        $gaseosa = array_values(array_filter($r['datos']['productos'], fn ($p) => $p['id'] === $a))[0];
        $this->assertEquals(80, $gaseosa['ganancia']);
        $alfajor = array_values(array_filter($r['datos']['productos'], fn ($p) => $p['id'] === $b))[0];
        $this->assertNull($alfajor['ganancia']);
        $this->assertCount(1, $r['datos']['dias']);

        // Una venta vieja (sin costo guardado) usa el costo actual del producto.
        $this->pdo()->exec("UPDATE sales_product SET cost = NULL WHERE id_product = $a");
        [, $r] = $this->pedir('GET', "/api/report/margen?desde=$hoy&hasta=$hoy", null, $this->token);
        $this->assertEquals(20, $r['datos']['totales']['ganancia']);

        // El otro negocio no ve estas ventas.
        [, $r] = $this->pedir('GET', "/api/report/margen?desde=$hoy&hasta=$hoy", null, $this->tokenOtro);
        $this->assertEquals(0, $r['datos']['totales']['ventas']);
    }

    public function testDeudoresYRecordatorio(): void
    {
        $ana = $this->insertar("INSERT INTO clientes (id_negocio, dni, apellido, nombre, telefono) VALUES (1, '1', 'Pérez', 'Ana', '1155551111')");
        $beto = $this->insertar("INSERT INTO clientes (id_negocio, dni, apellido, nombre) VALUES (1, '2', 'Gómez', 'Beto')");
        $otro = $this->insertar("INSERT INTO clientes (id_negocio, dni, apellido, nombre) VALUES (2, '3', 'X', 'Otro')");
        $c1 = $this->insertar('INSERT INTO ctacte (id_persona, precio_original, precio_actual, id_negocio, fecha_venta) VALUES (?, 1000, 1000, 1, DATE_SUB(NOW(), INTERVAL 40 DAY))', [$ana]);
        $this->insertar('INSERT INTO pagos (id_persona, id_ctacte, entrega, fecha) VALUES (?, ?, 300, DATE_SUB(NOW(), INTERVAL 35 DAY))', [$ana, $c1]);
        $c2 = $this->insertar('INSERT INTO ctacte (id_persona, precio_original, precio_actual, id_negocio, fecha_venta) VALUES (?, 500, 500, 1, NOW())', [$beto]);
        $this->insertar('INSERT INTO pagos (id_persona, id_ctacte, entrega, fecha) VALUES (?, ?, 500, NOW())', [$beto, $c2]); // pagó todo
        $this->insertar('INSERT INTO ctacte (id_persona, precio_original, precio_actual, id_negocio, fecha_venta) VALUES (?, 900, 900, 2, NOW())', [$otro]);

        [$estado, $r] = $this->pedir('GET', '/api/fiado/deudores', null, $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        $this->assertCount(1, $r['datos']['deudores']);
        $d = $r['datos']['deudores'][0];
        $this->assertSame('Ana Pérez', $d['nombre']);
        $this->assertEquals(700, $d['saldo']);
        $this->assertSame('1155551111', $d['telefono']);
        $this->assertGreaterThanOrEqual(34, $d['dias_sin_pagar']);
        $this->assertStringContainsString('{saldo}', $r['datos']['mensaje']);

        // Aviso de Inicio.
        [, $r] = $this->pedir('GET', '/api/avisos', null, $this->token);
        $this->assertCount(1, array_filter($r['datos'], fn ($a) => ($a['ruta'] ?? '') === '/fiados'));

        // Recordar: queda anotado y el aviso se va.
        [$estado] = $this->pedir('POST', "/api/fiado/recordado/$ana", null, $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        [, $r] = $this->pedir('GET', '/api/fiado/deudores', null, $this->token);
        $this->assertNotNull($r['datos']['deudores'][0]['recordado_en']);
        [, $r] = $this->pedir('GET', '/api/avisos', null, $this->token);
        $this->assertCount(0, array_filter($r['datos'], fn ($a) => ($a['ruta'] ?? '') === '/fiados'));

        // No se puede marcar un cliente de otro negocio.
        [$estado] = $this->pedir('POST', "/api/fiado/recordado/$otro", null, $this->token);
        $this->assertSame(404, $estado);

        // El mensaje lo cambia el administrador.
        [$estado] = $this->pedir('POST', '/api/mostrador/config', ['fiado_mensaje' => 'Hola {nombre}, debés {saldo}'], $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        $this->pedir('POST', '/api/mostrador/config', ['fiado_mensaje' => 'Hola {nombre}, debés {saldo}'], $this->token);
        [, $r] = $this->pedir('GET', '/api/fiado/deudores', null, $this->tokenEmpleado);
        $this->assertSame('Hola {nombre}, debés {saldo}', $r['datos']['mensaje']);
    }

    public function testPromos(): void
    {
        $a = $this->producto(1, 'A', 10);
        $ajeno = $this->producto(2, 'Ajeno', 10);

        [$estado] = $this->pedir('POST', '/api/promos', ['tipo' => 'nxm', 'lleva' => 2, 'paga' => 1, 'productos' => [$a]], $this->tokenEmpleado);
        $this->assertSame(403, $estado);

        [$estado, $r] = $this->pedir('POST', '/api/promos', ['tipo' => 'nxm', 'lleva' => 2, 'paga' => 2, 'productos' => [$a]], $this->token);
        $this->assertSame(400, $estado);
        [$estado, $r] = $this->pedir('POST', '/api/promos', ['tipo' => 'porcentaje', 'porcentaje' => 100, 'todos_productos' => true], $this->token);
        $this->assertSame(400, $estado);
        [$estado, $r] = $this->pedir('POST', '/api/promos', ['tipo' => 'nxm', 'lleva' => 2, 'paga' => 1], $this->token);
        $this->assertSame(400, $estado);
        [$estado, $r] = $this->pedir('POST', '/api/promos', ['tipo' => 'nxm', 'lleva' => 2, 'paga' => 1, 'productos' => [$ajeno]], $this->token);
        $this->assertSame(400, $estado);

        [$estado, $r] = $this->pedir('POST', '/api/promos', ['tipo' => 'nxm', 'lleva' => 2, 'paga' => 1, 'productos' => [$a, $ajeno], 'dias' => [5, 6], 'desde' => '2026-12-01', 'hasta' => '2026-12-31'], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $promo = $r['datos'];
        $this->assertSame('2x1', $promo['nombre']);
        $this->assertSame([$a], $promo['productos']);
        $this->assertSame([5, 6], $promo['dias']);

        [$estado, $r] = $this->pedir('POST', '/api/promos', ['tipo' => 'cantidad', 'lleva' => 3, 'porcentaje' => 15, 'todos_productos' => true, 'nombre' => 'Mayorista'], $this->token);
        $this->assertSame(200, $estado);

        // El empleado las ve (la Caja las aplica); el otro negocio no.
        [, $r] = $this->pedir('GET', '/api/promos', null, $this->tokenEmpleado);
        $this->assertCount(2, $r['datos']);
        [, $r] = $this->pedir('GET', '/api/promos', null, $this->tokenOtro);
        $this->assertCount(0, $r['datos']);

        // Editar y pausar.
        [$estado, $r] = $this->pedir('POST', '/api/promos', ['id' => $promo['id'], 'tipo' => 'nxm', 'lleva' => 3, 'paga' => 2, 'productos' => [$a], 'activo' => false], $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame('3x2', $r['datos']['nombre']);
        $this->assertFalse($r['datos']['activo']);
        $this->assertSame([], $r['datos']['dias']);

        // Otro negocio no la toca.
        [$estado] = $this->pedir('DELETE', '/api/promos/' . $promo['id'], null, $this->tokenOtro);
        $this->assertSame(404, $estado);
        [$estado] = $this->pedir('DELETE', '/api/promos/' . $promo['id'], null, $this->token);
        $this->assertSame(200, $estado);
        [, $r] = $this->pedir('GET', '/api/promos', null, $this->token);
        $this->assertCount(1, $r['datos']);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM promo_producto WHERE id_promo = ' . $promo['id'])->fetchColumn());
    }
}
