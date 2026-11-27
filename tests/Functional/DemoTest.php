<?php

namespace App\Tests\Functional;

use App\Service\DemoService;
use App\Tests\KontiaTestCase;

/** "Probar sin registrarme": entra al negocio de ejemplo, que se puede usar pero no administrar. */
class DemoTest extends KontiaTestCase
{
    private function cuenta(string $tabla, int $negocio): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM `$tabla` WHERE id_negocio = ?");
        $stmt->execute([$negocio]);
        return (int) $stmt->fetchColumn();
    }

    public function testEntraAlNegocioDeEjemploConDatos(): void
    {
        $this->crearNegocio(1);
        $this->crearUsuario('titular@test.com', 1);

        [$estado, $r] = $this->pedir('POST', '/api/publico/demo');
        $this->assertSame(200, $estado);
        $this->assertTrue($r['demo']);
        $this->assertNotEmpty($r['token']);
        $negocio = (int) $r['id_negocio'];
        $this->assertNotSame(1, $negocio);
        $this->assertSame('activa', $r['suscripcion']['estado']);
        $this->assertSame(36, $this->cuenta('product', $negocio));
        $this->assertGreaterThan(100, $this->cuenta('sales_product', $negocio));
        $this->assertSame(3, $this->cuenta('proveedores', $negocio));

        // Un segundo ingreso usa el mismo negocio (no crea otro).
        [, $r2] = $this->pedir('POST', '/api/publico/demo');
        $this->assertSame($negocio, (int) $r2['id_negocio']);

        // El login normal no viene marcado como demo.
        [, $login] = $this->pedir('POST', '/api/usuario/login', ['username' => 'titular@test.com', 'password' => 'Clave1234']);
        $this->assertFalse($login['demo']);
    }

    public function testSePuedeVenderPeroNoAdministrar(): void
    {
        [, $r] = $this->pedir('POST', '/api/publico/demo');
        $token = $r['token'];
        $negocio = (int) $r['id_negocio'];

        // Se puede usar: preferencias, conteo.
        [$estado] = $this->pedir('POST', '/api/preferencias/inicio_accesos', ['valor' => ['vender', 'contar']], $token);
        $this->assertSame(200, $estado);
        $stmt = $this->pdo()->prepare('SELECT id FROM product WHERE id_negocio = ? LIMIT 1');
        $stmt->execute([$negocio]);
        $producto = (int) $stmt->fetchColumn();
        [$estado] = $this->pedir('POST', '/api/conteo', ['sucursal' => 1, 'items' => [['id' => $producto, 'contado' => 1]]], $token);
        $this->assertSame(201, $estado);

        // No se puede tocar la cuenta, empleados, sucursales, plan ni IA.
        foreach ([
            '/api/empleados/nuevo', '/api/negocio/nuevo_negocio', '/api/negocio/eliminar_negocio',
            '/api/suscripcion/checkout', '/api/asistente/mensaje', '/api/facturas/leer', '/api/catalogo/config',
            '/api/products/importar', '/api/products/masivo',
        ] as $url) {
            [$estado, $resp] = $this->pedir('POST', $url, ['x' => 1], $token);
            $this->assertSame(403, $estado, $url);
            $this->assertSame('demo', $resp['datos']['codigo'], $url);
        }
    }

    public function testReiniciarBorraLoCargado(): void
    {
        [, $r] = $this->pedir('POST', '/api/publico/demo');
        $negocio = (int) $r['id_negocio'];
        $ventas = $this->cuenta('sales_product', $negocio);
        $this->insertar('INSERT INTO product (description, quantity, id_negocio, sucursal) VALUES (?, 1, ?, 1)', ['Cargado por alguien', $negocio]);
        $this->insertar('INSERT INTO negocio (id_negocio, sucursal, nombre) VALUES (?, 2, ?)', [$negocio, 'Sucursal nueva']);
        $this->crearUsuario('empleado-demo@test.com', $negocio, false);
        $this->crearNegocio(9);
        $ajeno = $this->insertar('INSERT INTO product (description, quantity, id_negocio, sucursal) VALUES (?, 1, 9, 1)', ['De otro negocio']);

        static::getContainer()->get(DemoService::class)->reiniciar();

        $this->assertSame(36, $this->cuenta('product', $negocio));
        $this->assertSame($ventas, $this->cuenta('sales_product', $negocio));
        $this->assertSame(1, $this->cuenta('negocio', $negocio));
        $this->assertSame(1, $this->cuenta('user', $negocio));
        $this->assertSame(1, $this->cuenta('product', 9));
        $this->assertGreaterThan(0, $ajeno);
    }
}
