<?php

namespace App\Controller;

use App\Repository\BaseRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * "Primeros pasos" de la pantalla de Inicio para un negocio nuevo: qué cosas
 * ya hizo (productos cargados, primera venta, empleados, catálogo online).
 */
class OnboardingController extends BaseController
{
    #[Route('/api/onboarding', name: 'app_onboarding', methods: ['GET'])]
    public function estado(BaseRepository $repo): JsonResponse
    {
        $idNegocio = $this->tenant()->idNegocioRequerido();
        $pdo = $repo->get_bbdd();
        $existe = function (string $sql) use ($pdo, $idNegocio): bool {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':n' => $idNegocio]);
                return (bool) $stmt->fetchColumn();
            } catch (\Throwable $th) {
                return false; // Tabla que no existe en una base vieja: se muestra como pendiente.
            }
        };
        return $this->respuesta(200, [
            'productos' => $existe('SELECT 1 FROM product WHERE id_negocio = :n LIMIT 1'),
            'venta' => $existe('SELECT 1 FROM sales WHERE id_negocio = :n LIMIT 1'),
            'empleados' => $existe("SELECT 1 FROM user WHERE id_negocio = :n AND role NOT LIKE '%ROLE_TITULAR%' AND (eliminado = 0 OR eliminado IS NULL) LIMIT 1"),
            'catalogo' => $existe('SELECT 1 FROM negocio_config WHERE id_negocio = :n AND catalogo_activo = 1 LIMIT 1'),
        ], []);
    }
}
