<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** Conteo de stock: el empleado cuenta, el administrador aplica; se respetan las ventas del medio. */
class ConteoTest extends KontiaTestCase
{
    private string $token;
    private string $tokenEmpleado;
    private string $tokenOtro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1, 2]);
        $this->crearNegocio(2, [1]);
        $this->crearUsuario('titular@test.com', 1);
        $this->crearUsuario('empleado@test.com', 1, false);
        $this->crearUsuario('otro@test.com', 2);
        $this->token = $this->tokenPara('titular@test.com');
        $this->tokenEmpleado = $this->tokenPara('empleado@test.com');
        $this->tokenOtro = $this->tokenPara('otro@test.com');
    }

    private function producto(int $negocio, int $sucursal, int $stock, float $costo = 100, float $precio = 200): int
    {
        return $this->insertar(
            'INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES (?, ?, ?, ?, ?, 0, ?, ?)',
            ['Producto ' . uniqid(), $costo, $precio, $stock, uniqid(), $negocio, $sucursal]
        );
    }

    private function stock(int $id): int
    {
        $stmt = $this->pdo()->prepare('SELECT quantity FROM product WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    public function testEmpleadoCuentaYElAdministradorAplica(): void
    {
        $a = $this->producto(1, 1, 10);           // contó 8: faltan 2
        $b = $this->producto(1, 1, 5, 0, 300);    // contó 7: sobran 2 (sin costo, vale al precio)
        $c = $this->producto(1, 1, 3);            // contó 3: bien

        [$estado, $r] = $this->pedir('POST', '/api/conteo', [
            'sucursal' => 1, 'alcance' => 'Remeras', 'aplicar' => true,
            'items' => [['id' => $a, 'contado' => 8], ['id' => $b, 'contado' => 7], ['id' => $c, 'contado' => 3]],
        ], $this->tokenEmpleado);
        $this->assertSame(201, $estado);
        // El empleado no puede aplicar: queda pendiente aunque pida "aplicar".
        $this->assertSame('pendiente', $r['datos']['estado']);
        $this->assertSame('Remeras', $r['datos']['alcance']);
        $res = $r['datos']['resumen'];
        $this->assertSame(3, $res['productos']);
        $this->assertSame(2, $res['con_diferencia']);
        $this->assertSame(2, $res['unidades_faltantes']);
        $this->assertSame(2, $res['unidades_sobrantes']);
        $this->assertSame(200.0, (float) $res['valor_faltante']);
        $this->assertSame(600.0, (float) $res['valor_sobrante']);
        $this->assertSame(10, $this->stock($a));
        $id = $r['datos']['id'];

        // Mientras tanto se vende 1 de A: al aplicar se respeta (10 - 1 - 2 = 7).
        $this->pdo()->prepare('UPDATE product SET quantity = quantity - 1 WHERE id = ?')->execute([$a]);

        [$estado] = $this->pedir('POST', "/api/conteo/$id/aplicar", null, $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado, $r] = $this->pedir('GET', '/api/conteo?estado=pendiente', null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertCount(1, $r['datos']);

        [$estado, $r] = $this->pedir('POST', "/api/conteo/$id/aplicar", null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame('aplicado', $r['datos']['estado']);
        $this->assertCount(3, $r['datos']['items']);
        $this->assertSame(7, $this->stock($a));
        $this->assertSame(7, $this->stock($b));
        $this->assertSame(3, $this->stock($c));

        // No se aplica dos veces.
        [$estado] = $this->pedir('POST', "/api/conteo/$id/aplicar", null, $this->token);
        $this->assertSame(409, $estado);
        $this->assertSame(7, $this->stock($a));
    }

    public function testElAdministradorAplicaEnElMomentoYPuedeDescartar(): void
    {
        $a = $this->producto(1, 1, 4);
        [$estado, $r] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'aplicar' => true, 'items' => [['id' => $a, 'contado' => 0]]], $this->token);
        $this->assertSame(201, $estado);
        $this->assertSame('aplicado', $r['datos']['estado']);
        $this->assertSame(0, $this->stock($a));

        [, $r] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $a, 'contado' => 9]]], $this->tokenEmpleado);
        $id = $r['datos']['id'];
        [$estado, $r] = $this->pedir('POST', "/api/conteo/$id/descartar", null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame('descartado', $r['datos']['estado']);
        $this->assertSame(0, $this->stock($a));
    }

    public function testValidacionesYAislamiento(): void
    {
        $propio = $this->producto(1, 1, 4);
        $otraSucursal = $this->producto(1, 2, 4);
        $ajeno = $this->producto(2, 1, 4);

        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => []], $this->token);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $propio, 'contado' => -1]]], $this->token);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $propio, 'contado' => 1.5]]], $this->token);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $otraSucursal, 'contado' => 1]]], $this->token);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $ajeno, 'contado' => 1]]], $this->token);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $propio, 'contado' => 1]]], $this->tokenOtro);
        $this->assertSame(409, $estado); // el producto no es de su negocio
        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 2, 'items' => [['id' => $propio, 'contado' => 1]]], $this->tokenOtro);
        $this->assertSame(403, $estado); // sucursal que no es suya

        [, $r] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $propio, 'contado' => 1]]], $this->token);
        $id = $r['datos']['id'];
        // Otro negocio no lo ve ni lo aplica; el empleado no ve conteos que no son suyos.
        [$estado] = $this->pedir('GET', "/api/conteo/$id", null, $this->tokenOtro);
        $this->assertSame(404, $estado);
        [$estado] = $this->pedir('POST', "/api/conteo/$id/aplicar", null, $this->tokenOtro);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('GET', "/api/conteo/$id", null, $this->tokenEmpleado);
        $this->assertSame(404, $estado);
        [, $r] = $this->pedir('GET', '/api/conteo', null, $this->tokenEmpleado);
        $this->assertCount(0, $r['datos']);
        $this->assertSame(4, $this->stock($propio));
    }
}
