<?php

namespace App\Tenant;

use App\Model\Usuario;
use App\Repository\BaseRepository;
use Symfony\Component\Security\Core\Security;

/**
 * Único lugar que resuelve a qué negocio (tenant) pertenece la request.
 *
 * El negocio sale siempre del usuario autenticado por JWT, nunca de la URL ni del
 * body. Los controllers y servicios nuevos lo piden por constructor en vez de
 * recibir un id_negocio del cliente.
 */
class TenantContext
{
    private Security $security;
    private BaseRepository $repo;
    /** @var array<int, int[]> */
    private array $sucursales = [];

    public function __construct(Security $security, BaseRepository $repo)
    {
        $this->security = $security;
        $this->repo = $repo;
    }

    public function usuario(): ?Usuario
    {
        $usuario = $this->security->getUser();
        return $usuario instanceof Usuario ? $usuario : null;
    }

    public function idNegocio(): ?int
    {
        return $this->usuario()?->getIdNegocio();
    }

    /**
     * Id del negocio actual o excepción si no hay usuario logueado con negocio.
     */
    public function idNegocioRequerido(): int
    {
        $id = $this->idNegocio();
        if ($id === null) {
            throw new \LogicException('No hay un negocio asociado a la sesión.');
        }
        return $id;
    }

    /**
     * Números de sucursal del negocio actual (columna negocio.sucursal).
     *
     * @return int[]
     */
    public function sucursales(): array
    {
        $id = $this->idNegocio();
        if ($id === null) {
            return [];
        }
        if (!isset($this->sucursales[$id])) {
            $stmt = $this->repo->get_bbdd()->prepare('SELECT sucursal FROM negocio WHERE id_negocio = :id');
            $stmt->execute([':id' => $id]);
            $this->sucursales[$id] = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        return $this->sucursales[$id];
    }

    public function sucursalPermitida(int|string|null $sucursal): bool
    {
        return $sucursal !== null && in_array((int) $sucursal, $this->sucursales(), true);
    }
}
