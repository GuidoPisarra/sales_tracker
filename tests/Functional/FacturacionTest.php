<?php

namespace App\Tests\Functional;

use App\Service\Arca\ArcaWsaa;
use App\Service\Arca\Certificados;
use App\Service\Arca\HttpArcaTransporte;
use App\Service\FacturacionService;
use App\Tests\Doble\ArcaSimulado;
use App\Tests\KontiaTestCase;

/** Factura electrónica ARCA contra un ARCA simulado (no sale a internet). */
class FacturacionTest extends KontiaTestCase
{
    private string $token;
    private string $tokenEmpleado;
    private string $tokenOtro;
    private ArcaSimulado $arca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1]);
        $this->crearNegocio(2, [1], 'starter');
        $this->crearUsuario('titular@test.com', 1);
        $this->crearUsuario('empleado@test.com', 1, false);
        $this->crearUsuario('otro@test.com', 2);
        $this->token = $this->tokenPara('titular@test.com');
        $this->tokenEmpleado = $this->tokenPara('empleado@test.com');
        $this->tokenOtro = $this->tokenPara('otro@test.com');
        $this->arca = new ArcaSimulado();
        static::getContainer()->set(HttpArcaTransporte::class, $this->arca);
    }

    /** Deja el negocio 1 listo para facturar: datos, pedido, certificado y activado. */
    private function configurar(string $condicion = 'monotributo', string $modo = 'manual'): void
    {
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/config', [
            'cuit' => '20-11111111-2', 'razon_social' => 'Almacén Don Pepe', 'condicion_iva' => $condicion,
            'punto_venta' => 3, 'domicilio' => 'Calle 1 123', 'modo' => $modo,
        ], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/pedido-certificado', [], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $r['datos']['pedido']);

        // "ARCA" firma el pedido: acá lo firmamos con la misma clave (autofirmado).
        $clave = Certificados::descifrar((string) $this->pdo()->query('SELECT clave_privada FROM negocio_fiscal WHERE id_negocio = 1')->fetchColumn());
        $cert = openssl_csr_sign($r['datos']['pedido'], null, $clave, 730, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/certificado', ['certificado' => $pem], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertTrue($r['datos']['tiene_certificado']);

        [$estado, $r] = $this->pedir('POST', '/api/facturacion/config', ['activo' => true], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertTrue($r['datos']['activo']);
    }

    private function venta(float $precio, int $cantidad = 1, int $negocio = 1): int
    {
        static $idSale = 500;
        $idSale++;
        $this->insertar(
            'INSERT INTO sales_product (id_sale, id_product, quantity, price, sale_product_date, type_payment, active, id_negocio, sucursal) VALUES (?, 1, ?, ?, NOW(), "efectivo", 0, ?, 1)',
            [$idSale, $cantidad, $precio, $negocio]
        );
        return $idSale;
    }

    public function testCuitValido(): void
    {
        $this->assertTrue(FacturacionService::cuitValido('20111111112'));
        $this->assertTrue(FacturacionService::cuitValido('30500010912'));
        $this->assertFalse(FacturacionService::cuitValido('20111111111'));
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/config', ['cuit' => '20111111111'], $this->token);
        $this->assertSame(400, $estado);
        $this->assertStringContainsString('CUIT', $r['errores'][0]);
    }

    public function testSoloElAdministradorConfiguraYStarterNoIncluye(): void
    {
        [$estado] = $this->pedir('POST', '/api/facturacion/config', ['cuit' => '20111111112'], $this->tokenEmpleado);
        $this->assertSame(403, $estado);
        [, $r] = $this->pedir('GET', '/api/facturacion/config', null, $this->tokenEmpleado);
        $this->assertArrayNotHasKey('cuit', $r['datos']);
        $this->assertFalse($r['datos']['activo']);

        [, $r] = $this->pedir('GET', '/api/facturacion/config', null, $this->tokenOtro);
        $this->assertFalse($r['datos']['incluido_en_plan']);
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/config', ['activo' => true], $this->tokenOtro);
        $this->assertSame(400, $estado);
        $this->assertStringContainsString('Pro y Premium', $r['errores'][0]);

        // Sin certificado no se puede activar.
        $this->pedir('POST', '/api/facturacion/config', ['cuit' => '20111111112', 'razon_social' => 'X', 'condicion_iva' => 'monotributo', 'punto_venta' => 1], $this->token);
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/config', ['activo' => true], $this->token);
        $this->assertSame(400, $estado);
        $this->assertStringContainsString('certificado', $r['errores'][0]);
    }

    public function testCertificadoQueNoCorrespondeSeRechaza(): void
    {
        $this->pedir('POST', '/api/facturacion/config', ['cuit' => '20111111112', 'razon_social' => 'X'], $this->token);
        $this->pedir('POST', '/api/facturacion/pedido-certificado', [], $this->token);
        $otraClave = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'otro'], $otraClave);
        openssl_x509_export(openssl_csr_sign($csr, null, $otraClave, 30), $pem);
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/certificado', ['certificado' => $pem], $this->token);
        $this->assertSame(400, $estado);
        $this->assertStringContainsString('no corresponde', $r['errores'][0]);
    }

    public function testMonotributoEmiteFacturaCYLaNotaDeCreditoAlAnular(): void
    {
        $this->configurar();
        $this->arca->ultimo[11] = 41;
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/probar', [], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertStringContainsString('41', $r['datos']['mensaje']);

        $idVenta = $this->venta(1500, 2);
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $idVenta], $this->tokenEmpleado);
        $this->assertSame(200, $estado, json_encode($r));
        $f = $r['datos'];
        $this->assertSame('emitida', $f['estado'], (string) $f['error']);
        $this->assertSame('Factura C', $f['nombre']);
        $this->assertSame('00003-00000042', $f['numero_completo']);
        $this->assertEquals(3000, $f['total']);
        $this->assertNotEmpty($f['cae']);
        $this->assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $f['qr_url']);
        $qr = json_decode(base64_decode(substr($f['qr_url'], strlen('https://www.afip.gob.ar/fe/qr/?p='))), true);
        $this->assertSame(20111111112, $qr['cuit']);
        $this->assertSame(42, $qr['nroCmp']);

        $pedido = $this->arca->llamadas('FECAESolicitar')[0]['xml'];
        $this->assertSame('3000.00', $this->arca->valor($pedido, 'ImpNeto'));
        $this->assertSame('0.00', $this->arca->valor($pedido, 'ImpIVA'));
        $this->assertSame('5', $this->arca->valor($pedido, 'CondicionIVAReceptorId'));
        $this->assertStringNotContainsString('<ar:Iva>', $pedido);

        // El ticket de acceso se reusa: un solo pedido al WSAA.
        $this->assertCount(1, array_filter($this->arca->pedidos, fn ($p) => $p['accion'] === ''));

        // Facturar otra vez la misma venta devuelve la misma factura.
        [, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $idVenta], $this->token);
        $this->assertSame($f['id'], $r['datos']['id']);
        $this->assertCount(1, $this->arca->llamadas('FECAESolicitar'));

        // Anular la venta emite la nota de crédito C asociada.
        [$estado, $r] = $this->pedir('POST', '/api/salesProduct/salesProduct/delete', ['idSaleProduct' => $idVenta], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $nota = $r['datos'][1]['notaCredito'];
        $this->assertSame('Nota de crédito C', $nota['nombre']);
        $this->assertSame('emitida', $nota['estado']);
        $xmlNota = $this->arca->llamadas('FECAESolicitar')[1]['xml'];
        $this->assertSame('13', $this->arca->valor($xmlNota, 'CbteTipo'));
        $this->assertStringContainsString('<ar:Nro>42</ar:Nro>', $xmlNota);

        // La anulada ya no se factura.
        [$estado] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $idVenta], $this->token);
        $this->assertSame(400, $estado);

        // Otro negocio no ve los comprobantes.
        [, $r] = $this->pedir('GET', '/api/facturacion/venta/' . $idVenta, null, $this->tokenOtro);
        $this->assertSame([], $r['datos']);
        [, $r] = $this->pedir('GET', '/api/facturacion/venta/' . $idVenta, null, $this->token);
        $this->assertCount(2, $r['datos']);
    }

    public function testResponsableInscriptoHaceBConIvaYASoloConCuit(): void
    {
        $this->configurar('responsable_inscripto');
        $idVenta = $this->venta(1210);
        [, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $idVenta], $this->token);
        $this->assertSame('Factura B', $r['datos']['nombre']);
        $this->assertEquals(1000, $r['datos']['neto']);
        $this->assertEquals(210, $r['datos']['iva']);
        $pedido = $this->arca->llamadas('FECAESolicitar')[0]['xml'];
        $this->assertStringContainsString('<ar:AlicIva><ar:Id>5</ar:Id><ar:BaseImp>1000.00</ar:BaseImp><ar:Importe>210.00</ar:Importe>', $pedido);

        // Cliente responsable inscripto sin CUIT: no se puede.
        $otra = $this->venta(500);
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $otra, 'receptor' => ['condicion' => 'responsable_inscripto']], $this->token);
        $this->assertSame(400, $estado);
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $otra, 'receptor' => ['condicion' => 'responsable_inscripto', 'doc_nro' => '30-50001091-2', 'nombre' => 'Empresa SA']], $this->token);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertSame('Factura A', $r['datos']['nombre']);
        $pedido = $this->arca->llamadas('FECAESolicitar')[1]['xml'];
        $this->assertSame('80', $this->arca->valor($pedido, 'DocTipo'));
        $this->assertSame('30500010912', $this->arca->valor($pedido, 'DocNro'));
        $this->assertSame('1', $this->arca->valor($pedido, 'CondicionIVAReceptorId'));
    }

    public function testSiArcaNoRespondeQuedaPendienteYSeReintentaSinDuplicar(): void
    {
        $this->configurar();
        $idVenta = $this->venta(800);

        // ARCA emite pero la respuesta se pierde: queda pendiente.
        $this->arca->perderRespuesta = true;
        [$estado, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $idVenta], $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame('pendiente', $r['datos']['estado']);

        // Al reintentar se consulta el número y se adopta ese CAE (no se pide otro).
        $servicio = static::getContainer()->get(FacturacionService::class);
        $this->assertSame(['emitidas' => 1, 'pendientes' => 0, 'rechazadas' => 0], $servicio->reintentarPendientes());
        $this->assertCount(1, $this->arca->llamadas('FECAESolicitar'));
        [, $r] = $this->pedir('GET', '/api/facturacion/venta/' . $idVenta, null, $this->token);
        $this->assertSame('emitida', $r['datos'][0]['estado']);
        $this->assertSame(1, $r['datos'][0]['numero']);

        // Caído del todo: pendiente, y sale cuando vuelve.
        $otra = $this->venta(300);
        $this->arca->caido = true;
        [, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $otra], $this->token);
        $this->assertSame('pendiente', $r['datos']['estado']);
        $this->arca->caido = false;
        [, $r] = $this->pedir('POST', '/api/facturacion/reintentar/' . $r['datos']['id'], [], $this->tokenEmpleado);
        $this->assertSame('emitida', $r['datos']['estado']);
        $this->assertSame(2, $r['datos']['numero']);
    }

    public function testRechazoDeArcaSeMuestraYSePuedeCorregir(): void
    {
        $this->configurar();
        $idVenta = $this->venta(100);
        $this->arca->rechazo = 'El campo DocNro es invalido';
        [, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $idVenta], $this->token);
        $this->assertSame('rechazada', $r['datos']['estado']);
        $this->assertStringContainsString('DocNro', $r['datos']['error']);
        $this->arca->rechazo = null;
        [, $r] = $this->pedir('POST', '/api/facturacion/emitir', ['idVenta' => $idVenta, 'receptor' => ['doc_nro' => '30123456']], $this->token);
        $this->assertSame('emitida', $r['datos']['estado']);
        $this->assertSame(96, $r['datos']['doc_tipo']);
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM factura WHERE id_sale = $idVenta")->fetchColumn());
    }

    public function testModoAutomaticoFacturaAlVender(): void
    {
        $this->configurar('monotributo', 'automatico');
        $id = $this->insertar('INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES ("Yerba", 50, 100, 10, "Y1", 0, 1, 1)');
        [$estado, $r] = $this->pedir('POST', '/api/salesProduct/salesProduct', [
            ['id' => $id, 'salePrice' => 100, 'cantidad' => 2, 'typePayment' => 'efectivo', 'sucursal' => 1],
        ], $this->tokenEmpleado);
        $this->assertSame(200, $estado, json_encode($r));
        $this->assertSame('emitida', $r['datos'][1]['factura']['estado']);
        $this->assertEquals(200, $r['datos'][1]['factura']['total']);

        // Aunque ARCA esté caído, la venta se guarda igual y la factura queda pendiente.
        $this->arca->caido = true;
        [$estado, $r] = $this->pedir('POST', '/api/salesProduct/salesProduct', [
            ['id' => $id, 'salePrice' => 100, 'cantidad' => 1, 'typePayment' => 'efectivo', 'sucursal' => 1],
        ], $this->tokenEmpleado);
        $this->assertSame(200, $estado);
        $this->assertTrue($r['datos'][0]);
        $this->assertSame('pendiente', $r['datos'][1]['factura']['estado']);
    }

    public function testFirmaDelPedidoDeAcceso(): void
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'kontia', 'serialNumber' => 'CUIT 20111111112'], $clave);
        openssl_x509_export(openssl_csr_sign($csr, null, $clave, 30), $cert);
        openssl_pkey_export($clave, $clavePem);
        $cms = ArcaWsaa::firmarPedido($cert, $clavePem);
        $der = base64_decode($cms, true);
        $this->assertNotFalse($der);
        // El CMS lleva adentro el pedido de acceso para el servicio wsfe.
        $this->assertStringContainsString('<service>wsfe</service>', $der);
    }
}
