<?php

namespace App\Controller;

use App\Repository\BaseRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Preferencias del usuario logueado, guardadas en el servidor para que lo sigan
 * a otro teléfono. Solo claves conocidas, cada una con su validación.
 */
#[Route('/api/preferencias')]
class PreferenciasController extends BaseController
{
    /** Accesos rápidos que se pueden poner en el Inicio (ids que entiende el front). */
    public const ACCESOS_INICIO = ['vender', 'precio', 'cambio', 'producto', 'contar', 'caja', 'stock', 'productos', 'ventas', 'factura', 'qr', 'cta-cte'];

    public function __construct(private BaseRepository $repo)
    {
    }

    #[Route('/{clave}', name: 'app_preferencia_ver', requirements: ['clave' => '[a-z_]+'], methods: ['GET'])]
    public function ver(string $clave): JsonResponse
    {
        if (!$this->claveValida($clave)) {
            return $this->respuesta(404, [], ['Preferencia desconocida.'], 404);
        }
        $stmt = $this->repo->get_bbdd()->prepare('SELECT valor FROM usuario_preferencia WHERE id_usuario = ? AND clave = ?');
        $stmt->execute([(int) $this->idUsuarioActual(), $clave]);
        $valor = $stmt->fetchColumn();
        return $this->respuesta(200, ['valor' => $valor === false ? null : json_decode($valor, true)], []);
    }

    /** Body: {"valor": ...} */
    #[Route('/{clave}', name: 'app_preferencia_guardar', requirements: ['clave' => '[a-z_]+'], methods: ['POST'])]
    public function guardar(string $clave, Request $request): JsonResponse
    {
        if (!$this->claveValida($clave)) {
            return $this->respuesta(404, [], ['Preferencia desconocida.'], 404);
        }
        $datos = json_decode($request->getContent(), true) ?: [];
        $valor = $this->validar($clave, $datos['valor'] ?? null);
        if ($valor === null) {
            return $this->respuesta(400, [], ['El valor no es válido.'], 400);
        }
        $this->repo->get_bbdd()->prepare(
            'INSERT INTO usuario_preferencia (id_usuario, clave, valor) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        )->execute([(int) $this->idUsuarioActual(), $clave, json_encode($valor)]);
        return $this->respuesta(200, ['valor' => $valor], []);
    }

    private function claveValida(string $clave): bool
    {
        return $clave === 'inicio_accesos';
    }

    /** Devuelve el valor limpio, o null si no sirve. */
    private function validar(string $clave, $valor): ?array
    {
        if ($clave === 'inicio_accesos') {
            if (!is_array($valor)) {
                return null;
            }
            $ids = array_values(array_unique(array_filter($valor, fn ($v) => is_string($v) && in_array($v, self::ACCESOS_INICIO, true))));
            return count($ids) >= 1 && count($ids) <= 4 && count($ids) === count($valor) ? $ids : null;
        }
        return null;
    }
}
