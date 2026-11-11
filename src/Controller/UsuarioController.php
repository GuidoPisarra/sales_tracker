<?php

namespace App\Controller;

use App\Model\Usuario;
use App\Repository\UsuarioRepository;
use App\Service\ServicioUsuario;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;


#[Route('/api/usuario')]
class UsuarioController extends BaseController
{

    #[Route('/login', name: 'app_login', methods: ['POST'])]
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

}
