<?php

namespace App\Controller;

use App\DTO\Usuario\RegistrarUsuarioDTO;
use App\Form\Type\Usuario\RegistrarUsuarioType;
use App\Model\Usuario;
use App\Repository\UsuarioRepository;
use App\Service\AppLogs;
use App\Service\ServicioUsuario;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/api/usuario")
 */
class UsuarioController extends BaseController
{
    /**
     * @Route("/login", name="app_login", methods={"POST"})
     * @param UserInterface $usuario
     * @param JWTTokenManagerInterface $jwt_manager
     * @return JsonResponse
     */
    public function login(Usuario $usuario, JWTTokenManagerInterface $jwt_manager): JsonResponse
    {
        $token = $jwt_manager->create($usuario);
        $respuesta = [
            'token' => $token,
            'role' => $usuario->getRoles(),
            'id' => $usuario->getId()
        ];
        return $this->respuesta(200, $respuesta, []);
    }

    /**
     * @Route("/registrar_usuario", name="app_registrar_usuario", methods={"POST"})
     */
    public function registrar_usuario(UsuarioRepository $rep_usuario,  Request $request, ValidatorInterface $validator, ServicioUsuario $servicio_usuario): JsonResponse
    {
        $this->request_to_json($request);
        $dto = new RegistrarUsuarioDTO($rep_usuario);
        $form = $this->createForm(RegistrarUsuarioType::class, $dto);
        $form->handleRequest($request);

        $errores = $this->obtener_validaciones($validator, $dto);
        if (count($errores) > 0) {
            //$this->errores_to_log($errores, $log, 'registrar_email');
            return $this->respuesta(400, [], $errores, 400);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $servicio_usuario->registrar_usuario($dto);
                $respuesta = [
                    'OK' => 'OK'
                ];
                return $this->respuesta(200, [$dto], []);
            } catch (\Throwable $th) {
                //$log::get_log()->error('ENDPOINT: registrar_email ERROR: ' . $th->getMessage());
                return $this->respuesta(400, [], ['Ocurrió un error enviado la verificación de email.'], 400);
            }
        }

        // $log::get_log()->error('ENDPOINT: registrar_email ERROR: Ocurrió un error desconocido.');
        return $this->respuesta(400, [], ['Ocurrió un error desconocido.'], 400);
    }
}
