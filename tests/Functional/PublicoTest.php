<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

class PublicoTest extends KontiaTestCase
{
    public function testInfoPublicaSinSesion(): void
    {
        $_ENV['LEGAL_CUIT'] = '20-32668607-8';
        $_ENV['SOPORTE_WHATSAPP'] = '+54 9 2983 69-4278';
        [$codigo, $json] = $this->pedir('GET', '/api/publico/info');
        unset($_ENV['LEGAL_CUIT'], $_ENV['SOPORTE_WHATSAPP']);
        $this->assertSame(200, $codigo);
        $this->assertSame('20-32668607-8', $json['datos']['legal']['cuit']);
        $this->assertSame('5492983694278', $json['datos']['soporte']['whatsapp']);
        $this->assertSame(7, $json['datos']['dias_prueba']);
    }

    public function testPedidoDeBajaQuedaRegistrado(): void
    {
        $this->crearNegocio(5, [1], 'pro');
        $this->crearUsuario('titular@test.com', 5);

        [$codigo, $json] = $this->pedir('POST', '/api/publico/solicitud', ['tipo' => 'baja', 'email' => 'Titular@test.com', 'motivo' => 'Cierro el local']);
        $this->assertSame(201, $codigo);
        $this->assertMatchesRegularExpression('/^B-\d{6}-[0-9A-F]{6}$/', $json['datos']['codigo']);

        $fila = $this->pdo()->query("SELECT id_negocio, detalle FROM auditoria WHERE accion = 'solicitud_baja'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(5, (int) $fila['id_negocio']);
        $this->assertSame('Cierro el local', json_decode($fila['detalle'], true)['motivo']);
    }

    public function testPedidoInvalido(): void
    {
        [$codigo] = $this->pedir('POST', '/api/publico/solicitud', ['tipo' => 'otra', 'email' => 'a@test.com']);
        $this->assertSame(400, $codigo);
        [$codigo] = $this->pedir('POST', '/api/publico/solicitud', ['tipo' => 'arrepentimiento', 'email' => 'no-es-mail']);
        $this->assertSame(400, $codigo);
    }

    public function testPrimerosPasos(): void
    {
        $this->crearNegocio(3, [1], 'pro');
        $this->crearUsuario('t@test.com', 3);
        $token = $this->tokenPara('t@test.com');
        [$codigo, $json] = $this->pedir('GET', '/api/onboarding', null, $token);
        $this->assertSame(200, $codigo);
        $this->assertSame(['productos' => false, 'venta' => false, 'empleados' => false, 'catalogo' => false], $json['datos']);

        $this->insertar("INSERT INTO product (description, code, activo, id_negocio, sucursal) VALUES ('Uno', 'U1', 0, 3, 1)");
        $this->crearUsuario('empleado@test.com', 3, false);
        [, $json] = $this->pedir('GET', '/api/onboarding', null, $token);
        $this->assertTrue($json['datos']['productos']);
        $this->assertTrue($json['datos']['empleados']);
        $this->assertFalse($json['datos']['venta']);
    }
}
