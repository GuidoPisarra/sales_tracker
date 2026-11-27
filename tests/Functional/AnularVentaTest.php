<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** Anular una venta (deslizar en Ventas): solo administrador, devuelve el stock. */
class AnularVentaTest extends KontiaTestCase
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

    private function producto(int $stock): int
    {
        return $this->insertar('INSERT INTO product (description, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES ("P", 100, ?, ?, 0, 1, 1)', [$stock, uniqid()]);
    }

    private function linea(int $idSale, int $producto, int $cantidad, string $pago = 'efectivo'): void
    {
        $this->insertar('INSERT INTO sales_product (id_sale, id_product, quantity, price, sale_product_date, type_payment, active, id_negocio, sucursal) VALUES (?, ?, ?, 100, NOW(), ?, 0, 1, 1)',
            [$idSale, $producto, $cantidad, $pago]);
    }

    private function stock(int $id): int
    {
        $s = $this->pdo()->prepare('SELECT quantity FROM product WHERE id = ?');
        $s->execute([$id]);
        return (int) $s->fetchColumn();
    }

    public function testAnulaYDevuelveElStock(): void
    {
        $a = $this->producto(5);
        $b = $this->producto(0);
        $this->linea(10, $a, 2);
        $this->linea(10, $b, 3);
        // De B se vendieron 3 sin stock: 3 nunca se descontaron.
        $this->insertar('INSERT INTO stock_faltante (id_negocio, sucursal, id_product, id_sale, cantidad_vendida, stock_antes, faltante, fecha_venta) VALUES (1, 1, ?, 10, 3, 0, 3, NOW())', [$b]);

        [$estado] = $this->pedir('POST', '/api/salesProduct/anular', ['idSale' => 10], $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado] = $this->pedir('POST', '/api/salesProduct/anular', ['idSale' => 10], $this->tokenOtro);
        $this->assertSame(404, $estado);

        [$estado] = $this->pedir('POST', '/api/salesProduct/anular', ['idSale' => 10], $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame(7, $this->stock($a));
        $this->assertSame(0, $this->stock($b));
        $activas = (int) $this->pdo()->query('SELECT COUNT(*) FROM sales_product WHERE id_sale = 10 AND active = 0')->fetchColumn();
        $this->assertSame(0, $activas);

        // Dos veces no.
        [$estado] = $this->pedir('POST', '/api/salesProduct/anular', ['idSale' => 10], $this->token);
        $this->assertSame(404, $estado);
        $this->assertSame(7, $this->stock($a));
    }

    public function testCuentaCorrienteNoSeAnula(): void
    {
        $a = $this->producto(5);
        $this->linea(11, $a, 1, 'Cta Cte');
        [$estado] = $this->pedir('POST', '/api/salesProduct/anular', ['idSale' => 11], $this->token);
        $this->assertSame(409, $estado);
        $this->assertSame(5, $this->stock($a));
    }
}
