<?php

namespace App\Tests\Functional;

use App\Service\CajaService;
use App\Tests\KontiaTestCase;

/** Apertura y cierre de caja: opcional, un turno abierto por sucursal, arqueo al cerrar. */
class CajaTest extends KontiaTestCase
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

    private function venta(int $negocio, int $sucursal, float $precio, int $cantidad, string $pago, ?string $fecha = null, int $anulada = 0): void
    {
        static $idSale = 100;
        $idSale++;
        $this->insertar(
            'INSERT INTO sales_product (id_sale, id_product, quantity, price, sale_product_date, type_payment, active, id_negocio, sucursal) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)',
            [$idSale, $cantidad, $precio, $fecha ?? CajaService::ahora(), $pago, $anulada, $negocio, $sucursal]
        );
    }

    public function testApagadaPorDefectoYNoSePuedeAbrir(): void
    {
        [$estado, $r] = $this->pedir('GET', '/api/caja/estado?sucursal=1', null, $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        $this->assertSame(['activa' => false, 'turno' => null], $r['datos']);

        [$estado] = $this->pedir('POST', '/api/caja/abrir', ['sucursal' => 1, 'monto_inicial' => 1000], $this->tokenEmpleado);
        $this->assertSame(409, $estado);
    }

    public function testSoloElAdministradorLaActiva(): void
    {
        [$estado] = $this->pedir('POST', '/api/caja/config', ['activa' => true], $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado, $r] = $this->pedir('POST', '/api/caja/config', ['activa' => true], $this->token);
        $this->assertSame(200, $estado);
        $this->assertTrue($r['datos']['activa']);
        // No afecta a otro negocio.
        [, $r] = $this->pedir('GET', '/api/caja/estado?sucursal=1', null, $this->tokenOtro);
        $this->assertFalse($r['datos']['activa']);
    }

    public function testTurnoCompletoConArqueo(): void
    {
        $this->pedir('POST', '/api/caja/config', ['activa' => true], $this->token);
        $this->venta(1, 1, 999, 1, 'efectivo', '2020-01-01 10:00:00'); // antes de abrir: no cuenta

        [$estado, $r] = $this->pedir('POST', '/api/caja/abrir', ['sucursal' => 1, 'monto_inicial' => 1000, 'observacion' => 'Cambio'], $this->tokenEmpleado);
        $this->assertSame(201, $estado);
        $this->assertTrue($r['datos']['abierta']);
        $this->assertSame(1000.0, (float) $r['datos']['resumen']['esperado_efectivo']);

        // Segunda apertura en la misma sucursal: rechazada. En otra sucursal: se puede.
        [$estado] = $this->pedir('POST', '/api/caja/abrir', ['sucursal' => 1, 'monto_inicial' => 0], $this->token);
        $this->assertSame(409, $estado);
        [$estado] = $this->pedir('POST', '/api/caja/abrir', ['sucursal' => 2, 'monto_inicial' => 0], $this->token);
        $this->assertSame(201, $estado);

        $this->venta(1, 1, 500, 2, 'Efectivo');
        $this->venta(1, 1, 300, 1, 'tarjeta');
        $this->venta(1, 1, 700, 1, 'efectivo', null, 1); // anulada
        $this->venta(1, 2, 400, 1, 'efectivo');          // otra sucursal
        $this->venta(2, 1, 800, 1, 'efectivo');          // otro negocio

        [$estado] = $this->pedir('POST', '/api/caja/movimiento', ['sucursal' => 1, 'tipo' => 'retiro', 'monto' => 200, 'motivo' => 'Proveedor'], $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        [$estado, $r] = $this->pedir('POST', '/api/caja/movimiento', ['sucursal' => 1, 'tipo' => 'ingreso', 'monto' => 50], $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        $res = $r['datos']['resumen'];
        $this->assertSame(1000.0, (float) $res['ventas_efectivo']);
        $this->assertSame(1300.0, (float) $res['total_ventas']);
        $this->assertSame(2, $res['cantidad_ventas']);
        $this->assertSame(1850.0, (float) $res['esperado_efectivo']); // 1000 + 1000 + 50 - 200
        $this->assertCount(2, $r['datos']['movimientos']);

        [$estado] = $this->pedir('POST', '/api/caja/movimiento', ['sucursal' => 1, 'tipo' => 'otro', 'monto' => 50], $this->tokenEmpleado);
        $this->assertSame(409, $estado);

        [$estado, $r] = $this->pedir('POST', '/api/caja/cerrar', ['sucursal' => 1, 'contado' => 1800, 'observacion' => 'Faltan 50'], $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        $this->assertFalse($r['datos']['abierta']);
        $this->assertSame(1850.0, (float) $r['datos']['esperado_efectivo']);
        $this->assertSame(-50.0, (float) $r['datos']['diferencia']);

        [$estado] = $this->pedir('POST', '/api/caja/cerrar', ['sucursal' => 1, 'contado' => 0], $this->tokenEmpleado);
        $this->assertSame(409, $estado);

        // Se puede volver a abrir después de cerrar.
        [$estado] = $this->pedir('POST', '/api/caja/abrir', ['sucursal' => 1, 'monto_inicial' => 0], $this->tokenEmpleado);
        $this->assertSame(201, $estado);

        // Historial: solo administrador, y solo su negocio.
        [$estado] = $this->pedir('GET', '/api/caja/turnos', null, $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado, $r] = $this->pedir('GET', '/api/caja/turnos', null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertCount(3, $r['datos']);
        [, $r] = $this->pedir('GET', '/api/caja/turnos', null, $this->tokenOtro);
        $this->assertCount(0, $r['datos']);
    }

    public function testNoSePuedeUsarLaCajaDeOtroNegocio(): void
    {
        $this->pedir('POST', '/api/caja/config', ['activa' => true], $this->token);
        [, $r] = $this->pedir('POST', '/api/caja/abrir', ['sucursal' => 1, 'monto_inicial' => 100], $this->token);
        $id = $r['datos']['id'];

        [$estado] = $this->pedir('GET', '/api/caja/estado?sucursal=2', null, $this->tokenOtro);
        $this->assertSame(403, $estado);
        [$estado] = $this->pedir('GET', '/api/caja/turnos/' . $id, null, $this->tokenOtro);
        $this->assertSame(404, $estado);
        [$estado, $r] = $this->pedir('GET', '/api/caja/estado?sucursal=1', null, $this->tokenOtro);
        $this->assertSame(200, $estado);
        $this->assertNull($r['datos']['turno']);
    }
}
