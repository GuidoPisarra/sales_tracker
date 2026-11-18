<?php

namespace App\Controller;

use App\Service\Fotos\FotoInvalida;
use App\Service\Fotos\FotosService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Fotos de un producto (ver FotosService). Las usa el catálogo online.
 * Cualquier usuario del negocio con acceso a la sucursal del producto las puede cargar.
 */
#[Route('/api/productos/{idProducto}/fotos', requirements: ['idProducto' => '\d+'])]
class FotosController extends BaseController
{
    #[Route('', name: 'app_fotos_listar', methods: ['GET'])]
    public function listar(int $idProducto, FotosService $fotos): JsonResponse
    {
        if ($check = $this->productoPermitido($idProducto, $fotos)) {
            return $check;
        }
        return $this->respuesta(200, $fotos->listado((int) $this->idNegocioUsuarioActual(), $idProducto), []);
    }

    #[Route('', name: 'app_fotos_subir', methods: ['POST'])]
    public function subir(int $idProducto, Request $request, FotosService $fotos, RateLimiterFactory $fotosSubidaLimiter): JsonResponse
    {
        if ($check = $this->productoPermitido($idProducto, $fotos)) {
            return $check;
        }
        $idNegocio = (int) $this->idNegocioUsuarioActual();
        if (!$fotosSubidaLimiter->create('negocio-' . $idNegocio)->consume()->isAccepted()) {
            return $this->respuesta(429, [], ['Subiste muchas fotos seguidas. Esperá unos minutos y seguí.'], 429);
        }
        if ($mensaje = $fotos->excedeLimite($idNegocio, $idProducto)) {
            return $this->respuesta(402, ['codigo' => 'limite_plan', 'recurso' => 'fotos'], [$mensaje], 402);
        }
        $archivo = $request->files->get('foto');
        if (!$archivo || !$archivo->isValid()) {
            return $this->respuesta(400, [], ['No llegó la foto. Si es muy pesada, probá con otra o sacala de nuevo.'], 400);
        }
        try {
            $foto = $fotos->subir($idNegocio, $idProducto, $archivo->getPathname());
        } catch (FotoInvalida $e) {
            return $this->respuesta(400, [], [$e->getMessage()], 400);
        } catch (\Throwable $th) {
            error_log('Fotos: ' . $th->getMessage());
            return $this->respuesta(500, [], ['No se pudo guardar la foto. Probá de nuevo en un rato.'], 500);
        }
        return $this->respuesta(201, $foto, [], 201);
    }

    #[Route('/orden', name: 'app_fotos_orden', methods: ['PUT'])]
    public function ordenar(int $idProducto, Request $request, FotosService $fotos): JsonResponse
    {
        if ($check = $this->productoPermitido($idProducto, $fotos)) {
            return $check;
        }
        $d = json_decode($request->getContent(), true) ?: [];
        $ids = is_array($d['ids'] ?? null) ? $d['ids'] : [];
        return $this->respuesta(200, ['fotos' => $fotos->reordenar((int) $this->idNegocioUsuarioActual(), $idProducto, $ids)], []);
    }

    #[Route('/{idFoto}', name: 'app_fotos_borrar', requirements: ['idFoto' => '\d+'], methods: ['DELETE'])]
    public function borrar(int $idProducto, int $idFoto, FotosService $fotos): JsonResponse
    {
        if ($check = $this->productoPermitido($idProducto, $fotos)) {
            return $check;
        }
        if (!$fotos->borrar((int) $this->idNegocioUsuarioActual(), $idProducto, $idFoto)) {
            return $this->respuesta(404, [], ['Esa foto ya no existe.'], 404);
        }
        return $this->respuesta(200, $fotos->listado((int) $this->idNegocioUsuarioActual(), $idProducto), []);
    }

    private function productoPermitido(int $idProducto, FotosService $fotos): ?JsonResponse
    {
        $idNegocio = $this->idNegocioUsuarioActual();
        $producto = $idNegocio ? $fotos->producto($idNegocio, $idProducto) : null;
        if (!$producto) {
            return $this->respuesta(404, [], ['No encontramos ese producto.'], 404);
        }
        return $this->sucursalPermitida($producto['sucursal']);
    }
}
