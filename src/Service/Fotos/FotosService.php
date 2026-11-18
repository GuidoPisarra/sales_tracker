<?php

namespace App\Service\Fotos;

use App\Repository\FotosRepository;
use App\Service\Entorno;
use App\Service\SuscripcionService;

/**
 * Fotos de productos para el catálogo online. Fotos por producto según el plan
 * (plan_suscripcion.max_fotos_producto: Starter 1, Pro 3, Premium 5). Los clientes
 * sin límites (plan de por vida) tienen FOTOS_MAX_SIN_LIMITES (5 por defecto).
 * Si el negocio baja de plan no se borra nada: el catálogo muestra las primeras.
 */
class FotosService
{
    public const POR_DEFECTO = 5;

    public function __construct(
        private FotosRepository $repo,
        private AlmacenFotos $almacen,
        private ProcesadorFotos $procesador,
        private SuscripcionService $suscripciones,
    ) {
    }

    /** Cuántas fotos por producto permite el plan del negocio. */
    public function maximo(int $idNegocio): int
    {
        if ($this->suscripciones->exento($idNegocio)) {
            return max(1, Entorno::int('FOTOS_MAX_SIN_LIMITES', self::POR_DEFECTO));
        }
        $max = $this->repo->plan($idNegocio)['max_fotos_producto'] ?? null;
        return $max === null ? self::POR_DEFECTO : max(0, (int) $max);
    }

    public function nombrePlan(int $idNegocio): ?string
    {
        return $this->repo->plan($idNegocio)['nombre'] ?? null;
    }

    public function producto(int $idNegocio, int $idProducto): ?array
    {
        return $this->repo->producto($idNegocio, $idProducto);
    }

    /** Fotos del producto + el límite, para la pantalla del negocio. */
    public function listado(int $idNegocio, int $idProducto): array
    {
        return [
            'fotos' => array_map([$this, 'formato'], $this->repo->porProducto($idNegocio, $idProducto)),
            'maximo' => $this->maximo($idNegocio),
            'plan' => $this->nombrePlan($idNegocio),
            'sin_limites' => $this->suscripciones->exento($idNegocio),
        ];
    }

    /** null si puede subir una más; si no, el mensaje para el usuario. */
    public function excedeLimite(int $idNegocio, int $idProducto): ?string
    {
        $max = $this->maximo($idNegocio);
        if ($this->repo->contar($idNegocio, $idProducto) < $max) {
            return null;
        }
        if ($this->suscripciones->exento($idNegocio)) {
            return sprintf('Podés cargar hasta %d fotos por producto. Borrá una para subir otra.', $max);
        }
        $plan = $this->nombrePlan($idNegocio) ?? 'actual';
        if ($max === 0) {
            return sprintf('Tu plan %s no incluye fotos de productos. Mejorá tu plan para usarlas.', $plan);
        }
        return sprintf('Tu plan %s permite %d %s por producto. Mejorá tu plan para agregar más.',
            $plan, $max, $max === 1 ? 'foto' : 'fotos');
    }

    /**
     * Procesa y guarda una foto. Lanza FotoInvalida si la foto no sirve.
     */
    public function subir(int $idNegocio, int $idProducto, string $rutaTemporal): array
    {
        $r = $this->procesador->procesar($rutaTemporal);
        $base = sprintf('n/%d/%s', $idNegocio, bin2hex(random_bytes(10)));
        $archivo = $base . '.webp';
        $miniatura = $base . '-m.webp';
        $this->almacen->guardar($archivo, $r['grande']);
        try {
            $this->almacen->guardar($miniatura, $r['mini']);
            $id = $this->repo->crear($idNegocio, $idProducto, $archivo, $miniatura, $r['ancho'], $r['alto'],
                strlen($r['grande']) + strlen($r['mini']));
        } catch (\Throwable $th) {
            $this->almacen->borrar($archivo);
            $this->almacen->borrar($miniatura);
            throw $th;
        }
        return $this->formato($this->repo->una($idNegocio, $idProducto, $id));
    }

    public function borrar(int $idNegocio, int $idProducto, int $idFoto): bool
    {
        $foto = $this->repo->una($idNegocio, $idProducto, $idFoto);
        if (!$foto) {
            return false;
        }
        $this->repo->borrar($idNegocio, $idFoto);
        $this->borrarArchivosSinUso($foto);
        // Se reacomoda el orden para que la siguiente quede de portada.
        $this->repo->reordenar($idNegocio, $idProducto, []);
        return true;
    }

    public function reordenar(int $idNegocio, int $idProducto, array $ids): array
    {
        $this->repo->reordenar($idNegocio, $idProducto, $ids);
        return array_map([$this, 'formato'], $this->repo->porProducto($idNegocio, $idProducto));
    }

    /**
     * Fotos para el catálogo público, cortadas al límite del plan actual.
     * @param int[] $idsProductos
     * @return array<int, array{url: string, mini: string}[]>
     */
    public function paraCatalogo(int $idNegocio, array $idsProductos): array
    {
        $max = $this->maximo($idNegocio);
        if ($max === 0) {
            return [];
        }
        $resultado = [];
        foreach ($this->repo->porProductos($idNegocio, $idsProductos) as $idProducto => $fotos) {
            $resultado[$idProducto] = array_map(fn ($f) => [
                'url' => $this->almacen->url($f['archivo']),
                'mini' => $this->almacen->url($f['miniatura']),
                'ancho' => (int) $f['ancho'],
                'alto' => (int) $f['alto'],
            ], array_slice($fotos, 0, $max));
        }
        return $resultado;
    }

    /** null si no se puede saber (por ejemplo, base sin la migración 0009). */
    public function productosSinFoto(int $idNegocio, int $sucursal): ?int
    {
        try {
            return $this->repo->productosSinFoto($idNegocio, $sucursal);
        } catch (\Throwable $th) {
            return null;
        }
    }

    /**
     * Borra filas y archivos de fotos cuyo producto ya no existe y, si se pide,
     * todas las de un negocio (baja definitiva). Devuelve cuántas fotos borró.
     */
    public function limpiar(?int $idNegocio = null): int
    {
        $fotos = $idNegocio !== null ? $this->repo->delNegocio($idNegocio) : $this->repo->huerfanas();
        foreach ($fotos as $f) {
            $this->repo->borrar((int) $f['id_negocio'], (int) $f['id']);
            $this->borrarArchivosSinUso($f);
        }
        return count($fotos);
    }

    private function borrarArchivosSinUso(array $foto): void
    {
        if ($this->repo->usosDeArchivo($foto['archivo']) > 0) {
            return; // Otro producto (por un traslado) usa el mismo archivo.
        }
        try {
            $this->almacen->borrar($foto['archivo']);
            $this->almacen->borrar($foto['miniatura']);
        } catch (\Throwable $th) {
            error_log('Fotos: no se pudo borrar ' . $foto['archivo'] . ': ' . $th->getMessage());
        }
    }

    public function formato(array $f): array
    {
        return [
            'id' => (int) $f['id'],
            'url' => $this->almacen->url($f['archivo']),
            'mini' => $this->almacen->url($f['miniatura']),
            'ancho' => (int) $f['ancho'],
            'alto' => (int) $f['alto'],
            'orden' => (int) $f['orden'],
        ];
    }
}
