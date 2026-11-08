<?php

namespace App\Service;

use App\Repository\BaseRepository;
use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Registro de eventos sensibles (login, registro, cambios masivos de precio,
 * borrados, cambios de plan, pagos). Nunca corta la operación si falla.
 */
class AuditoriaService
{
    private BaseRepository $repo;
    private TenantContext $tenant;
    private RequestStack $requestStack;

    public function __construct(BaseRepository $repo, TenantContext $tenant, RequestStack $requestStack)
    {
        $this->repo = $repo;
        $this->tenant = $tenant;
        $this->requestStack = $requestStack;
    }

    public function registrar(string $accion, array $detalle = [], ?int $idNegocio = null, ?int $idUsuario = null): void
    {
        try {
            $usuario = $this->tenant->usuario();
            $stmt = $this->repo->get_bbdd()->prepare(
                'INSERT INTO auditoria (id_negocio, usuario_id, accion, detalle, ip) VALUES (:n, :u, :a, :d, :ip)'
            );
            $stmt->execute([
                ':n' => $idNegocio ?? $usuario?->getIdNegocio(),
                ':u' => $idUsuario ?? ($usuario ? $usuario->getId() : null),
                ':a' => $accion,
                ':d' => $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
                ':ip' => $this->requestStack->getCurrentRequest()?->getClientIp(),
            ]);
        } catch (\Throwable $th) {
            error_log('Auditoría: no se pudo registrar ' . $accion . ': ' . $th->getMessage());
        }
    }
}
