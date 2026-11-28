<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** "Consultá el precio": cartel con QR y escaneo de etiquetas sin sesión. */
class ConsultaPrecioTest extends KontiaTestCase
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
        foreach ([
            ['Remera lisa', 'S', 5, 0, 'K1', 1, '7790001'],
            ['Remera lisa', 'M', 0, 0, 'K2', 1, null],
            ['Remera lisa', 'L', 2, 1, 'K3', 1, null], // borrada
            ['Mesa ratona', null, 1, 0, 'K4', 2, null], // de otro negocio
        ] as [$nombre, $talle, $stock, $activo, $codigo, $negocio, $barra]) {
            $this->insertar('INSERT INTO product (description, size, cost_price, sale_price, quantity, code, barcode, activo, id_negocio, sucursal) VALUES (?, ?, 50, 100, ?, ?, ?, ?, ?, 1)',
                [$nombre, $talle, $stock, $codigo, $barra, $activo, $negocio]);
        }
    }

    public function testSoloElAdministradorConfigura(): void
    {
        [$estado] = $this->pedir('GET', '/api/consulta-precio/config', null, $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado] = $this->pedir('POST', '/api/consulta-precio/config', ['activo' => true, 'slug' => 'x-y-z'], $this->tokenEmpleado);
        $this->assertSame(403, $estado);
    }

    public function testApagadoNoSeVeYPrendidoMuestraPrecio(): void
    {
        [$estado, $r] = $this->pedir('GET', '/api/consulta-precio/config', null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertFalse($r['datos']['activo']);

        // Sin link no se puede prender.
        [$estado] = $this->pedir('POST', '/api/consulta-precio/config', ['activo' => true], $this->token);
        $this->assertSame(409, $estado);

        [$estado, $r] = $this->pedir('POST', '/api/consulta-precio/config', ['activo' => false, 'slug' => 'Casa Lola'], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertSame('casa-lola', $r['datos']['slug']);
        [$estado] = $this->pedir('GET', '/api/publico/precio/casa-lola');
        $this->assertSame(404, $estado);

        [$estado] = $this->pedir('POST', '/api/consulta-precio/config', ['activo' => true], $this->token);
        $this->assertSame(200, $estado);
        [$estado, $r] = $this->pedir('GET', '/api/publico/precio/casa-lola');
        $this->assertSame(200, $estado);
        $this->assertFalse($r['datos']['catalogo']);

        // Por el código de Kontia (el del QR de la etiqueta).
        [$estado, $r] = $this->pedir('GET', '/api/publico/precio/casa-lola/K1');
        $this->assertSame(200, $estado, json_encode($r));
        $p = $r['datos'];
        $this->assertSame('Remera lisa', $p['nombre']);
        $this->assertEquals(100, $p['precio']);
        $this->assertTrue($p['disponible']);
        $this->assertArrayNotHasKey('stock', $p);
        $this->assertArrayNotHasKey('cost_price', $p);
        // El otro talle activo aparece; el borrado no.
        $this->assertCount(1, $p['opciones']);
        $this->assertSame('M', $p['opciones'][0]['talle']);
        $this->assertFalse($p['opciones'][0]['disponible']);

        // Por código de barras también.
        [$estado, $r] = $this->pedir('GET', '/api/publico/precio/casa-lola/7790001');
        $this->assertSame(200, $estado);
        $this->assertSame('S', $r['datos']['talle']);

        // Borrado, de otro negocio o inexistente: no se encuentra.
        foreach (['K3', 'K4', 'nada'] as $codigo) {
            [$estado, $r] = $this->pedir('GET', "/api/publico/precio/casa-lola/$codigo");
            $this->assertSame(404, $estado, $codigo);
            $this->assertTrue($r['datos']['no_encontrado']);
        }
    }

    public function testElLinkEsElDelCatalogoYNoSePuedeRepetir(): void
    {
        [$estado] = $this->pedir('POST', '/api/consulta-precio/config', ['activo' => true, 'slug' => 'casa-lola'], $this->token);
        $this->assertSame(200, $estado);
        [, $r] = $this->pedir('GET', '/api/catalogo/config', null, $this->token);
        $this->assertSame('casa-lola', $r['datos']['slug']);

        [$estado, $r] = $this->pedir('POST', '/api/consulta-precio/config', ['activo' => true, 'slug' => 'casa-lola'], $this->tokenOtro);
        $this->assertSame(409, $estado);
        $this->assertStringContainsString('otro negocio', $r['errores'][0]);
    }
}
