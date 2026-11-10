<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/**
 * Endpoints pensados para negocios con miles de productos: búsqueda paginada,
 * catálogo por diferencia, edición rápida y masiva, importación, ventas por
 * fecha, resumen del día y ventas con clave (reintentos sin duplicar).
 */
class RendimientoTest extends KontiaTestCase
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

    private function producto(int $negocio, string $nombre, string $code, float $precio = 100, int $stock = 10, ?string $barcode = null, ?int $proveedor = null): int
    {
        return $this->insertar(
            'INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal, barcode, id_proveedor) VALUES (?, 50, ?, ?, ?, 0, ?, 1, ?, ?)',
            [$nombre, $precio, $stock, $code, $negocio, $barcode, $proveedor]
        );
    }

    public function testBuscarPaginaYFiltraSinMezclarNegocios(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->producto(1, sprintf('Remera %02d', $i), "R$i", 100, $i % 5);
        }
        $this->producto(1, 'Café molido', 'CAF', 200, 3, '7790001');
        $this->producto(2, 'Remera de otro negocio', 'X1');

        [$codigo, $r] = $this->pedir('GET', '/api/products/buscar?q=remera&pagina=1&limite=20', null, $this->token);
        $this->assertSame(200, $codigo);
        $this->assertSame(45, $r['datos']['total']);
        $this->assertCount(20, $r['datos']['items']);
        $this->assertTrue($r['datos']['hayMas']);
        $this->assertSame('Remera 01', $r['datos']['items'][0]['description']);

        [, $r] = $this->pedir('GET', '/api/products/buscar?q=remera&pagina=3&limite=20', null, $this->token);
        $this->assertCount(5, $r['datos']['items']);
        $this->assertFalse($r['datos']['hayMas']);

        // Sin tilde encuentra "Café", y por código de barras también.
        [, $r] = $this->pedir('GET', '/api/products/buscar?q=cafe', null, $this->token);
        $this->assertSame(['Café molido'], array_column($r['datos']['items'], 'description'));
        [, $r] = $this->pedir('GET', '/api/products/buscar?q=7790001', null, $this->token);
        $this->assertSame('CAF', $r['datos']['items'][0]['code']);

        // Varias palabras en cualquier orden.
        [, $r] = $this->pedir('GET', '/api/products/buscar?q=' . urlencode('07 remera'), null, $this->token);
        $this->assertSame(['Remera 07'], array_column($r['datos']['items'], 'description'));

        [, $r] = $this->pedir('GET', '/api/products/buscar?filtro=sin_stock', null, $this->token);
        $this->assertSame(9, $r['datos']['total']);

        [, $r] = $this->pedir('GET', '/api/products/buscar?q=otro', null, $this->token);
        $this->assertSame(0, $r['datos']['total']);
    }

    public function testCatalogoCompletoYPorDiferencia(): void
    {
        $a = $this->producto(1, 'Alfajor', 'A1');
        $b = $this->producto(1, 'Bombón', 'B1');
        $this->producto(2, 'Ajeno', 'Z1');

        [$codigo, $r] = $this->pedir('GET', '/api/products/catalogo', null, $this->token);
        $this->assertSame(200, $codigo);
        $this->assertTrue($r['datos']['completo']);
        $this->assertEqualsCanonicalizing(['Alfajor', 'Bombón'], array_column($r['datos']['productos'], 'description'));
        $servidor = $r['datos']['servidor'];

        // Se modifica uno y se borra el otro.
        $this->pdo()->exec("UPDATE product SET modificado = '2000-01-01 00:00:00'");
        $this->pdo()->prepare('UPDATE product SET sale_price = 999, modificado = NOW() WHERE id = ?')->execute([$a]);
        $this->pdo()->prepare('DELETE FROM product WHERE id = ?')->execute([$b]);

        [, $r] = $this->pedir('GET', '/api/products/catalogo?desde=' . urlencode($servidor), null, $this->token);
        $this->assertFalse($r['datos']['completo']);
        $this->assertCount(1, $r['datos']['productos']);
        $this->assertEquals(999, $r['datos']['productos'][0]['salePrice']);
        $this->assertSame([$a], $r['datos']['ids']);
    }

    public function testEdicionRapidaYPorIds(): void
    {
        $id = $this->producto(1, 'Yerba', 'Y1', 100, 4);
        $ajeno = $this->producto(2, 'Ajeno', 'Z1');

        [$codigo, $r] = $this->pedir('POST', '/api/products/rapido', ['id' => $id, 'salePrice' => 150, 'quantity' => 9], $this->tokenEmpleado);
        $this->assertSame(200, $codigo);
        $this->assertEquals(150, $r['datos']['sale_price']);
        $this->assertEquals(9, $r['datos']['quantity']);

        [$codigo] = $this->pedir('POST', '/api/products/rapido', ['id' => $ajeno, 'quantity' => 1], $this->token);
        $this->assertSame(403, $codigo);

        [, $r] = $this->pedir('POST', '/api/products/por_ids', ['ids' => [$id, $ajeno]], $this->token);
        $this->assertSame([$id], array_map('intval', array_column($r['datos'], 'id')));
    }

    public function testEdicionMasivaSoloTitularYSoloSusProductos(): void
    {
        $a = $this->producto(1, 'A', 'A1', 100);
        $b = $this->producto(1, 'B', 'B1', 200);
        $ajeno = $this->producto(2, 'Z', 'Z1', 100);

        [$codigo] = $this->pedir('POST', '/api/products/masivo', ['ids' => [$a], 'cambios' => ['porcentaje' => 10]], $this->tokenEmpleado);
        $this->assertSame(403, $codigo);

        [$codigo, $r] = $this->pedir('POST', '/api/products/masivo', ['ids' => [$a, $b, $ajeno], 'cambios' => ['porcentaje' => 10, 'stock' => 7]], $this->token);
        $this->assertSame(200, $codigo);
        $this->assertSame(2, $r['datos']['modificados']);

        $precios = $this->pdo()->query('SELECT id, sale_price, quantity FROM product ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertEquals([110, 7], [$precios[0]['sale_price'], $precios[0]['quantity']]);
        $this->assertEquals([220, 7], [$precios[1]['sale_price'], $precios[1]['quantity']]);
        $this->assertEquals(100, $precios[2]['sale_price']);
    }

    public function testImportarValidaCreaYActualiza(): void
    {
        $this->producto(1, 'Viejo', 'A1', 100, 1);

        $filas = [
            ['code' => 'A1', 'description' => 'Actualizado', 'salePrice' => '1.234,50', 'quantity' => '5'],
            ['code' => 'N1', 'description' => 'Nuevo', 'salePrice' => '300'],
        ];
        [$codigo, $r] = $this->pedir('POST', '/api/products/importar', ['sucursal' => 1, 'filas' => $filas, 'simular' => true], $this->token);
        $this->assertSame(200, $codigo);
        $this->assertSame(['validas' => 2, 'nuevos' => 1, 'actualizar' => 1, 'errores' => []], $r['datos']);
        $this->assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM product')->fetchColumn());

        [$codigo, $r] = $this->pedir('POST', '/api/products/importar', ['sucursal' => 1, 'filas' => $filas], $this->token);
        $this->assertSame(200, $codigo);
        $this->assertSame(['creados' => 1, 'actualizados' => 1], $r['datos']);
        $a1 = $this->pdo()->query("SELECT description, sale_price, quantity FROM product WHERE code = 'A1'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(['Actualizado', 1234.5, 5], [$a1['description'], $a1['sale_price'], $a1['quantity']]);

        // Filas con errores: no se guarda nada y se dice cuál.
        [$codigo, $r] = $this->pedir('POST', '/api/products/importar', ['sucursal' => 1, 'filas' => [
            ['code' => 'X', 'description' => '', 'salePrice' => '1'],
        ]], $this->token);
        $this->assertSame(400, $codigo);
        $this->assertStringContainsString('Fila 2', $r['errores'][0]);

        [$codigo] = $this->pedir('POST', '/api/products/importar', ['sucursal' => 1, 'filas' => $filas], $this->tokenEmpleado);
        $this->assertSame(403, $codigo);
    }

    public function testImportarRespetaLimiteDelPlan(): void
    {
        $this->pdo()->exec("UPDATE suscripcion SET id_plan = (SELECT id FROM plan_suscripcion WHERE codigo = 'starter') WHERE id_negocio = 1");
        $filas = [];
        for ($i = 0; $i < 501; $i++) {
            $filas[] = ['code' => "C$i", 'description' => "P$i", 'salePrice' => 1];
        }
        [$codigo] = $this->pedir('POST', '/api/products/importar', ['sucursal' => 1, 'filas' => $filas], $this->token);
        $this->assertSame(403, $codigo);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM product')->fetchColumn());
    }

    public function testVentasPorRangoYResumenDelDia(): void
    {
        $p = $this->producto(1, 'Pan', 'P1', 100, 50);
        $hoy = (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
        $venta = function (string $fecha, int $cantidad) use ($p) {
            $idSale = $this->insertar('INSERT INTO sales (id_product, id_negocio) VALUES (?, 1)', [$p]);
            $this->insertar('INSERT INTO sales_product (id_sale, id_product, quantity, price, sale_product_date, type_payment, id_negocio, sucursal) VALUES (?, ?, ?, 100, ?, "Efectivo", 1, 1)', [$idSale, $p, $cantidad, $fecha]);
        };
        $venta("$hoy 10:00:00", 2);
        $venta("$hoy 11:00:00", 1);
        $venta('2020-01-01 10:00:00', 5);

        [, $r] = $this->pedir('GET', "/api/report/salesProduct?desde=$hoy&hasta=$hoy", null, $this->token);
        $this->assertCount(2, $r['datos']['ventas']);
        [, $r] = $this->pedir('GET', '/api/report/salesProduct', null, $this->token);
        $this->assertCount(3, $r['datos']['ventas']);

        [$codigo, $r] = $this->pedir('GET', '/api/report/resumen', null, $this->token);
        $this->assertSame(200, $codigo);
        $this->assertEquals(['total' => 300, 'ventas' => 2, 'unidades' => 3], $r['datos']['hoy']);
        $this->assertSame(1, $r['datos']['productos']);

        // El empleado no ve el monto vendido.
        [, $r] = $this->pedir('GET', '/api/report/resumen', null, $this->tokenEmpleado);
        $this->assertArrayNotHasKey('hoy', $r['datos']);
    }

    public function testVentaConClaveNoSeDuplicaAlReintentar(): void
    {
        $p = $this->producto(1, 'Pan', 'P1', 100, 10);
        $carrito = [['id' => $p, 'salePrice' => 100, 'cantidad' => 2, 'typePayment' => 'Efectivo', 'sucursal' => 1]];
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_X_VENTA_CLAVE' => 'venta-offline-0001',
            'HTTP_X_VENTA_FECHA' => (new \DateTime('-1 hour', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s'),
        ];
        for ($i = 0; $i < 2; $i++) {
            $this->client->request('POST', '/api/salesProduct/salesProduct', [], [], $headers, json_encode($carrito));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        }
        $this->assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM sales_product')->fetchColumn());
        $this->assertSame(8, (int) $this->pdo()->query("SELECT quantity FROM product WHERE id = $p")->fetchColumn());
        $fecha = $this->pdo()->query('SELECT sale_product_date FROM sales_product')->fetchColumn();
        $this->assertSame($headers['HTTP_X_VENTA_FECHA'], $fecha);
    }

    public function testDeudoresSoloSumanCuentasDelNegocio(): void
    {
        $cliente = $this->insertar("INSERT INTO clientes (id_negocio, dni, apellido, nombre) VALUES (1, '1', 'Pérez', 'Ana')");
        $this->insertar('INSERT INTO ctacte (id_persona, precio_original, precio_actual, id_negocio) VALUES (?, 100, 100, 1)', [$cliente]);
        // Fila corrupta de otro negocio con el mismo id_persona: no debe sumar.
        $this->insertar('INSERT INTO ctacte (id_persona, precio_original, precio_actual, id_negocio) VALUES (?, 900, 900, 2)', [$cliente]);

        [$codigo, $r] = $this->pedir('GET', '/api/cuenta_corriente/obtener_cuentas_corrientes_con_deuda/1', null, $this->token);
        $this->assertSame(200, $codigo);
        $this->assertEquals(100, $r['datos'][0]['deuda']);
    }
}
