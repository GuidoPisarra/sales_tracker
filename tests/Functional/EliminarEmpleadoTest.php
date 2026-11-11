<?php

namespace App\Tests\Functional;

use App\Tests\KontiaTestCase;

/** Al eliminar usuarios, el negocio nunca queda sin administrador. */
class EliminarEmpleadoTest extends KontiaTestCase
{
    public function testNoSeEliminaElPropioUsuarioNiElUltimoAdministrador(): void
    {
        $this->crearNegocio(1, [1]);
        $titular = $this->crearUsuario('titular@test.com', 1);
        $empleado = $this->crearUsuario('empleado@test.com', 1, false);
        $token = $this->tokenPara('titular@test.com');

        [$estado, $r] = $this->pedir('DELETE', '/api/empleados/' . $titular, null, $token);
        $this->assertSame(409, $estado);
        $this->assertSame('No podés eliminar tu propio usuario.', $r['errores'][0]);

        // Un empleado común sí se puede eliminar.
        [$estado] = $this->pedir('DELETE', '/api/empleados/' . $empleado, null, $token);
        $this->assertSame(200, $estado);

        // Con dos administradores se puede eliminar uno, pero no el último.
        $otroAdmin = $this->crearUsuario('admin2@test.com', 1);
        $tokenOtro = $this->tokenPara('admin2@test.com');
        [$estado] = $this->pedir('DELETE', '/api/empleados/' . $otroAdmin, null, $token);
        $this->assertSame(200, $estado);
        $this->pdo()->exec("UPDATE user SET eliminado = 0 WHERE id = $otroAdmin");
        $this->pdo()->exec("UPDATE user SET eliminado = 1 WHERE id = $titular");
        [$estado, $r] = $this->pedir('DELETE', '/api/empleados/' . $otroAdmin, null, $tokenOtro);
        $this->assertSame(409, $estado);
    }
}
