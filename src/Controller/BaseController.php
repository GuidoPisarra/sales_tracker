<?php

namespace App\Controller;

use App\Service\AppLogs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BaseController extends AbstractController
{
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
