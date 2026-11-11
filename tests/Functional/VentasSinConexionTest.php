<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/**
 * Ventas sin conexión y ventas confirmadas "sin stock": nunca se rechazan por stock,
 * el stock queda en 0 (no negativo) y lo que faltó se anota para el administrador.
 */
class VentasSinConexionTest extends KontiaTestCase
{
    private string $token;
    private string $tokenEmpleado;
    private string $tokenOtro;
    private int $idTitular;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1]);
        $this->crearNegocio(2, [1]);
        $this->idTitular = $this->crearUsuario('titular@test.com', 1);
        $this->crearUsuario('empleado@test.com', 1, false);
        $this->crearUsuario('otro@test.com', 2);
        $this->token = $this->tokenPara('titular@test.com');
        $this->tokenEmpleado = $this->tokenPara('empleado@test.com');
        $this->tokenOtro = $this->tokenPara('otro@test.com');
    }

    private function producto(int $negocio, string $nombre, int $stock): int
    {
        return $this->insertar(
            'INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES (?, 50, 100, ?, ?, 0, ?, 1)',
            [$nombre, $stock, 'C' . $nombre, $negocio]
        );
    }

    private function vender(array $carrito, string $token, ?string $fechaOffline = null, ?string $clave = null): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        if ($fechaOffline) {
            $headers['HTTP_X_VENTA_FECHA'] = $fechaOffline;
        }
        if ($clave) {
            $headers['HTTP_X_VENTA_CLAVE'] = $clave;
        }
        $this->client->request('POST', '/api/salesProduct/salesProduct', [], [], $headers, json_encode($carrito));
        $r = $this->client->getResponse();
        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    private function stock(int $id): int
    {
        return (int) $this->pdo()->query("SELECT quantity FROM product WHERE id = $id")->fetchColumn();
    }

    private function haceUnaHora(): string
    {
        return (new \DateTime('-1 hour', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');
    }

    public function testVentaOnlineSinStockSeRechazaComoAntes(): void
    {
        $p = $this->producto(1, 'Pan', 1);
        [$estado] = $this->vender([['id' => $p, 'salePrice' => 100, 'cantidad' => 3, 'typePayment' => 'Efectivo', 'sucursal' => 1]], $this->token);
        $this->assertSame(400, $estado);
        $this->assertSame(1, $this->stock($p));
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM sales_product')->fetchColumn());
    }

    public function testVentaSinConexionNuncaSeRechazaYDejaElStockEnCero(): void
    {
        $p = $this->producto(1, 'Pan', 1);
        $q = $this->producto(1, 'Leche', 10);
        [$estado, $r] = $this->vender([
            ['id' => $p, 'salePrice' => 100, 'cantidad' => 3, 'typePayment' => 'Efectivo', 'sucursal' => 1],
            ['id' => $q, 'salePrice' => 100, 'cantidad' => 2, 'typePayment' => 'Efectivo', 'sucursal' => 1],
        ], $this->tokenEmpleado, $this->haceUnaHora(), 'offline-faltante-01');

        $this->assertSame(200, $estado);
        $this->assertTrue($r['datos'][0]);
        $this->assertSame([['id_product' => $p, 'description' => 'Pan', 'faltante' => 2]], $r['datos'][1]['faltantes']);
        $this->assertSame(0, $this->stock($p));
        $this->assertSame(8, $this->stock($q));
        $this->assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM sales_product')->fetchColumn());

        $f = $this->pdo()->query('SELECT * FROM stock_faltante')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $f);
        $this->assertSame([3, 1, 2, 1], array_map('intval', [$f[0]['cantidad_vendida'], $f[0]['stock_antes'], $f[0]['faltante'], $f[0]['sin_conexion']]));

        // Aviso solo para el administrador.
        $n = $this->pdo()->query("SELECT usuario_id, titulo FROM notificaciones")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $n);
        $this->assertSame([$this->idTitular, 'Stock a revisar'], [(int) $n[0]['usuario_id'], $n[0]['titulo']]);
    }

    public function testVenderIgualConfirmadoDejaElStockEnCero(): void
    {
        $p = $this->producto(1, 'Pan', 0);
        [$estado] = $this->vender([['id' => $p, 'salePrice' => 100, 'cantidad' => 1, 'typePayment' => 'Efectivo', 'sucursal' => 1, 'sinStock' => true]], $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame(0, $this->stock($p));
        $this->assertSame(0, (int) $this->pdo()->query('SELECT sin_conexion FROM stock_faltante')->fetchColumn());
    }

    public function testVenderIgualNoPermiteProductosDeOtroNegocio(): void
    {
        $ajeno = $this->producto(2, 'Ajeno', 5);
        [$estado] = $this->vender([['id' => $ajeno, 'salePrice' => 100, 'cantidad' => 1, 'typePayment' => 'Efectivo', 'sucursal' => 1, 'sinStock' => true]], $this->token, $this->haceUnaHora());
        $this->assertSame(400, $estado);
        $this->assertSame(5, $this->stock($ajeno));
    }

    public function testStockARevisarSoloTitularYSoloSuNegocio(): void
    {
        $p = $this->producto(1, 'Pan', 0);
        $this->vender([['id' => $p, 'salePrice' => 100, 'cantidad' => 2, 'typePayment' => 'Efectivo', 'sucursal' => 1]], $this->token, $this->haceUnaHora());

        [$estado] = $this->pedir('GET', '/api/salesProduct/faltantes', null, $this->tokenEmpleado);
        $this->assertSame(403, $estado);

        [, $otro] = $this->pedir('GET', '/api/salesProduct/faltantes', null, $this->tokenOtro);
        $this->assertSame([], $otro['datos']);

        [$estado, $r] = $this->pedir('GET', '/api/salesProduct/faltantes', null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertCount(1, $r['datos']);
        $this->assertSame('Pan', $r['datos'][0]['description']);
        $id = (int) $r['datos'][0]['id'];

        [, $avisos] = $this->pedir('GET', '/api/avisos/1', null, $this->token);
        $this->assertContains('Stock a revisar', array_column($avisos['datos'] ?? [], 'titulo'));

        // Otro negocio no puede marcarlo.
        [, $m] = $this->pedir('POST', '/api/salesProduct/faltantes/revisados', ['ids' => [$id]], $this->tokenOtro);
        $this->assertSame(0, $m['datos']['marcados']);

        [, $m] = $this->pedir('POST', '/api/salesProduct/faltantes/revisados', ['ids' => [$id]], $this->token);
        $this->assertSame(1, $m['datos']['marcados']);
        [, $r] = $this->pedir('GET', '/api/salesProduct/faltantes', null, $this->token);
        $this->assertSame([], $r['datos']);
    }
}
