<?php

namespace App\Tests\Functional;

use App\Service\Fotos\AlmacenR2;
use App\Tests\KontiaTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Fotos de productos para el catálogo online (migración 0009). */
class FotosTest extends KontiaTestCase
{
    private string $dir;
    private string $token;
    private string $tokenOtro;
    private int $remera;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/kontia-fotos-test-' . bin2hex(random_bytes(4));
        $_ENV['FOTOS_DIR'] = $this->dir;
        $_ENV['FOTOS_URL_BASE'] = 'https://api.test/fotos';
        $_ENV['FOTOS_ALMACEN'] = 'local';

        $this->crearNegocio(1, [1, 2], 'pro');
        $this->crearNegocio(2, [1], 'starter');
        $this->crearUsuario('titular@test.com', 1);
        $this->crearUsuario('otro@test.com', 2);
        $this->token = $this->tokenPara('titular@test.com');
        $this->tokenOtro = $this->tokenPara('otro@test.com');
        $this->remera = $this->insertar('INSERT INTO product (description, cost_price, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES (?, 50, 100, 5, ?, 0, 1, 1)', ['Remera', 'REM']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        unset($_ENV['FOTOS_DIR'], $_ENV['FOTOS_URL_BASE'], $_ENV['FOTOS_ALMACEN'], $_ENV['FOTOS_MAX_SIN_LIMITES']);
        parent::tearDown();
    }

    /** JPEG de prueba, apaisado, con la "rotación" que le pondría un celular. */
    private function jpeg(int $ancho = 2400, int $alto = 1600): string
    {
        $img = imagecreatetruecolor($ancho, $alto);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 140, 90));
        $ruta = tempnam(sys_get_temp_dir(), 'foto') . '.jpg';
        imagejpeg($img, $ruta, 90);
        imagedestroy($img);
        return $ruta;
    }

    private function subir(int $idProducto, ?string $ruta = null, ?string $token = null): array
    {
        $ruta ??= $this->jpeg();
        $this->client->request('POST', "/api/productos/$idProducto/fotos", [], [
            'foto' => new UploadedFile($ruta, 'foto.jpg', null, null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token)]);
        $r = $this->client->getResponse();
        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    private function archivoLocal(string $url): string
    {
        return $this->dir . '/' . substr($url, strlen('https://api.test/fotos/'));
    }

    public function testSubeYConvierteAWebp(): void
    {
        [$estado, $r] = $this->subir($this->remera);
        $this->assertSame(201, $estado, json_encode($r));
        $foto = $r['datos'];
        $this->assertSame(1200, $foto['ancho']);
        $this->assertSame(800, $foto['alto']);
        $this->assertStringStartsWith('https://api.test/fotos/n/1/', $foto['url']);

        $grande = getimagesize($this->archivoLocal($foto['url']));
        $mini = getimagesize($this->archivoLocal($foto['mini']));
        $this->assertSame(IMAGETYPE_WEBP, $grande[2]);
        $this->assertSame([1200, 800], [$grande[0], $grande[1]]);
        $this->assertSame([400, 400], [$mini[0], $mini[1]]);
    }

    public function testRechazaLoQueNoEsFoto(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'falsa');
        file_put_contents($ruta, '<?php echo "hola";');
        [$estado, $r] = $this->subir($this->remera, $ruta);
        $this->assertSame(400, $estado);
        $this->assertStringContainsString('no es una foto', $r['errores'][0]);
    }

    public function testLimiteDelPlan(): void
    {
        for ($i = 0; $i < 3; $i++) {
            [$estado] = $this->subir($this->remera, $this->jpeg(300, 300));
            $this->assertSame(201, $estado);
        }
        [$estado, $r] = $this->subir($this->remera, $this->jpeg(300, 300));
        $this->assertSame(402, $estado);
        $this->assertSame('fotos', $r['datos']['recurso']);
        $this->assertStringContainsString('Pro permite 3 fotos', $r['errores'][0]);

        [, $r] = $this->pedir('GET', "/api/productos/{$this->remera}/fotos", null, $this->token);
        $this->assertSame(3, $r['datos']['maximo']);
        $this->assertCount(3, $r['datos']['fotos']);
    }

    public function testStarterUnaYExentoCinco(): void
    {
        $buzo = $this->insertar('INSERT INTO product (description, sale_price, quantity, code, activo, id_negocio, sucursal) VALUES (?, 100, 1, ?, 0, 2, 1)', ['Buzo', 'BUZ']);
        $this->assertSame(201, $this->subir($buzo, $this->jpeg(300, 300), $this->tokenOtro)[0]);
        $this->assertSame(402, $this->subir($buzo, $this->jpeg(300, 300), $this->tokenOtro)[0]);

        // Plan de por vida (sin límites): 5 fotos, no ilimitado.
        $this->pdo()->exec('UPDATE suscripcion SET exento_limites = 1 WHERE id_negocio = 2');
        [, $r] = $this->pedir('GET', "/api/productos/$buzo/fotos", null, $this->tokenOtro);
        $this->assertSame(5, $r['datos']['maximo']);
        $this->assertTrue($r['datos']['sin_limites']);
    }

    public function testNoSePuedenTocarFotosDeOtroNegocio(): void
    {
        [$estado] = $this->subir($this->remera, $this->jpeg(300, 300), $this->tokenOtro);
        $this->assertSame(404, $estado);
        [$estado] = $this->pedir('GET', "/api/productos/{$this->remera}/fotos", null, $this->tokenOtro);
        $this->assertSame(404, $estado);
    }

    public function testOrdenYBorrar(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->subir($this->remera, $this->jpeg(300, 300))[1]['datos']['id'];
        }
        [$estado, $r] = $this->pedir('PUT', "/api/productos/{$this->remera}/fotos/orden", ['ids' => [$ids[2], $ids[0]]], $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_column($r['datos']['fotos'], 'id'));

        $portada = $r['datos']['fotos'][0];
        [$estado, $r] = $this->pedir('DELETE', "/api/productos/{$this->remera}/fotos/{$ids[2]}", null, $this->token);
        $this->assertSame(200, $estado);
        $this->assertSame([$ids[0], $ids[1]], array_column($r['datos']['fotos'], 'id'));
        $this->assertSame(0, $r['datos']['fotos'][0]['orden']);
        $this->assertFileDoesNotExist($this->archivoLocal($portada['url']));
        $this->assertFileDoesNotExist($this->archivoLocal($portada['mini']));
    }

    public function testCatalogoPublicoMuestraFotosHastaElLimite(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->subir($this->remera, $this->jpeg(300, 300));
        }
        $this->pedir('POST', '/api/catalogo/config', ['activo' => true, 'slug' => 'mi-tienda', 'whatsapp' => '5491122334455'], $this->token);

        [, $r] = $this->pedir('GET', '/api/publico/catalogo/mi-tienda');
        $this->assertCount(3, $r['datos']['productos'][0]['fotos']);
        $this->assertArrayHasKey('mini', $r['datos']['productos'][0]['fotos'][0]);

        // Baja a Starter: no se borra nada, pero el catálogo muestra solo la primera.
        $this->pdo()->prepare('UPDATE suscripcion SET id_plan = ? WHERE id_negocio = 1')->execute([$this->idPlan('starter')]);
        [, $r] = $this->pedir('GET', '/api/publico/catalogo/mi-tienda');
        $this->assertCount(1, $r['datos']['productos'][0]['fotos']);
        $this->assertSame(3, (int) $this->pdo()->query('SELECT COUNT(*) FROM producto_foto')->fetchColumn());
    }

    public function testTrasladoCopiaLasFotos(): void
    {
        $foto = $this->subir($this->remera)[1]['datos'];
        [$estado] = $this->pedir('POST', '/api/products/trasladar_products', [[
            'id' => $this->remera, 'code' => 'REM', 'codeCanvas' => '', 'costPrice' => 50, 'description' => 'Remera',
            'idProveedor' => 0, 'quantity' => 5, 'salePrice' => 100, 'size' => '', 'sucursal' => 1, 'sucursal_nueva' => 2,
        ]], $this->token);
        $this->assertSame(200, $estado);
        $nuevo = (int) $this->pdo()->query("SELECT id FROM product WHERE code = 'REM' AND sucursal = 2")->fetchColumn();
        [, $r] = $this->pedir('GET', "/api/productos/$nuevo/fotos", null, $this->token);
        $this->assertSame($foto['url'], $r['datos']['fotos'][0]['url']);

        // El archivo se comparte: borrar la foto de un producto no lo borra mientras el otro la use.
        $this->pedir('DELETE', "/api/productos/{$this->remera}/fotos/{$foto['id']}", null, $this->token);
        $this->assertFileExists($this->archivoLocal($foto['url']));
    }

    public function testLimpiezaDeHuerfanas(): void
    {
        $foto = $this->subir($this->remera)[1]['datos'];
        $this->pdo()->exec('DELETE FROM product WHERE id = ' . $this->remera);
        $borradas = static::getContainer()->get(\App\Service\Fotos\FotosService::class)->limpiar();
        $this->assertSame(1, $borradas);
        $this->assertFileDoesNotExist($this->archivoLocal($foto['url']));
    }

    /** Ejemplo oficial de AWS para Signature V4 (GET Object con Range). */
    public function testFirmaR2(): void
    {
        $h = AlmacenR2::firmar('GET', 'examplebucket.s3.amazonaws.com', '/test.txt', '', ['range' => 'bytes=0-9'],
            'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            new \DateTimeImmutable('2013-05-24 00:00:00', new \DateTimeZone('UTC')), 'us-east-1');
        $this->assertStringEndsWith('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $h['authorization']);
    }
}
