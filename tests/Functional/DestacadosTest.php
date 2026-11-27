<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** Tarjetas "Para vos": nada negativo, y lo de plata solo para el administrador. */
class DestacadosTest extends KontiaTestCase
{
    private string $token;
    private string $tokenEmpleado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1]);
        $this->crearUsuario('titular@test.com', 1);
        $this->crearUsuario('empleado@test.com', 1, false);
        $this->token = $this->tokenPara('titular@test.com');
        $this->tokenEmpleado = $this->tokenPara('empleado@test.com');
    }

    private function producto(string $nombre, int $stock): int
    {
        return $this->insertar('INSERT INTO product (description, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES (?, 100, ?, ?, 0, 1, 1)',
            [$nombre, $stock, uniqid()]);
    }

    private function venta(int $producto, int $cantidad, float $precio, int $haceDias): void
    {
        static $id = 1;
        $fecha = (new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))
            ->modify("-$haceDias days")->format('Y-m-d') . ' 00:00:01';
        $this->insertar('INSERT INTO sales_product (id_sale, id_product, quantity, price, sale_product_date, type_payment, active, id_negocio, sucursal) VALUES (?, ?, ?, ?, ?, "efectivo", 0, 1, 1)',
            [$id++, $producto, $cantidad, $precio, $fecha]);
    }

    private function tipos(array $r): array
    {
        return array_column($r['datos'], 'tipo');
    }

    public function testStockParaTodosYRecordSoloParaElAdministrador(): void
    {
        $poco = $this->producto('Remera', 2);
        $agotado = $this->producto('Jean', 0);
        $this->venta($poco, 6, 100, 3);
        $this->venta($agotado, 5, 100, 2);
        for ($d = 1; $d <= 8; $d++) {
            $this->venta($poco, 0, 100, $d); // días con ventas chicas
        }
        $this->venta($poco, 1, 5000, 0); // hoy: récord

        [$estado, $r] = $this->pedir('GET', '/api/destacados?sucursal=1', null, $this->token);
        $this->assertSame(200, $estado);
        $tipos = $this->tipos($r);
        $this->assertSame('record', $tipos[0]);
        $this->assertContains('se_termina', $tipos);
        $this->assertContains('agotado', $tipos);
        $this->assertLessThanOrEqual(4, count($tipos));

        [, $r] = $this->pedir('GET', '/api/destacados?sucursal=1', null, $this->tokenEmpleado);
        $tipos = $this->tipos($r);
        $this->assertNotContains('record', $tipos);
        $this->assertNotContains('mejor_dia', $tipos);
        $this->assertNotContains('semana', $tipos);
        $this->assertContains('se_termina', $tipos);
    }

    public function testNoMuestraNadaNegativo(): void
    {
        $p = $this->producto('Gorra', 100);
        $this->venta($p, 1, 10000, 7); // el mismo día de la semana pasada se vendió mucho más
        $this->venta($p, 1, 100, 0);   // hoy, poco

        [, $r] = $this->pedir('GET', '/api/destacados?sucursal=1', null, $this->token);
        $this->assertSame([], $this->tipos($r));
        foreach ($r['datos'] as $t) {
            $this->assertDoesNotMatchRegularExpression('/-\d|menos/', $t['titulo'] . ' ' . $t['texto']);
        }
    }

    public function testMejorQueLaSemanaPasada(): void
    {
        $p = $this->producto('Buzo', 100);
        $this->venta($p, 1, 1000, 7);
        $this->venta($p, 1, 1500, 0);
        [, $r] = $this->pedir('GET', '/api/destacados?sucursal=1', null, $this->token);
        $this->assertSame('mejor_dia', $r['datos'][0]['tipo']);
        $this->assertSame('Hoy vas 50% arriba', $r['datos'][0]['titulo']);
    }
}
