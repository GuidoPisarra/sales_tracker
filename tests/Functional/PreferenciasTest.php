<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** Accesos rápidos del Inicio guardados por usuario. */
class PreferenciasTest extends KontiaTestCase
{
    public function testGuardaPorUsuarioYValida(): void
    {
        $this->crearNegocio(1, [1]);
        $this->crearUsuario('a@test.com', 1);
        $this->crearUsuario('b@test.com', 1, false);
        $a = $this->tokenPara('a@test.com');
        $b = $this->tokenPara('b@test.com');

        [$estado, $r] = $this->pedir('GET', '/api/preferencias/inicio_accesos', null, $a);
        $this->assertSame(200, $estado);
        $this->assertNull($r['datos']['valor']);

        [$estado, $r] = $this->pedir('POST', '/api/preferencias/inicio_accesos', ['valor' => ['contar', 'vender']], $a);
        $this->assertSame(200, $estado);
        [, $r] = $this->pedir('GET', '/api/preferencias/inicio_accesos', null, $a);
        $this->assertSame(['contar', 'vender'], $r['datos']['valor']);
        [, $r] = $this->pedir('GET', '/api/preferencias/inicio_accesos', null, $b);
        $this->assertNull($r['datos']['valor']);

        foreach ([[], ['a', 'b'], ['vender', 'precio', 'cambio', 'producto', 'stock'], 'vender', ['vender', 'hackear']] as $malo) {
            [$estado] = $this->pedir('POST', '/api/preferencias/inicio_accesos', ['valor' => $malo], $a);
            $this->assertSame(400, $estado);
        }
        [$estado] = $this->pedir('GET', '/api/preferencias/otra_cosa', null, $a);
        $this->assertSame(404, $estado);
    }
}
