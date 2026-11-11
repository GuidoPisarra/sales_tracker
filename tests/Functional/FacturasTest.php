<?php

namespace App\Tests\Functional;

use App\Service\Facturas\FacturaIaService;
use App\Service\Facturas\LectorFacturas;
use App\Tests\KontiaTestCase;

/**
 * "Cargar factura" con IA: el lector de facturas se reemplaza por uno falso
 * (no se llama a Gemini) y se prueban la sugerencia, la confirmación y el
 * aprendizaje de emparejamientos.
 */
class FacturasTest extends KontiaTestCase
{
    private string $token;
    private int $idYerba;

    private const FACTURA = [
        'proveedor' => 'Distribuidora Norte SA',
        'cuit_proveedor' => '30-71234567-8',
        'fecha' => '2026-11-10',
        'numero' => '0003-00012345',
        'precios_sin_iva' => true,
        'items' => [
            ['codigo' => '7790387000014', 'descripcion' => 'YERBA PLAYADITO 1KG', 'cantidad' => 10, 'precio_unitario' => 3100.5],
            ['codigo' => null, 'descripcion' => 'GALL OREO X118G', 'cantidad' => 24, 'precio_unitario' => 820],
            ['codigo' => 'AZ-55', 'descripcion' => 'AZUCAR LEDESMA 1KG', 'cantidad' => 5, 'precio_unitario' => 1200],
            ['codigo' => null, 'descripcion' => 'FLETE', 'cantidad' => 0, 'precio_unitario' => 5000],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearNegocio(1, [1], 'pro');
        $this->crearUsuario('a@test.com', 1);
        $this->crearUsuario('empleado@test.com', 1, false);
        $this->idYerba = $this->insertar("INSERT INTO product (description, cost_price, sale_price, quantity, code, barcode, activo, id_negocio, sucursal) VALUES ('Yerba Playadito 1kg', 3000, 4200, 2, 'Y1', '7790387000014', 0, 1, 1)");
        $this->insertar("INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES ('Galletitas Oreo 118g', 800, 1300, 3, 'G1', 0, 1, 1)");
        $this->token = $this->tokenPara('a@test.com');
        $this->simularLector(self::FACTURA);
        $_ENV['GEMINI_API_KEY'] = 'clave-test';
    }

    private function simularLector(array $respuesta): void
    {
        $falso = new class($respuesta) implements LectorFacturas {
            public function __construct(private array $respuesta) {}
            public function configurado(): bool { return true; }
            public function leer(string $contenido, string $mime): array { return $this->respuesta; }
        };
        static::getContainer()->set(LectorFacturas::class, $falso);
    }

    private function leer(?string $token = null): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'fac');
        // Un JPG mínimo válido (el tipo se detecta por el contenido).
        file_put_contents($ruta, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
        $archivo = new \Symfony\Component\HttpFoundation\File\UploadedFile($ruta, 'factura.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', '/api/facturas/leer', ['sucursal' => 1], ['archivo' => $archivo], ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token)]);
        $r = $this->client->getResponse();
        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    public function testLeeYSugiereProductos(): void
    {
        [$codigo, $json] = $this->leer();
        $this->assertSame(200, $codigo, json_encode($json));
        $d = $json['datos'];
        $this->assertSame('30712345678', $d['cuit_proveedor']);
        $this->assertTrue($d['precios_sin_iva']);
        // El flete con cantidad 0 se descarta.
        $this->assertCount(3, $d['items']);
        $this->assertSame('codigo', $d['items'][0]['sugerencia']['motivo']);
        $this->assertSame($this->idYerba, $d['items'][0]['sugerencia']['id']);
        // "GALL OREO X118G" se parece a "Galletitas Oreo 118g".
        $this->assertSame('nombre', $d['items'][1]['sugerencia']['motivo']);
        $this->assertSame('Galletitas Oreo 118g', $d['items'][1]['sugerencia']['description']);
        $this->assertNull($d['items'][2]['sugerencia']);

        // Cuenta como un mensaje de IA del plan.
        $this->assertSame(1, (int) $this->pdo()->query('SELECT mensajes FROM uso_ia WHERE id_negocio = 1')->fetchColumn());
    }

    public function testConfirmarSumaStockCreaYAprende(): void
    {
        [, $json] = $this->leer();
        $d = $json['datos'];
        [$codigo, $r] = $this->pedir('POST', '/api/facturas/confirmar', [
            'sucursal' => 1,
            'proveedor_clave' => $d['proveedor_clave'],
            'items' => [
                ['accion' => 'sumar', 'id' => $this->idYerba, 'cantidad' => 10, 'costo' => 3751.61, 'precio_venta' => 5000, 'texto_factura' => 'YERBA PLAYADITO 1KG'],
                ['accion' => 'crear', 'descripcion' => 'Azúcar Ledesma 1kg', 'codigo' => 'AZ-55', 'cantidad' => 5, 'costo' => 1452, 'precio_venta' => 2000, 'texto_factura' => 'AZUCAR LEDESMA 1KG'],
            ],
        ], $this->token);
        $this->assertSame(200, $codigo, json_encode($r));
        $this->assertSame(['sumados' => 1, 'creados' => 1], $r['datos']);

        $yerba = $this->pdo()->query("SELECT quantity, cost_price, sale_price FROM product WHERE id = {$this->idYerba}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(12, (int) $yerba['quantity']);
        $this->assertEquals(3751.61, (float) $yerba['cost_price']);
        $this->assertEquals(5000, (float) $yerba['sale_price']);
        $azucar = $this->pdo()->query("SELECT quantity, code FROM product WHERE description = 'Azúcar Ledesma 1kg'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(5, (int) $azucar['quantity']);
        $this->assertSame('AZ-55', $azucar['code']);

        // La próxima factura del mismo proveedor ya sugiere el azúcar (aprendido).
        [, $json] = $this->leer();
        $this->assertSame('aprendido', $json['datos']['items'][2]['sugerencia']['motivo']);
        $this->assertSame('Azúcar Ledesma 1kg', $json['datos']['items'][2]['sugerencia']['description']);
    }

    public function testConfirmarConErroresNoTocaNada(): void
    {
        [$codigo] = $this->pedir('POST', '/api/facturas/confirmar', [
            'sucursal' => 1,
            'items' => [
                ['accion' => 'sumar', 'id' => $this->idYerba, 'cantidad' => 10, 'costo' => 3000, 'precio_venta' => 4000],
                ['accion' => 'crear', 'descripcion' => '', 'cantidad' => 1, 'costo' => 1, 'precio_venta' => 2],
            ],
        ], $this->token);
        $this->assertSame(400, $codigo);
        $this->assertSame(2, (int) $this->pdo()->query("SELECT quantity FROM product WHERE id = {$this->idYerba}")->fetchColumn());
    }

    public function testProductoDeOtroNegocioNoSeToca(): void
    {
        $this->crearNegocio(2, [1]);
        $ajeno = $this->insertar("INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES ('Ajeno', 1, 2, 5, 'X1', 0, 2, 1)");
        [$codigo] = $this->pedir('POST', '/api/facturas/confirmar', [
            'sucursal' => 1,
            'items' => [['accion' => 'sumar', 'id' => $ajeno, 'cantidad' => 10, 'costo' => 1, 'precio_venta' => 2]],
        ], $this->token);
        $this->assertSame(409, $codigo);
        $this->assertSame(5, (int) $this->pdo()->query("SELECT quantity FROM product WHERE id = $ajeno")->fetchColumn());
    }

    public function testSoloElAdministrador(): void
    {
        [$codigo] = $this->leer($this->tokenPara('empleado@test.com'));
        $this->assertSame(403, $codigo);
    }

    public function testPlanSinIaNoLee(): void
    {
        $this->pdo()->exec("UPDATE suscripcion SET id_plan = (SELECT id FROM plan_suscripcion WHERE codigo = 'starter') WHERE id_negocio = 1");
        [$codigo, $json] = $this->leer();
        $this->assertSame(402, $codigo);
        $this->assertStringContainsString('asistente de IA', $json['errores'][0]);
    }

    public function testFotoDesdeElCelular(): void
    {
        [, $json] = $this->pedir('POST', '/api/escaneo/sesiones', ['sucursal' => 1, 'modo' => 'factura'], $this->token);
        $id = $json['datos']['id'];
        [, $json] = $this->pedir('POST', '/api/escaneo/movil/vincular', ['token' => $json['datos']['token']]);
        $clave = $json['datos']['clave'];

        $ruta = tempnam(sys_get_temp_dir(), 'fac');
        file_put_contents($ruta, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
        $this->client->request('POST', '/api/escaneo/movil/foto', ['clave' => $clave], ['archivo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($ruta, 'f.jpg', 'image/jpeg', null, true)]);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        [, $json] = $this->pedir('GET', "/api/escaneo/sesiones/$id", null, $this->token);
        $this->assertSame('factura', $json['datos']['modo']);
        $this->assertCount(1, $json['datos']['fotos']);

        [$codigo, $json] = $this->pedir('POST', '/api/facturas/leer-escaneo', ['sesion' => $id, 'foto' => $json['datos']['fotos'][0], 'sucursal' => 1], $this->token);
        $this->assertSame(200, $codigo, json_encode($json));
        $this->assertCount(3, $json['datos']['items']);
    }

    public function testParecidoDeNombres(): void
    {
        $t = fn ($s) => explode(' ', FacturaIaService::normalizarTexto($s));
        $this->assertGreaterThanOrEqual(0.5, FacturaIaService::parecido($t('GALL OREO X118G'), $t('Galletitas Oreo 118g')));
        $this->assertLessThan(0.5, FacturaIaService::parecido($t('ACEITE NATURA 1.5L'), $t('Galletitas Oreo 118g')));
        $this->assertSame('coca cola 1.5l', FacturaIaService::normalizarTexto('COCA-COLA 1,5L'));
    }
}
