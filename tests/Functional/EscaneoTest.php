<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/**
 * "Escanear con el celular" desde Caja: la PC abre la sesión, el celular se
 * vincula con el token del QR y manda códigos que la PC levanta.
 */
class EscaneoTest extends KontiaTestCase
{
    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1]);
        $this->crearNegocio(2, [1, 2]);
        $this->crearUsuario('a@test.com', 1);
        $this->crearUsuario('otro@test.com', 1);
        $this->insertar("INSERT INTO product (description, cost_price, sale_price, quantity, code, barcode, activo, id_negocio, sucursal) VALUES ('Yerba 1kg', 100, 2500, 7, 'Y1', '7790001000017', 0, 1, 1)");
        $this->insertar("INSERT INTO product (description, cost_price, sale_price, quantity, code, barcode, activo, id_negocio, sucursal) VALUES ('Secreto B', 10, 20, 5, 'B1', '7790001000099', 0, 2, 1)");
        $this->tokenA = $this->tokenPara('a@test.com');
    }

    /** @return array{0: int, 1: string} id de la sesión y token del QR */
    private function abrirSesion(): array
    {
        [$codigo, $json] = $this->pedir('POST', '/api/escaneo/sesiones', ['sucursal' => 1], $this->tokenA);
        $this->assertSame(201, $codigo);
        return [$json['datos']['id'], $json['datos']['token']];
    }

    private function vincular(string $token): string
    {
        [$codigo, $json] = $this->pedir('POST', '/api/escaneo/movil/vincular', ['token' => $token]);
        $this->assertSame(200, $codigo);
        return $json['datos']['clave'];
    }

    public function testFlujoCompleto(): void
    {
        [$id, $token] = $this->abrirSesion();

        [, $json] = $this->pedir('GET', "/api/escaneo/sesiones/$id", null, $this->tokenA);
        $this->assertSame('esperando', $json['datos']['estado']);

        $clave = $this->vincular($token);
        [, $json] = $this->pedir('GET', "/api/escaneo/sesiones/$id", null, $this->tokenA);
        $this->assertSame('vinculada', $json['datos']['estado']);

        // Por código de barras: el celular ve el producto de su negocio.
        [$codigo, $json] = $this->pedir('POST', '/api/escaneo/movil/codigo', ['clave' => $clave, 'codigo' => '7790001000017']);
        $this->assertSame(200, $codigo);
        $this->assertSame('Yerba 1kg', $json['datos']['producto']['description']);

        // Un producto de otro negocio no se muestra (pero el código llega igual a la PC).
        [, $json] = $this->pedir('POST', '/api/escaneo/movil/codigo', ['clave' => $clave, 'codigo' => '7790001000099']);
        $this->assertNull($json['datos']['producto']);

        [, $json] = $this->pedir('GET', "/api/escaneo/sesiones/$id", null, $this->tokenA);
        $codigos = $json['datos']['codigos'];
        $this->assertSame(['7790001000017', '7790001000099'], array_column($codigos, 'codigo'));

        // Con ?desde= solo vienen los nuevos.
        [, $json] = $this->pedir('GET', "/api/escaneo/sesiones/$id?desde=" . $codigos[0]['id'], null, $this->tokenA);
        $this->assertSame(['7790001000099'], array_column($json['datos']['codigos'], 'codigo'));

        // Al cerrar desde la PC, el celular ya no puede mandar.
        [$codigo] = $this->pedir('POST', "/api/escaneo/sesiones/$id/cerrar", null, $this->tokenA);
        $this->assertSame(200, $codigo);
        [$codigo, $json] = $this->pedir('POST', '/api/escaneo/movil/codigo', ['clave' => $clave, 'codigo' => 'Y1']);
        $this->assertSame(410, $codigo);
        $this->assertSame('cerrada', $json['datos']['estado']);
    }

    public function testElQrEsDeUnSoloUso(): void
    {
        [, $token] = $this->abrirSesion();
        $this->vincular($token);
        [$codigo] = $this->pedir('POST', '/api/escaneo/movil/vincular', ['token' => $token]);
        $this->assertSame(410, $codigo);
    }

    public function testQrVencidoNoVincula(): void
    {
        [$id, $token] = $this->abrirSesion();
        $this->pdo()->prepare('UPDATE escaneo_sesion SET vence_vinculo = NOW() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$id]);
        [$codigo] = $this->pedir('POST', '/api/escaneo/movil/vincular', ['token' => $token]);
        $this->assertSame(410, $codigo);
    }

    public function testClaveInvalidaDa403(): void
    {
        [$codigo] = $this->pedir('POST', '/api/escaneo/movil/codigo', ['clave' => str_repeat('a', 43), 'codigo' => 'Y1']);
        $this->assertSame(403, $codigo);
        [$codigo] = $this->pedir('POST', '/api/escaneo/movil/codigo', ['clave' => 'corta', 'codigo' => 'Y1']);
        $this->assertSame(403, $codigo);
    }

    public function testCodigoInvalidoDa422(): void
    {
        [, $token] = $this->abrirSesion();
        $clave = $this->vincular($token);
        [$codigo] = $this->pedir('POST', '/api/escaneo/movil/codigo', ['clave' => $clave, 'codigo' => '']);
        $this->assertSame(422, $codigo);
        [$codigo] = $this->pedir('POST', '/api/escaneo/movil/codigo', ['clave' => $clave, 'codigo' => str_repeat('9', 101)]);
        $this->assertSame(422, $codigo);
    }

    public function testOtroUsuarioNoVeLaSesion(): void
    {
        [$id] = $this->abrirSesion();
        $otro = $this->tokenPara('otro@test.com');
        [$codigo] = $this->pedir('GET', "/api/escaneo/sesiones/$id", null, $otro);
        $this->assertSame(404, $codigo);
        [$codigo] = $this->pedir('POST', "/api/escaneo/sesiones/$id/cerrar", null, $otro);
        $this->assertSame(404, $codigo);
    }

    public function testSucursalDeOtroNegocioDa403(): void
    {
        [$codigo] = $this->pedir('POST', '/api/escaneo/sesiones', ['sucursal' => 2], $this->tokenA);
        $this->assertSame(403, $codigo);
    }

    public function testSinLoginNoSeAbreSesion(): void
    {
        [$codigo] = $this->pedir('POST', '/api/escaneo/sesiones', ['sucursal' => 1]);
        $this->assertSame(401, $codigo);
    }
}
