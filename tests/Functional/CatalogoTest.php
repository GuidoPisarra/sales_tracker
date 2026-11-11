<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** Catálogo online compartible: lo configura el administrador y se ve sin sesión. */
class CatalogoTest extends KontiaTestCase
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
        foreach ([['Remera', 5, 0], ['Buzo', 0, 0], ['Borrado', 3, 1]] as [$nombre, $stock, $activo]) {
            $this->insertar('INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES (?, 50, 100, ?, ?, ?, 1, 1)',
                [$nombre, $stock, 'C' . $nombre, $activo]);
        }
    }

    private function activar(array $extra = []): array
    {
        return $this->pedir('POST', '/api/catalogo/config', array_merge(['activo' => true, 'slug' => 'Mi Tienda', 'whatsapp' => '+54 9 11 2233-4455'], $extra), $this->token);
    }

    public function testApagadoNoSeVe(): void
    {
        [$estado] = $this->pedir('GET', '/api/publico/catalogo/mi-tienda');
        $this->assertSame(404, $estado);
        [$estado, $r] = $this->pedir('GET', '/api/catalogo/config', null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertFalse($r['datos']['activo']);
    }

    public function testSoloElAdministradorLoConfigura(): void
    {
        [$estado] = $this->pedir('GET', '/api/catalogo/config', null, $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado] = $this->pedir('POST', '/api/catalogo/config', ['activo' => true, 'slug' => 'x-y-z', 'whatsapp' => '5491122334455'], $this->tokenEmpleado);
        $this->assertSame(403, $estado);
    }

    public function testPublicoSinCostoYSinProductosSinStock(): void
    {
        [$estado, $r] = $this->activar();
        $this->assertSame(200, $estado);
        $this->assertSame('mi-tienda', $r['datos']['slug']);
        $this->assertSame('5491122334455', $r['datos']['whatsapp']);

        [$estado, $r] = $this->pedir('GET', '/api/publico/catalogo/mi-tienda');
        $this->assertSame(200, $estado);
        $this->assertSame('Negocio 1 suc 1', $r['datos']['titulo']);
        $this->assertSame(['Remera'], array_column($r['datos']['productos'], 'nombre'));
        $p = $r['datos']['productos'][0];
        $this->assertArrayNotHasKey('stock', $p);
        $this->assertStringNotContainsString('cost', json_encode($r['datos']));

        $this->activar(['sin_stock' => true, 'mostrar_stock' => true, 'titulo' => 'Ropa Linda']);
        [, $r] = $this->pedir('GET', '/api/publico/catalogo/mi-tienda');
        $this->assertSame('Ropa Linda', $r['datos']['titulo']);
        $this->assertSame(['Buzo', 'Remera'], array_column($r['datos']['productos'], 'nombre'));
        $this->assertSame([0, 5], array_column($r['datos']['productos'], 'stock'));
        $this->assertSame([false, true], array_column($r['datos']['productos'], 'disponible'));

        $this->pedir('POST', '/api/catalogo/config', ['activo' => false], $this->token);
        [$estado] = $this->pedir('GET', '/api/publico/catalogo/mi-tienda');
        $this->assertSame(404, $estado);
    }

    public function testLinkUnicoYValidaciones(): void
    {
        $this->activar();
        [$estado, $r] = $this->pedir('POST', '/api/catalogo/config', ['activo' => true, 'slug' => 'mi-tienda', 'whatsapp' => '5491100000000'], $this->tokenOtro);
        $this->assertSame(409, $estado);
        $this->assertStringContainsString('otro negocio', $r['errores'][0]);

        [$estado] = $this->pedir('POST', '/api/catalogo/config', ['activo' => true, 'slug' => 'otro-link', 'whatsapp' => ''], $this->tokenOtro);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/catalogo/config', ['activo' => true, 'slug' => 'ab', 'whatsapp' => '5491100000000'], $this->tokenOtro);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/catalogo/config', ['sucursal' => 9], $this->token);
        $this->assertSame(409, $estado);
    }

    /** Un negocio cuyas sucursales no empiezan en 1 usa su primera sucursal por defecto. */
    public function testSucursalPorDefectoEsLaDelNegocio(): void
    {
        $this->crearNegocio(3, [4, 7]);
        $this->crearUsuario('tres@test.com', 3);
        $token = $this->tokenPara('tres@test.com');

        [$estado, $r] = $this->pedir('GET', '/api/catalogo/config', null, $token);
        $this->assertSame(200, $estado);
        $this->assertSame(4, $r['datos']['sucursal']);

        [$estado, $r] = $this->pedir('POST', '/api/catalogo/config', ['activo' => true, 'slug' => 'tienda-tres', 'whatsapp' => '5491122334455'], $token);
        $this->assertSame(200, $estado);
        $this->assertSame(4, $r['datos']['sucursal']);
    }
}
