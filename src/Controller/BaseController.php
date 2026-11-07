<?php

namespace App\Controller;

use App\Model\Usuario;
use App\Service\AppLogs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BaseController extends AbstractController
{
    /**
     * Devuelve el id_negocio del usuario autenticado por JWT (nunca el que mande el cliente).
     */
    protected function idNegocioUsuarioActual(): ?int
    {
        $usuario = $this->getUser();
        return $usuario instanceof Usuario ? $usuario->getIdNegocio() : null;
    }

    protected function idUsuarioActual(): ?int
    {
        $usuario = $this->getUser();
        return $usuario instanceof Usuario ? $usuario->getId() : null;
    }

    /**
     * Verifica que el id_negocio pedido en la request sea el mismo del usuario autenticado.
     * Devolver null significa "autorizado, seguir"; si devuelve una JsonResponse hay que
     * retornarla inmediatamente (403) y no continuar con la acción.
     */
    protected function negocioPermitido($idNegocioSolicitado): ?JsonResponse
    {
        $idNegocioUsuario = $this->idNegocioUsuarioActual();
        if ($idNegocioUsuario === null || (int) $idNegocioSolicitado !== $idNegocioUsuario) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        return null;
    }

    /**
     * true si el usuario autenticado es el administrador del negocio (ROLE_TITULAR).
     * El rol se lee del campo "role" de la tabla user (getRoles() de Symfony siempre
     * devuelve ROLE_USER, así que no sirve para esto).
     */
    protected function esTitular(): bool
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof Usuario) {
            return false;
        }
        $roles = json_decode((string) $usuario->get_role(), true);
        return is_array($roles) && in_array('ROLE_TITULAR', $roles, true);
    }

    /**
     * Para endpoints exclusivos del administrador. Antes solo los protegía el
     * TitularGuard del front, así que un empleado podía llamarlos directo con su token.
     * Devuelve null si está autorizado; si no, una JsonResponse 403 para retornar.
     */
    protected function soloTitular(): ?JsonResponse
    {
        if ($this->esTitular()) {
            return null;
        }
        return $this->respuesta(403, [], ['Solo el administrador puede hacer esta acción.'], 403);
    }

    public function request_to_json(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        $request->request->replace($data);
    }

    public function obtener_validaciones(ValidatorInterface $validator, Object $modelo): array
    {
        $errores = [];
        $validaciones = $validator->validate($modelo);
        if (0 !== count($validaciones)) {
            foreach ($validaciones as $validacion) {
                $errores[] = $validacion->getMessage();
            }
        }

        return $errores;
    }

    public function respuesta(Int $estado, array $datos, array $errores, Int $estado_http = 200, array $headers = [])
    {
        $respuesta =
            [
                'estado'    =>  $estado,
                'errores'   =>  $errores,
                'datos'     =>  $datos
            ];
        return new JsonResponse($respuesta, $estado_http, $headers);
    }

    public function errores_to_log(array $errores, AppLogs $log, string $endpoint)
    {
        for ($i = 0; $i < count($errores); $i++) {
            $log::get_log()->error('ENDPOINT: ' . $endpoint . ' ERROR: ' . $errores[$i]);
        }
    }
}
