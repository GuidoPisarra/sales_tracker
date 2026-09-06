<?php

namespace App\Controller;

use App\Model\Usuario;
use App\Service\ChatIaService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

#[Route('/api/asistente')]
class ChatIaController extends BaseController
{
    #[Route('/mensaje', name: 'app_asistente_mensaje', methods: ['POST'])]
    public function mensaje(Request $request, ChatIaService $chat_ia_service): JsonResponse
    {
        $authorization = $request->headers->get('Authorization');
        if (!$authorization) {
            return $this->respuesta(401, [], ['Falta el token de autenticación'], 401);
        }

        // Se revalida en cada mensaje, no solo al loguearse: como el firewall es
        // stateless, $this->getUser() ya viene recién leído de la base en esta request.
        $usuario = $this->getUser();
        if (!$usuario instanceof Usuario || !$usuario->getAsistenteIa()) {
            return $this->respuesta(403, [], ['El asistente de IA no está habilitado para este usuario'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $mensaje = trim((string) ($data['mensaje'] ?? ''));
        if ($mensaje === '') {
            return $this->respuesta(400, [], ['El mensaje es obligatorio'], 400);
        }

        $threadId = isset($data['thread_id']) && $data['thread_id'] !== '' ? (string) $data['thread_id'] : '1';
        $sucursalId = isset($data['sucursal_id']) && $data['sucursal_id'] !== '' ? (string) $data['sucursal_id'] : null;

        // negocio_id y roles nunca se leen del body, aunque el cliente los mande:
        // el agente los saca del JWT ya reenviado en el header Authorization.

        try {
            $resultado = $chat_ia_service->consultarAgente($authorization, $mensaje, $threadId, $sucursalId);
            return $this->respuesta(200, $resultado, []);
        } catch (HttpClientExceptionInterface $e) {
            return $this->respuesta(502, [], ['El asistente no está disponible en este momento'], 502);
        } catch (\Throwable $th) {
            return $this->respuesta(500, [], ['Error al consultar el asistente'], 500);
        }
    }
}
