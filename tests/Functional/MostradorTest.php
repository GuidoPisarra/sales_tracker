<?php

namespace App\Tests\Functional;

use App\Repository\CajaRepository;
use App\Tests\KontiaTestCase;

/** Stock mínimo y lista para reponer, pago mixto y ajustes del ticket. */
class MostradorTest extends KontiaTestCase
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

    private function producto(int $negocio, string $nombre, int $stock, ?int $minimo = null, ?int $proveedor = null): int
    {
        return $this->insertar(
            'INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal, stock_minimo, id_proveedor) VALUES (?, 50, 100, ?, ?, 0, ?, 1, ?, ?)',
            [$nombre, $stock, 'C' . $nombre, $negocio, $minimo, $proveedor]
        );
    }

    private function nombres(array $lista): array
    {
        $n = array_column($lista, 'description');
        sort($n);
        return $n;
    }

    public function testListaParaReponerUsaElMinimoDeCadaProductoOElGeneral(): void
    {
        $prov = $this->insertar('INSERT INTO proveedores (nombre, telefono, id_negocio) VALUES (?, ?, 1)', ['Distribuidora Sur', '1155550000']);
        $this->producto(1, 'Yerba', 2, null, $prov);   // general (3): reponer
        $this->producto(1, 'Azucar', 5, 10);           // mínimo propio 10: reponer
        $this->producto(1, 'Arroz', 5);                // general 3: bien
        $this->producto(1, 'Fideos', 0);               // sin stock: reponer
        $this->producto(1, 'Sal', 1, 0);               // mínimo 0: bien
        $this->producto(2, 'DeOtro', 0);               // otro negocio

        [$estado, $r] = $this->pedir('GET', '/api/products/reponer', null, $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        $this->assertSame(['Azucar', 'Fideos', 'Yerba'], $this->nombres($r['datos']));
        $yerba = array_values(array_filter($r['datos'], fn ($p) => $p['description'] === 'Yerba'))[0];
        $this->assertSame('Distribuidora Sur', $yerba['proveedor']);
        $this->assertSame(3, $yerba['minimo']);
        $this->assertSame(4, $yerba['sugerido']); // hasta el doble del mínimo

        // El aviso de Inicio lleva a la lista.
        [, $r] = $this->pedir('GET', '/api/avisos', null, $this->token);
        $aviso = array_values(array_filter($r['datos'], fn ($a) => ($a['ruta'] ?? '') === '/reponer'));
        $this->assertCount(1, $aviso);
        $this->assertStringContainsString('3 productos', $aviso[0]['mensaje']);

        // El empleado no cambia el mínimo general; el administrador sí.
        [$estado] = $this->pedir('POST', '/api/mostrador/config', ['stock_minimo_general' => 1], $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [$estado, $r] = $this->pedir('POST', '/api/mostrador/config', ['stock_minimo_general' => 1], $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame(1, $r['datos']['stock_minimo_general']);
        [, $r] = $this->pedir('GET', '/api/products/reponer', null, $this->token);
        $this->assertSame(['Azucar', 'Fideos'], $this->nombres($r['datos']));

        // El otro negocio no ve nada de esto.
        [, $r] = $this->pedir('GET', '/api/products/reponer', null, $this->tokenOtro);
        $this->assertSame(['DeOtro'], $this->nombres($r['datos']));
    }

    public function testStockMinimoSeEditaPorProductoYEnMasa(): void
    {
        $a = $this->producto(1, 'A', 4);
        $b = $this->producto(1, 'B', 4);
        [$estado] = $this->pedir('POST', '/api/products/masivo', ['ids' => [$a, $b], 'cambios' => ['stockMinimo' => 6]], $this->token);
        $this->assertSame(200, $estado);
        [, $r] = $this->pedir('GET', '/api/products/buscar?filtro=reponer', null, $this->token);
        $this->assertSame(2, $r['datos']['total']);
        $this->assertSame(6, (int) $r['datos']['items'][0]['stockMinimo']);

        // Vacío = volver al general.
        $this->pedir('POST', '/api/products/masivo', ['ids' => [$a], 'cambios' => ['stockMinimo' => '']], $this->token);
        [, $r] = $this->pedir('GET', '/api/products/buscar?filtro=reponer', null, $this->token);
        $this->assertSame(1, $r['datos']['total']);

        // Catálogo del dispositivo trae el mínimo (para marcar "stock bajo" en Caja).
        [, $r] = $this->pedir('GET', '/api/products/catalogo', null, $this->token);
        $porId = array_column($r['datos']['productos'], 'stockMinimo', 'id');
        $this->assertNull($porId[$a]);
        $this->assertSame(6, (int) $porId[$b]);
    }

    private function vender(array $carrito): array
    {
        return $this->pedir('POST', '/api/salesProduct/salesProduct', $carrito, $this->tokenEmpleado);
    }

    private function item(int $id, float $precio, int $cantidad, string $pago = 'efectivo', array $extra = []): array
    {
        return array_merge(['id' => $id, 'salePrice' => $precio, 'cantidad' => $cantidad, 'typePayment' => $pago, 'sucursal' => 1], $extra);
    }

    public function testPagoMixtoGuardaCuantoEntroPorCadaMedio(): void
    {
        $a = $this->producto(1, 'A', 10);
        $b = $this->producto(1, 'B', 10);
        $pagos = [['forma' => 'efectivo', 'monto' => 100], ['forma' => 'tarjeta', 'monto' => 200]];
        [$estado, $r] = $this->vender([$this->item($a, 100, 1, 'efectivo', ['pagos' => $pagos]), $this->item($b, 100, 2)]);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertTrue($r['datos'][0]);
        $idVenta = $r['datos'][1]['idVenta'];
        $this->assertGreaterThan(0, $idVenta);

        $formas = $this->pdo()->query("SELECT DISTINCT type_payment FROM sales_product WHERE id_sale = $idVenta")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['mixto'], $formas);
        $guardados = $this->pdo()->query("SELECT forma, monto FROM venta_pago WHERE id_sale = $idVenta ORDER BY forma")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([['forma' => 'efectivo', 'monto' => '100.00'], ['forma' => 'tarjeta', 'monto' => '200.00']], $guardados);

        // La caja reparte la venta según lo que entró por cada medio.
        $otra = $this->vender([$this->item($a, 50, 1, 'efectivo')]);
        $this->assertSame(200, $otra[0]);
        $filas = static::getContainer()->get(CajaRepository::class)->ventasPorFormaPago(1, 1, '2000-01-01 00:00:00', '2999-01-01 00:00:00');
        $totales = array_map('floatval', array_column($filas, 'total', 'forma_pago'));
        ksort($totales);
        $this->assertSame(['efectivo' => 150.0, 'tarjeta' => 200.0], $totales);

        // El reporte de ventas muestra el detalle.
        [, $r] = $this->pedir('GET', '/api/report/salesProduct/1', null, $this->token);
        $venta = array_values(array_filter($r['datos']['ventas'], fn ($v) => (int) $v[0]['id_sale'] === $idVenta))[0];
        $this->assertCount(2, $venta[0]['pagos']);

        // Pasarla a un solo medio borra el detalle.
        [$estado] = $this->pedir('POST', '/api/salesProduct/salesProduct/typePayment', ['idSaleProduct' => $idVenta, 'typePayment' => 'tarjeta'], $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM venta_pago WHERE id_sale = $idVenta")->fetchColumn());
    }

    public function testPagoMixtoQueNoSumaElTotalSeRechaza(): void
    {
        $a = $this->producto(1, 'A', 10);
        [$estado, $r] = $this->vender([$this->item($a, 300, 1, 'efectivo', ['pagos' => [['forma' => 'efectivo', 'monto' => 100], ['forma' => 'tarjeta', 'monto' => 100]]])]);
        $this->assertSame(400, $estado);
        $this->assertStringContainsString('Tienen que dar lo mismo', $r['errores'][0]);

        [$estado, $r] = $this->vender([$this->item($a, 300, 1, 'efectivo', ['pagos' => [['forma' => 'efectivo', 'monto' => 300]]])]);
        $this->assertSame(400, $estado);
        [$estado] = $this->vender([$this->item($a, 300, 1, 'efectivo', ['pagos' => [['forma' => 'Cta Cte', 'monto' => 150], ['forma' => 'efectivo', 'monto' => 150]]])]);
        $this->assertSame(400, $estado);
        $this->assertSame(10, (int) $this->pdo()->query("SELECT quantity FROM product WHERE id = $a")->fetchColumn());
    }

    public function testDatosDelTicket(): void
    {
        [, $r] = $this->pedir('GET', '/api/mostrador/config', null, $this->tokenEmpleado);
        $this->assertSame('Negocio 1 suc 1', $r['datos']['nombre_negocio']);
        $this->assertSame(58, $r['datos']['ticket_ancho']);

        [$estado, $r] = $this->pedir('POST', '/api/mostrador/config', ['ticket_titulo' => 'Almacén Don Pepe', 'ticket_pie' => 'Gracias por su compra', 'ticket_ancho' => 80], $this->token);
        $this->assertSame(200, $estado);
        [, $r] = $this->pedir('GET', '/api/mostrador/config', null, $this->tokenEmpleado);
        $this->assertSame('Almacén Don Pepe', $r['datos']['ticket_titulo']);
        $this->assertSame('Gracias por su compra', $r['datos']['ticket_pie']);
        $this->assertSame(80, $r['datos']['ticket_ancho']);
        [, $r] = $this->pedir('GET', '/api/mostrador/config', null, $this->tokenOtro);
        $this->assertNull($r['datos']['ticket_titulo']);
    }
}
