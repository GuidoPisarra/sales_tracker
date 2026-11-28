<?php

namespace App\Controller;

use App\Repository\PromoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Promos. Las ve cualquiera que vende (la Caja las aplica, también sin conexión); las
 * crea y cambia el administrador.
 */
#[Route('/api/promos')]
class PromosController extends BaseController
{
    public const TIPOS = ['nxm', 'porcentaje', 'cantidad'];

    #[Route('', name: 'app_promos_lista', methods: ['GET'])]
    public function lista(PromoRepository $repo): JsonResponse
    {
        $idNegocio = $this->idNegocioUsuarioActual();
        if ($idNegocio === null) {
            return $this->respuesta(403, [], ['No tiene permisos sobre este negocio.'], 403);
        }
        return $this->respuesta(200, array_map([self::class, 'formato'], $repo->lista($idNegocio)), []);
    }

    #[Route('', name: 'app_promos_guardar', methods: ['POST'])]
    public function guardar(Request $request, PromoRepository $repo): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $idNegocio = (int) $this->idNegocioUsuarioActual();
        $d = json_decode($request->getContent(), true);
        if (!is_array($d)) {
            return $this->respuesta(400, [], ['Faltan los datos de la promo.'], 400);
        }
        $id = isset($d['id']) && (int) $d['id'] > 0 ? (int) $d['id'] : null;
        if ($id && !$repo->porId($idNegocio, $id)) {
            return $this->respuesta(404, [], ['No se encontró la promo.'], 404);
        }
        [$campos, $productos, $error] = self::validar($d);
        if ($error) {
            return $this->respuesta(400, [], [$error], 400);
        }
        $id = $repo->guardar($idNegocio, $id, $campos, $productos);
        $promo = $repo->porId($idNegocio, $id);
        if (!$campos['todos_productos'] && !$promo['productos']) {
            $repo->borrar($idNegocio, $id);
            return $this->respuesta(400, [], ['Ninguno de los productos elegidos es de este negocio.'], 400);
        }
        return $this->respuesta(200, self::formato($promo), []);
    }

    #[Route('/{id}', name: 'app_promos_borrar', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function borrar(int $id, PromoRepository $repo): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        if (!$repo->borrar((int) $this->idNegocioUsuarioActual(), $id)) {
            return $this->respuesta(404, [], ['No se encontró la promo.'], 404);
        }
        return $this->respuesta(200, ['borrada' => true], []);
    }

    /** @return array{0: array, 1: int[], 2: ?string} campos, productos y error. */
    public static function validar(array $d): array
    {
        $nombre = mb_substr(trim((string) ($d['nombre'] ?? '')), 0, 80);
        $tipo = (string) ($d['tipo'] ?? '');
        if (!in_array($tipo, self::TIPOS, true)) {
            return [[], [], 'Elegí el tipo de promo.'];
        }
        $entero = fn ($v) => is_numeric($v) && (int) $v == $v ? (int) $v : null;
        $lleva = $entero($d['lleva'] ?? null);
        $paga = $entero($d['paga'] ?? null);
        $porcentaje = is_numeric($d['porcentaje'] ?? null) ? round((float) $d['porcentaje'], 2) : null;

        if ($tipo === 'nxm') {
            if ($lleva === null || $paga === null || $lleva < 2 || $lleva > 100 || $paga < 1 || $paga >= $lleva) {
                return [[], [], 'En una promo "lleva N y paga M", M tiene que ser menor que N (por ejemplo, 2x1 o 3x2).'];
            }
            $porcentaje = null;
            $nombre = $nombre ?: "{$lleva}x{$paga}";
        } else {
            if ($porcentaje === null || $porcentaje <= 0 || $porcentaje >= 100) {
                return [[], [], 'El descuento tiene que ser mayor que 0% y menor que 100%.'];
            }
            if ($tipo === 'cantidad') {
                if ($lleva === null || $lleva < 2 || $lleva > 10000) {
                    return [[], [], 'Indicá desde cuántas unidades vale el descuento (2 o más).'];
                }
            } else {
                $lleva = null;
            }
            $paga = null;
            $nombre = $nombre ?: ($tipo === 'cantidad' ? "Llevando {$lleva}, {$porcentaje}% off" : "{$porcentaje}% off");
        }

        $fecha = function ($v) {
            $v = trim((string) ($v ?? ''));
            $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $f && $f->format('Y-m-d') === $v ? $v : null;
        };
        $desde = $fecha($d['desde'] ?? null);
        $hasta = $fecha($d['hasta'] ?? null);
        if ($desde && $hasta && $hasta < $desde) {
            return [[], [], 'La fecha "hasta" no puede ser anterior a "desde".'];
        }
        $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($d['dias'] ?? [])), fn ($x) => $x >= 1 && $x <= 7)));
        sort($dias);

        $todos = !empty($d['todos_productos']);
        $productos = $todos ? [] : array_values(array_unique(array_filter(array_map('intval', (array) ($d['productos'] ?? [])), fn ($x) => $x > 0)));
        if (!$todos && !$productos) {
            return [[], [], 'Elegí al menos un producto o marcá "Todos los productos".'];
        }
        if (count($productos) > 2000) {
            return [[], [], 'Una promo puede tener hasta 2000 productos.'];
        }

        return [[
            'nombre' => $nombre,
            'tipo' => $tipo,
            'lleva' => $lleva,
            'paga' => $paga,
            'porcentaje' => $porcentaje,
            'todos_productos' => $todos ? 1 : 0,
            'desde' => $desde,
            'hasta' => $hasta,
            'dias' => $dias && count($dias) < 7 ? implode(',', $dias) : null,
            'activo' => array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1,
        ], $productos, null];
    }

    public static function formato(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'nombre' => $p['nombre'],
            'tipo' => $p['tipo'],
            'lleva' => $p['lleva'] === null ? null : (int) $p['lleva'],
            'paga' => $p['paga'] === null ? null : (int) $p['paga'],
            'porcentaje' => $p['porcentaje'] === null ? null : (float) $p['porcentaje'],
            'todos_productos' => (int) $p['todos_productos'] === 1,
            'productos' => $p['productos'] ?? [],
            'desde' => $p['desde'],
            'hasta' => $p['hasta'],
            'dias' => $p['dias'] ? array_map('intval', explode(',', $p['dias'])) : [],
            'activo' => (int) $p['activo'] === 1,
        ];
    }
}
