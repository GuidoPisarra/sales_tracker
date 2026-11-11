<?php

namespace App\Controller;

use App\Repository\EscaneoRepository;
use App\Service\Facturas\FacturaIaService;
use App\Service\SuscripcionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * "Cargar factura" (solo administrador): la IA lee la factura del proveedor y
 * sugiere los productos; al confirmar se suma el stock y se crean los nuevos.
 * Cada lectura cuenta como un mensaje de IA del plan (mensajes_ia).
 */
#[Route('/api/facturas')]
class FacturasController extends BaseController
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];
    public const TAMANIO_MAXIMO = 8 * 1024 * 1024;

    /** Lee una factura subida desde la PC (multipart: archivo, sucursal). */
    #[Route('/leer', name: 'app_facturas_leer', methods: ['POST'])]
    public function leer(Request $request, FacturaIaService $facturas, SuscripcionService $suscripciones): JsonResponse
    {
        $archivo = $request->files->get('archivo');
        if (!$archivo || !$archivo->isValid()) {
            return $this->respuesta(400, [], ['No llegó el archivo. Si es muy pesado, probá con una foto en vez del PDF.'], 400);
        }
        $mime = (string) $archivo->getMimeType();
        $contenido = (string) file_get_contents($archivo->getPathname());
        return $this->leerContenido($request->request->get('sucursal'), $contenido, $mime, $facturas, $suscripciones);
    }

    /** Lee una foto que mandó el celular vinculado: {sesion, foto, sucursal}. */
    #[Route('/leer-escaneo', name: 'app_facturas_leer_escaneo', methods: ['POST'])]
    public function leerEscaneo(Request $request, FacturaIaService $facturas, SuscripcionService $suscripciones, EscaneoRepository $escaneo): JsonResponse
    {
        $d = json_decode($request->getContent(), true) ?: [];
        $foto = $escaneo->fotoDelUsuario((int) ($d['sesion'] ?? 0), (int) ($d['foto'] ?? 0), (int) $this->idUsuarioActual());
        if (!$foto) {
            return $this->respuesta(404, [], ['No se encontró la foto. Volvé a sacarla con el celular.'], 404);
        }
        return $this->leerContenido($d['sucursal'] ?? null, $foto['datos'], $foto['mime'], $facturas, $suscripciones);
    }

    private function leerContenido(mixed $sucursal, string $contenido, string $mime, FacturaIaService $facturas, SuscripcionService $suscripciones): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        if (!is_numeric($sucursal)) {
            return $this->respuesta(400, [], ['Falta la sucursal.'], 400);
        }
        if ($check = $this->sucursalPermitida($sucursal)) {
            return $check;
        }
        if (!in_array($mime, self::MIMES, true)) {
            return $this->respuesta(415, [], ['Subí una foto (JPG, PNG, WEBP) o un PDF de la factura.'], 415);
        }
        if (strlen($contenido) === 0 || strlen($contenido) > self::TAMANIO_MAXIMO) {
            return $this->respuesta(413, [], ['El archivo tiene que pesar menos de 8 MB.'], 413);
        }
        if (!$facturas->configurado()) {
            return $this->respuesta(503, [], ['La lectura de facturas con IA todavía no está configurada.'], 503);
        }
        $idNegocio = (int) $this->idNegocioUsuarioActual();
        if ($mensaje = $suscripciones->excedeLimite($idNegocio, 'mensajes_ia')) {
            return $this->respuesta(402, ['codigo' => 'limite_plan', 'recurso' => 'mensajes_ia'], [$mensaje], 402);
        }
        try {
            $factura = $facturas->leer($idNegocio, (int) $sucursal, $contenido, $mime);
        } catch (\RuntimeException $e) {
            return $this->respuesta(422, [], [$e->getMessage()], 422);
        } catch (\Throwable $th) {
            error_log('app_facturas_leer: ' . $th->getMessage());
            return $this->respuesta(500, [], ['No se pudo leer la factura. Probá de nuevo.'], 500);
        }
        $suscripciones->registrarMensajeIa($idNegocio);
        return $this->respuesta(200, $factura, []);
    }

    /** Confirma los renglones: {sucursal, proveedor_clave, items: [...]} (ver FacturaIaService::validarConfirmacion). */
    #[Route('/confirmar', name: 'app_facturas_confirmar', methods: ['POST'])]
    public function confirmar(Request $request, FacturaIaService $facturas, SuscripcionService $suscripciones): JsonResponse
    {
        if ($check = $this->soloTitular()) {
            return $check;
        }
        $d = json_decode($request->getContent(), true) ?: [];
        $sucursal = $d['sucursal'] ?? null;
        if (!is_numeric($sucursal)) {
            return $this->respuesta(400, [], ['Falta la sucursal.'], 400);
        }
        if ($check = $this->sucursalPermitida($sucursal)) {
            return $check;
        }
        $crudos = is_array($d['items'] ?? null) ? $d['items'] : [];
        if (!$crudos) {
            return $this->respuesta(400, [], ['No hay renglones para cargar.'], 400);
        }
        if (count($crudos) > FacturaIaService::MAXIMO_RENGLONES) {
            return $this->respuesta(400, [], ['La factura tiene demasiados renglones.'], 400);
        }
        [$renglones, $errores] = FacturaIaService::validarConfirmacion($crudos);
        if ($errores) {
            return $this->respuesta(400, [], $errores, 400);
        }
        $idNegocio = (int) $this->idNegocioUsuarioActual();
        $nuevos = count(array_filter($renglones, fn ($r) => $r['accion'] === 'crear'));
        if ($nuevos > 0 && ($mensaje = $suscripciones->excedeLimite($idNegocio, 'productos', $nuevos))) {
            return $this->respuesta(402, ['codigo' => 'limite_plan', 'recurso' => 'productos'], [$mensaje], 402);
        }
        $proveedor = mb_substr(trim((string) ($d['proveedor_clave'] ?? '')), 0, 100);
        try {
            $resultado = $facturas->confirmar($idNegocio, (int) $sucursal, $proveedor, $renglones, (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s'));
            return $this->respuesta(200, $resultado, []);
        } catch (\DomainException $e) {
            return $this->respuesta(409, [], [$e->getMessage()], 409);
        } catch (\Throwable $th) {
            error_log('app_facturas_confirmar: ' . $th->getMessage());
            return $this->respuesta(500, [], ['No se pudo cargar la factura. No se modificó nada; probá de nuevo.'], 500);
        }
    }
}
