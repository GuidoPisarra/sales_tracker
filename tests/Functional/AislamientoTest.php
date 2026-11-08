<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/**
 * Test de fuga entre negocios: con el token del negocio A se piden datos del
 * negocio B por todas las rutas que reciben un id. Ninguna puede devolverlos.
 */
class AislamientoTest extends KontiaTestCase
{
    private string $tokenA;
    private array $idsB = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1]);
        $this->crearNegocio(2, [1, 2]);
        $this->crearUsuario('a@test.com', 1);
        $empleadoB = $this->crearUsuario('b@test.com', 2);

        $this->idsB['producto'] = $this->insertar("INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES ('Secreto B', 10, 20, 5, 'B1', 0, 2, 1)");
        $this->idsB['cliente'] = $this->insertar("INSERT INTO clientes (id_negocio, dni, apellido, nombre) VALUES (2, '1', 'Cliente', 'B')");
        $this->idsB['ctacte'] = $this->insertar("INSERT INTO ctacte (id_persona, precio_original, precio_actual, id_negocio) VALUES (?, 100, 100, 2)", [$this->idsB['cliente']]);
        $this->idsB['pago'] = $this->insertar("INSERT INTO pagos (id_persona, id_ctacte, entrega, fecha) VALUES (?, ?, 10, NOW())", [$this->idsB['cliente'], $this->idsB['ctacte']]);
        $this->idsB['empleado'] = $empleadoB;
        $this->insertar("INSERT INTO sales (id_product, id_negocio) VALUES (?, 2)", [$this->idsB['producto']]);

        $this->tokenA = $this->tokenPara('a@test.com');
    }

    public function rutasGetConIdDeNegocio(): array
    {
        return [
            ['/api/avisos/2'],
            ['/api/clientes/obtener_clientes/2'],
            ['/api/cuenta_corriente/obtener_cuentas_corrientes/2'],
            ['/api/cuenta_corriente/obtener_cuentas_corrientes_con_deuda/2'],
            ['/api/empleados/2'],
            ['/api/expenses/expenses/2'],
            ['/api/negocio/obtener_negocios/2'],
            ['/api/precios/desactualizados/2'],
            ['/api/products/products_list/2'],
            ['/api/products/products_one/B1/2/1'],
            ['/api/products/products/price_percent/10/2/0'],
            ['/api/proveedores/proveedores_list/2'],
            ['/api/report/salesProduct/2'],
            ['/api/report/salesProduct/recientes/2'],
            ['/api/report/incomesExpenses/1/2026/2'],
        ];
    }

    /** @dataProvider rutasGetConIdDeNegocio */
    public function testRutasConIdDeOtroNegocioDan403(string $url): void
    {
        [$codigo] = $this->pedir('GET', $url, null, $this->tokenA);
        $this->assertSame(403, $codigo, "La ruta $url dejó ver datos de otro negocio");
    }

    public function testRutasSinIdUsanElNegocioDelToken(): void
    {
        [$codigo, $json] = $this->pedir('GET', '/api/products/products_list', null, $this->tokenA);
        $this->assertSame(200, $codigo);
        $this->assertStringNotContainsString('Secreto B', json_encode($json));

        [$codigo] = $this->pedir('GET', '/api/products/products_one/B1/1', null, $this->tokenA);
        $this->assertNotSame(403, $codigo);
    }

    public function testSucursalDeOtroNegocioDa403(): void
    {
        // La sucursal 2 existe solo en el negocio 2.
        [$codigo] = $this->pedir('GET', '/api/products/products_one/B1/2', null, $this->tokenA);
        $this->assertSame(403, $codigo);
    }

    public function testRutasPorIdDeRegistroAjenoDan403(): void
    {
        $casos = [
            ['GET', '/api/cuenta_corriente/obtener_movimientos_cliente/' . $this->idsB['cliente'], null],
            ['PATCH', '/api/cuenta_corriente/editar_cliente/' . $this->idsB['cliente'], ['dni' => '9', 'apellido' => 'X', 'nombre' => 'Y', 'telefono' => '1']],
            ['DELETE', '/api/cuenta_corriente/anular_pago/' . $this->idsB['pago'], null],
            ['DELETE', '/api/cuenta_corriente/anular_venta/' . $this->idsB['ctacte'], null],
            ['DELETE', '/api/empleados/' . $this->idsB['empleado'], null],
            ['PATCH', '/api/empleados/' . $this->idsB['empleado'], ['name' => 'X', 'email' => 'x@x.com']],
            ['POST', '/api/products/products/delete', ['id' => $this->idsB['producto']]],
        ];
        foreach ($casos as [$metodo, $url, $body]) {
            [$codigo] = $this->pedir($metodo, $url, $body, $this->tokenA);
            $this->assertContains($codigo, [400, 403, 404], "$metodo $url devolvió $codigo");
        }

        // Y nada del negocio B cambió.
        $this->assertSame('Secreto B', $this->pdo()->query('SELECT description FROM product WHERE id = ' . $this->idsB['producto'])->fetchColumn());
        $this->assertSame('0', (string) $this->pdo()->query('SELECT anulado FROM pagos WHERE id = ' . $this->idsB['pago'])->fetchColumn());
        $this->assertSame('0', (string) $this->pdo()->query('SELECT eliminado FROM user WHERE id = ' . $this->idsB['empleado'])->fetchColumn());
    }

    public function testSinTokenDa401(): void
    {
        [$codigo] = $this->pedir('GET', '/api/products/products_list');
        $this->assertSame(401, $codigo);
    }
}
