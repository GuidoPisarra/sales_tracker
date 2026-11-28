<?php

namespace App\Service;

use App\Repository\FacturaRepository;
use App\Service\Arca\ArcaRechazo;
use App\Service\Arca\ArcaSinRespuesta;
use App\Service\Arca\ArcaTransporte;
use App\Service\Arca\ArcaWsaa;
use App\Service\Arca\ArcaWsfe;
use App\Service\Arca\Certificados;

/**
 * Factura electrónica ARCA desde la venta.
 *
 * Cada venta se puede facturar una vez (Factura A, B o C según la condición frente
 * al IVA del negocio y del cliente). Si se anula la venta, se emite la nota de
 * crédito. Si ARCA no responde, el comprobante queda "pendiente" y el comando
 * app:facturas:reintentar lo vuelve a intentar; la venta nunca se pierde por eso.
 */
class FacturacionService
{
    public const CONDICIONES = ['monotributo', 'responsable_inscripto', 'exento'];
    public const ALICUOTAS = [21.0, 10.5, 27.0, 5.0, 2.5, 0.0];

    /** Condición frente al IVA del receptor => código ARCA (RG 5616). */
    public const CONDICION_RECEPTOR = [
        'consumidor_final' => 5,
        'responsable_inscripto' => 1,
        'monotributo' => 6,
        'exento' => 4,
    ];

    public const NOMBRES_CBTE = [
        1 => ['A', 'Factura'], 6 => ['B', 'Factura'], 11 => ['C', 'Factura'],
        3 => ['A', 'Nota de crédito'], 8 => ['B', 'Nota de crédito'], 13 => ['C', 'Nota de crédito'],
    ];
    private const NOTA_DE = [1 => 3, 6 => 8, 11 => 13];

    private ArcaWsaa $wsaa;
    private ArcaWsfe $wsfe;

    public function __construct(
        private FacturaRepository $repo,
        private SuscripcionService $suscripciones,
        ArcaTransporte $transporte
    ) {
        $this->wsaa = new ArcaWsaa($transporte);
        $this->wsfe = new ArcaWsfe($transporte);
    }

    // ===================== Configuración =====================

    /** Configuración para la pantalla (sin la clave privada). */
    public function config(int $idNegocio): array
    {
        $f = $this->repo->fiscal($idNegocio) ?? [];
        return [
            'incluido_en_plan' => $this->suscripciones->incluyeFacturacion($idNegocio),
            'activo' => !empty($f['activo']),
            'entorno' => $f['entorno'] ?? 'homologacion',
            'cuit' => $f['cuit'] ?? null,
            'razon_social' => $f['razon_social'] ?? null,
            'condicion_iva' => $f['condicion_iva'] ?? null,
            'domicilio' => $f['domicilio'] ?? null,
            'ingresos_brutos' => $f['ingresos_brutos'] ?? null,
            'inicio_actividades' => $f['inicio_actividades'] ?? null,
            'punto_venta' => isset($f['punto_venta']) ? (int) $f['punto_venta'] : null,
            'alicuota_iva' => isset($f['alicuota_iva']) ? (float) $f['alicuota_iva'] : 21.0,
            'modo' => $f['modo'] ?? 'manual',
            'pedido_certificado' => $f['pedido_certificado'] ?? null,
            'tiene_certificado' => !empty($f['certificado']),
            'certificado_vence' => $f['certificado_vence'] ?? null,
            'datos_completos' => $this->faltaDato($f) === null,
            'tope_consumidor_final' => $this->topeConsumidorFinal(),
        ];
    }

    /** Guarda los datos fiscales. Cambiar de entorno descarta el certificado (son distintos). */
    public function guardarConfig(int $idNegocio, array $d): array
    {
        $actual = $this->repo->fiscal($idNegocio) ?? [];
        $campos = [];
        if (array_key_exists('cuit', $d)) {
            $cuit = preg_replace('/\D/', '', (string) $d['cuit']);
            if ($cuit !== '' && !self::cuitValido($cuit)) {
                throw new ArcaRechazo('El CUIT no es válido. Revisá los 11 números.');
            }
            $campos['cuit'] = $cuit ?: null;
        }
        foreach (['razon_social' => 150, 'domicilio' => 200, 'ingresos_brutos' => 40] as $c => $max) {
            if (array_key_exists($c, $d)) {
                $v = trim((string) $d[$c]);
                $campos[$c] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }
        if (array_key_exists('condicion_iva', $d)) {
            if (!in_array($d['condicion_iva'], self::CONDICIONES, true)) {
                throw new ArcaRechazo('Elegí tu condición frente al IVA.');
            }
            $campos['condicion_iva'] = $d['condicion_iva'];
        }
        if (array_key_exists('inicio_actividades', $d)) {
            $v = (string) $d['inicio_actividades'];
            $campos['inicio_actividades'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        }
        if (array_key_exists('punto_venta', $d)) {
            $pv = (int) $d['punto_venta'];
            if ($pv < 1 || $pv > 99998) {
                throw new ArcaRechazo('El punto de venta es un número de 1 a 99998.');
            }
            $campos['punto_venta'] = $pv;
        }
        if (array_key_exists('alicuota_iva', $d)) {
            $a = round((float) $d['alicuota_iva'], 1);
            if (!in_array($a, self::ALICUOTAS, true)) {
                throw new ArcaRechazo('La alícuota de IVA tiene que ser 21, 10,5, 27, 5, 2,5 o 0.');
            }
            $campos['alicuota_iva'] = $a;
        }
        if (array_key_exists('modo', $d)) {
            $campos['modo'] = $d['modo'] === 'automatico' ? 'automatico' : 'manual';
        }
        if (array_key_exists('entorno', $d)) {
            $entorno = $d['entorno'] === 'produccion' ? 'produccion' : 'homologacion';
            if ($entorno !== ($actual['entorno'] ?? 'homologacion')) {
                $campos['entorno'] = $entorno;
                $campos['certificado'] = null;
                $campos['certificado_vence'] = null;
                $campos['clave_privada'] = null;
                $campos['pedido_certificado'] = null;
                $campos['activo'] = 0;
                $this->repo->borrarTokens($idNegocio);
            }
        }
        if (array_key_exists('activo', $d)) {
            $activo = !empty($d['activo']);
            if ($activo) {
                if (!$this->suscripciones->incluyeFacturacion($idNegocio)) {
                    throw new ArcaRechazo('La factura electrónica viene en los planes Pro y Premium. Cambiá de plan para usarla.');
                }
                if ($falta = $this->faltaDato(array_merge($actual, $campos))) {
                    throw new ArcaRechazo('Para activar la factura electrónica falta: ' . $falta . '.');
                }
            }
            $campos['activo'] = $activo ? 1 : 0;
        }
        $this->repo->guardarFiscal($idNegocio, $campos);
        return $this->config($idNegocio);
    }

    /** Genera la clave y el pedido de certificado (CSR) para subir a ARCA. */
    public function generarPedido(int $idNegocio): string
    {
        $f = $this->repo->fiscal($idNegocio) ?? [];
        if (empty($f['cuit']) || empty($f['razon_social'])) {
            throw new ArcaRechazo('Primero guardá el CUIT y la razón social.');
        }
        $par = Certificados::generarClaveYPedido($f['cuit'], $f['razon_social']);
        $this->repo->guardarFiscal($idNegocio, [
            'clave_privada' => Certificados::cifrar($par['clave']),
            'pedido_certificado' => $par['pedido'],
            'certificado' => null,
            'certificado_vence' => null,
            'activo' => 0,
        ]);
        $this->repo->borrarTokens($idNegocio);
        return $par['pedido'];
    }

    public function guardarCertificado(int $idNegocio, string $pem): array
    {
        $f = $this->repo->fiscal($idNegocio) ?? [];
        if (empty($f['clave_privada'])) {
            throw new ArcaRechazo('Primero generá el pedido de certificado en Kontia.');
        }
        $datos = Certificados::validarCertificado($pem, Certificados::descifrar($f['clave_privada']));
        if ($datos['cuit'] && !empty($f['cuit']) && $datos['cuit'] !== $f['cuit']) {
            throw new ArcaRechazo("Ese certificado es del CUIT {$datos['cuit']}, pero el negocio tiene cargado el {$f['cuit']}.");
        }
        $this->repo->guardarFiscal($idNegocio, ['certificado' => $datos['certificado'], 'certificado_vence' => $datos['vence']]);
        $this->repo->borrarTokens($idNegocio);
        return $this->config($idNegocio);
    }

    /** Prueba la conexión con ARCA: acceso con el certificado y último número emitido. */
    public function probar(int $idNegocio): array
    {
        $f = $this->repo->fiscal($idNegocio) ?? [];
        if ($falta = $this->faltaDato($f)) {
            throw new ArcaRechazo('Falta: ' . $falta . '.');
        }
        $auth = $this->acceso($idNegocio, $f);
        $tipo = $this->tipoFactura($f, 'consumidor_final');
        $ultimo = $this->wsfe->ultimoAutorizado($f['entorno'], $auth, (int) $f['punto_venta'], $tipo);
        [$letra] = self::NOMBRES_CBTE[$tipo];
        return [
            'ok' => true,
            'mensaje' => "Conexión correcta con ARCA. Última Factura $letra del punto de venta {$f['punto_venta']}: " . ($ultimo ?: 'ninguna todavía') . '.',
            'ultimo' => $ultimo,
        ];
    }

    // ===================== Facturar =====================

    /**
     * Factura una venta. $receptor: {condicion, doc_tipo: 'cuit'|'dni'|null, doc_nro, nombre}.
     * Devuelve el comprobante (emitido, pendiente o rechazado). Lanza ArcaRechazo si los
     * datos no permiten facturar (sin pedirle nada a ARCA).
     */
    public function facturar(int $idNegocio, int $idSale, array $receptor = []): array
    {
        $f = $this->listoParaFacturar($idNegocio);
        $venta = $this->repo->venta($idNegocio, $idSale);
        if ($venta === null) {
            throw new ArcaRechazo('No se encontró la venta.');
        }
        if ($venta['anulada']) {
            throw new ArcaRechazo('La venta está anulada: no se puede facturar.');
        }
        if ($venta['total'] <= 0) {
            throw new ArcaRechazo('La venta no tiene importe para facturar.');
        }

        $existentes = $this->repo->deVenta($idNegocio, $idSale);
        foreach ($existentes as $e) {
            if (isset(self::NOTA_DE[(int) $e['tipo_cbte']]) && in_array($e['estado'], ['emitida', 'pendiente'], true)) {
                if ($e['estado'] === 'pendiente') {
                    return $this->procesar((int) $e['id']);
                }
                return $this->resumen($e, $f);
            }
        }

        $r = $this->receptor($receptor, $venta['total']);
        $tipo = $this->tipoFactura($f, $r['condicion']);
        if ($tipo === 1 && $r['doc_tipo'] !== 80) {
            throw new ArcaRechazo('Para hacer Factura A necesitás el CUIT del cliente.');
        }
        [$neto, $iva, $alicuota] = $this->importes($f, $tipo, $venta['total']);

        $fila = [
            'id_negocio' => $idNegocio,
            'id_sale' => $idSale,
            'entorno' => $f['entorno'],
            'tipo_cbte' => $tipo,
            'punto_venta' => (int) $f['punto_venta'],
            'fecha' => $this->hoy(),
            'doc_tipo' => $r['doc_tipo'],
            'doc_nro' => $r['doc_nro'],
            'receptor_nombre' => $r['nombre'],
            'receptor_condicion_iva' => self::CONDICION_RECEPTOR[$r['condicion']],
            'importe_total' => $venta['total'],
            'importe_neto' => $neto,
            'importe_iva' => $iva,
            'alicuota_iva' => $alicuota,
            'estado' => 'pendiente',
            'creado' => $this->ahora(),
        ];
        // Un intento rechazado antes (por ejemplo, CUIT mal cargado) se reusa con los datos nuevos.
        foreach ($existentes as $e) {
            if ($e['estado'] === 'rechazada' && isset(self::NOTA_DE[(int) $e['tipo_cbte']])) {
                unset($fila['id_negocio'], $fila['id_sale'], $fila['creado']);
                $this->repo->actualizar((int) $e['id'], $fila + ['numero' => null, 'error' => null]);
                return $this->procesar((int) $e['id']);
            }
        }
        return $this->procesar($this->repo->crear($fila));
    }

    /** Con el modo automático, factura la venta a consumidor final apenas se registra. */
    public function facturarSiEsAutomatico(int $idNegocio, int $idSale): ?array
    {
        $f = $this->repo->fiscal($idNegocio);
        if (!$f || empty($f['activo']) || ($f['modo'] ?? '') !== 'automatico' || !$this->suscripciones->incluyeFacturacion($idNegocio)) {
            return null;
        }
        $venta = $this->repo->venta($idNegocio, $idSale);
        // Las ventas grandes sin datos del cliente no se pueden facturar a consumidor final anónimo.
        if ($venta === null || $venta['total'] >= $this->topeConsumidorFinal()) {
            return null;
        }
        try {
            return $this->facturar($idNegocio, $idSale, []);
        } catch (ArcaRechazo $e) {
            return null;
        }
    }

    /** Al anular una venta facturada se emite la nota de crédito. Devuelve null si no había factura. */
    public function anularVenta(int $idNegocio, int $idSale): ?array
    {
        $f = $this->repo->fiscal($idNegocio);
        if (!$f) {
            return null;
        }
        $factura = null;
        foreach ($this->repo->deVenta($idNegocio, $idSale) as $e) {
            $tipo = (int) $e['tipo_cbte'];
            if (!isset(self::NOTA_DE[$tipo])) {
                if (in_array($e['estado'], ['emitida', 'pendiente'], true)) {
                    return $e['estado'] === 'pendiente' ? $this->procesar((int) $e['id']) : $this->resumen($e, $f);
                }
                continue; // Ya hay nota de crédito.
            }
            if ($e['estado'] === 'emitida') {
                $factura = $e;
                break;
            }
            if ($e['estado'] === 'pendiente') {
                // La factura todavía no salió: se descarta en vez de hacer factura + nota.
                $this->repo->actualizar((int) $e['id'], ['estado' => 'cancelada', 'error' => 'Venta anulada antes de que ARCA respondiera.']);
                return null;
            }
        }
        if ($factura === null) {
            return null;
        }
        $id = $this->repo->crear([
            'id_negocio' => $idNegocio,
            'id_sale' => $idSale,
            'entorno' => $factura['entorno'],
            'tipo_cbte' => self::NOTA_DE[(int) $factura['tipo_cbte']],
            'punto_venta' => (int) $factura['punto_venta'],
            'fecha' => $this->hoy(),
            'doc_tipo' => (int) $factura['doc_tipo'],
            'doc_nro' => $factura['doc_nro'],
            'receptor_nombre' => $factura['receptor_nombre'],
            'receptor_condicion_iva' => (int) $factura['receptor_condicion_iva'],
            'importe_total' => $factura['importe_total'],
            'importe_neto' => $factura['importe_neto'],
            'importe_iva' => $factura['importe_iva'],
            'alicuota_iva' => $factura['alicuota_iva'],
            'id_factura_asociada' => (int) $factura['id'],
            'estado' => 'pendiente',
            'creado' => $this->ahora(),
        ]);
        return $this->procesar($id);
    }

    /** Comprobantes de una venta, para el ticket y la pantalla de ventas. */
    public function deVenta(int $idNegocio, int $idSale): array
    {
        $f = $this->repo->fiscal($idNegocio) ?? [];
        return array_map(fn ($e) => $this->resumen($e, $f), $this->repo->deVenta($idNegocio, $idSale));
    }

    public function lista(int $idNegocio, ?string $desde, ?string $hasta, ?string $estado): array
    {
        $f = $this->repo->fiscal($idNegocio) ?? [];
        return array_map(fn ($e) => $this->resumen($e, $f, false), $this->repo->lista($idNegocio, $desde, $hasta, $estado));
    }

    /** Reintento manual de un comprobante pendiente. */
    public function reintentar(int $idNegocio, int $idFactura): array
    {
        $e = $this->repo->porId($idNegocio, $idFactura);
        if (!$e) {
            throw new ArcaRechazo('No se encontró el comprobante.');
        }
        if ($e['estado'] !== 'pendiente') {
            return $this->resumen($e, $this->repo->fiscal($idNegocio) ?? []);
        }
        return $this->procesar($idFactura);
    }

    /** Reintenta los pendientes de todos los negocios (comando programado). @return array{emitidas: int, pendientes: int, rechazadas: int} */
    public function reintentarPendientes(int $limite = 50): array
    {
        $cuenta = ['emitidas' => 0, 'pendientes' => 0, 'rechazadas' => 0];
        foreach ($this->repo->pendientes($limite) as $p) {
            try {
                $r = $this->procesar((int) $p['id']);
                $clave = ['emitida' => 'emitidas', 'rechazada' => 'rechazadas'][$r['estado']] ?? 'pendientes';
                $cuenta[$clave]++;
            } catch (\Throwable $e) {
                $cuenta['pendientes']++;
                error_log('reintentar factura ' . $p['id'] . ': ' . $e->getMessage());
            }
        }
        return $cuenta;
    }

    // ===================== Emisión =====================

    /** Pide el CAE de un comprobante pendiente. Nunca lanza por falta de respuesta de ARCA. */
    private function procesar(int $idFactura): array
    {
        $e = $this->repo->porIdSinNegocio($idFactura);
        $idNegocio = (int) $e['id_negocio'];
        $f = $this->repo->fiscal($idNegocio) ?? [];
        if ($e['estado'] !== 'pendiente') {
            return $this->resumen($e, $f);
        }
        $pv = (int) $e['punto_venta'];
        $tipo = (int) $e['tipo_cbte'];
        if (!$this->repo->bloquear($idNegocio, $pv)) {
            return $this->resumen($e, $f);
        }
        try {
            $auth = $this->acceso($idNegocio, $f);
            // Si un intento anterior se cortó después de pedir un número, primero se
            // consulta si ARCA llegó a emitirlo, para no facturar dos veces.
            if (!empty($e['numero'])) {
                $previo = $this->wsfe->consultar($e['entorno'], $auth, $pv, $tipo, (int) $e['numero']);
                if ($previo && abs($previo['total'] - (float) $e['importe_total']) < 0.01) {
                    $this->repo->actualizar($idFactura, ['estado' => 'emitida', 'cae' => $previo['cae'], 'cae_vence' => $previo['vence'], 'emitida' => $this->ahora(), 'error' => null]);
                    return $this->resumen($this->repo->porIdSinNegocio($idFactura), $f);
                }
            }
            $numero = $this->wsfe->ultimoAutorizado($e['entorno'], $auth, $pv, $tipo) + 1;
            $this->repo->actualizar($idFactura, ['numero' => $numero, 'intentos' => (int) $e['intentos'] + 1]);

            $asociado = null;
            if (!empty($e['id_factura_asociada'])) {
                $orig = $this->repo->porIdSinNegocio((int) $e['id_factura_asociada']);
                $asociado = ['tipo' => (int) $orig['tipo_cbte'], 'punto_venta' => (int) $orig['punto_venta'], 'numero' => (int) $orig['numero'], 'cuit' => $f['cuit'], 'fecha' => $orig['fecha']];
            }
            $fecha = $this->hoy();
            $r = $this->wsfe->solicitarCae($e['entorno'], $auth, [
                'tipo' => $tipo,
                'punto_venta' => $pv,
                'numero' => $numero,
                'fecha' => $fecha,
                'doc_tipo' => (int) $e['doc_tipo'],
                'doc_nro' => $e['doc_nro'],
                'condicion_iva_receptor' => (int) $e['receptor_condicion_iva'],
                'total' => (float) $e['importe_total'],
                'neto' => (float) $e['importe_neto'],
                'iva' => (float) $e['importe_iva'],
                'alicuota' => $e['alicuota_iva'] === null ? null : (float) $e['alicuota_iva'],
                'asociado' => $asociado,
            ]);
            $this->repo->actualizar($idFactura, [
                'estado' => 'emitida', 'cae' => $r['cae'], 'cae_vence' => $r['vence'], 'fecha' => $fecha,
                'emitida' => $this->ahora(), 'error' => $r['observaciones'] ? mb_substr(implode(' · ', $r['observaciones']), 0, 500) : null,
            ]);
        } catch (ArcaRechazo $ex) {
            $this->repo->actualizar($idFactura, ['estado' => 'rechazada', 'numero' => null, 'error' => mb_substr($ex->getMessage(), 0, 500)]);
        } catch (ArcaSinRespuesta $ex) {
            $this->repo->actualizar($idFactura, ['error' => mb_substr($ex->getMessage(), 0, 500)]);
        } catch (\Throwable $ex) {
            error_log('factura ' . $idFactura . ': ' . $ex->getMessage());
            $this->repo->actualizar($idFactura, ['error' => 'Error inesperado al facturar. Se va a reintentar.']);
        } finally {
            $this->repo->desbloquear($idNegocio, $pv);
        }
        return $this->resumen($this->repo->porIdSinNegocio($idFactura), $f);
    }

    /** Ticket de acceso de ARCA (se guarda 12 horas). */
    private function acceso(int $idNegocio, array $f): array
    {
        $guardado = $this->repo->token($idNegocio, $f['entorno'], 'wsfe');
        if (!$guardado) {
            if (empty($f['certificado']) || empty($f['clave_privada'])) {
                throw new ArcaRechazo('Falta el certificado de ARCA.');
            }
            $guardado = $this->wsaa->pedirAcceso($f['entorno'], $f['certificado'], Certificados::descifrar($f['clave_privada']));
            $this->repo->guardarToken($idNegocio, $f['entorno'], 'wsfe', $guardado);
        }
        return ['token' => $guardado['token'], 'sign' => $guardado['sign'], 'cuit' => $f['cuit']];
    }

    // ===================== Reglas =====================

    private function listoParaFacturar(int $idNegocio): array
    {
        if (!$this->suscripciones->incluyeFacturacion($idNegocio)) {
            throw new ArcaRechazo('La factura electrónica viene en los planes Pro y Premium.');
        }
        $f = $this->repo->fiscal($idNegocio);
        if (!$f || empty($f['activo'])) {
            throw new ArcaRechazo('La factura electrónica no está activada. El administrador la configura en Facturación electrónica.');
        }
        if ($falta = $this->faltaDato($f)) {
            throw new ArcaRechazo('Para facturar falta: ' . $falta . '.');
        }
        return $f;
    }

    private function faltaDato(array $f): ?string
    {
        $faltan = [];
        if (empty($f['cuit'])) {
            $faltan[] = 'el CUIT';
        }
        if (empty($f['razon_social'])) {
            $faltan[] = 'la razón social';
        }
        if (empty($f['condicion_iva'])) {
            $faltan[] = 'la condición frente al IVA';
        }
        if (empty($f['punto_venta'])) {
            $faltan[] = 'el punto de venta';
        }
        if (empty($f['certificado'])) {
            $faltan[] = 'el certificado de ARCA';
        }
        return $faltan ? implode(', ', $faltan) : null;
    }

    /** Monotributo y exento hacen Factura C; responsable inscripto, A (a RI o monotributo) o B. */
    private function tipoFactura(array $f, string $condicionReceptor): int
    {
        if (($f['condicion_iva'] ?? '') !== 'responsable_inscripto') {
            return 11;
        }
        return in_array($condicionReceptor, ['responsable_inscripto', 'monotributo'], true) ? 1 : 6;
    }

    /** @return array{0: float, 1: float, 2: ?float} neto, IVA y alícuota (los precios ya incluyen IVA). */
    private function importes(array $f, int $tipo, float $total): array
    {
        if ($tipo === 11) {
            return [$total, 0.0, null];
        }
        $alicuota = (float) ($f['alicuota_iva'] ?? 21);
        $neto = round($total / (1 + $alicuota / 100), 2);
        return [$neto, round($total - $neto, 2), $alicuota];
    }

    /** @return array{condicion: string, doc_tipo: int, doc_nro: string, nombre: ?string} */
    private function receptor(array $r, float $total): array
    {
        $condicion = (string) ($r['condicion'] ?? 'consumidor_final');
        if (!isset(self::CONDICION_RECEPTOR[$condicion])) {
            $condicion = 'consumidor_final';
        }
        $numero = preg_replace('/\D/', '', (string) ($r['doc_nro'] ?? ''));
        $docTipo = 99;
        if ($numero !== '') {
            if (strlen($numero) === 11) {
                if (!self::cuitValido($numero)) {
                    throw new ArcaRechazo('El CUIT del cliente no es válido.');
                }
                $docTipo = 80;
            } elseif (strlen($numero) >= 7 && strlen($numero) <= 8) {
                $docTipo = 96;
            } else {
                throw new ArcaRechazo('El documento del cliente tiene que ser un DNI (7 u 8 números) o un CUIT (11).');
            }
        }
        if ($condicion !== 'consumidor_final' && $docTipo !== 80) {
            throw new ArcaRechazo('Para un cliente responsable inscripto, monotributista o exento hace falta su CUIT.');
        }
        if ($docTipo === 99 && $total >= $this->topeConsumidorFinal()) {
            throw new ArcaRechazo('Para ventas de $' . number_format($this->topeConsumidorFinal(), 0, ',', '.') . ' o más, ARCA pide el DNI o CUIT del cliente.');
        }
        $nombre = trim((string) ($r['nombre'] ?? ''));
        return ['condicion' => $condicion, 'doc_tipo' => $docTipo, 'doc_nro' => $numero ?: '0', 'nombre' => $nombre === '' ? null : mb_substr($nombre, 0, 150)];
    }

    /** Desde este monto hay que identificar al consumidor final (RG 5700/2025: $10.000.000). */
    private function topeConsumidorFinal(): float
    {
        return (float) Entorno::int('ARCA_TOPE_CONSUMIDOR_FINAL', 10000000);
    }

    public static function cuitValido(string $cuit): bool
    {
        if (!preg_match('/^\d{11}$/', $cuit)) {
            return false;
        }
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach ($pesos as $i => $p) {
            $suma += (int) $cuit[$i] * $p;
        }
        $dv = 11 - ($suma % 11);
        $dv = $dv === 11 ? 0 : ($dv === 10 ? 9 : $dv);
        return $dv === (int) $cuit[10];
    }

    /** Comprobante listo para mostrar e imprimir (con el link del QR de ARCA). */
    private function resumen(array $e, array $f, bool $conEmisor = true): array
    {
        $tipo = (int) $e['tipo_cbte'];
        [$letra, $clase] = self::NOMBRES_CBTE[$tipo] ?? ['', 'Comprobante'];
        $numero = $e['numero'] !== null ? (int) $e['numero'] : null;
        $r = [
            'id' => (int) $e['id'],
            'id_sale' => (int) $e['id_sale'],
            'estado' => $e['estado'],
            'tipo_cbte' => $tipo,
            'letra' => $letra,
            'nombre' => $clase . ' ' . $letra,
            'es_nota_credito' => !isset(self::NOTA_DE[$tipo]),
            'punto_venta' => (int) $e['punto_venta'],
            'numero' => $numero,
            'numero_completo' => $numero !== null ? sprintf('%05d-%08d', (int) $e['punto_venta'], $numero) : null,
            'fecha' => $e['fecha'],
            'cae' => $e['cae'],
            'cae_vence' => $e['cae_vence'],
            'error' => $e['error'],
            'total' => (float) $e['importe_total'],
            'neto' => isset($e['importe_neto']) ? (float) $e['importe_neto'] : null,
            'iva' => isset($e['importe_iva']) ? (float) $e['importe_iva'] : null,
            'alicuota_iva' => isset($e['alicuota_iva']) && $e['alicuota_iva'] !== null ? (float) $e['alicuota_iva'] : null,
            'doc_tipo' => (int) $e['doc_tipo'],
            'doc_nro' => $e['doc_nro'],
            'receptor_nombre' => $e['receptor_nombre'],
            'entorno' => $e['entorno'],
            'qr_url' => null,
        ];
        if ($e['estado'] === 'emitida' && $e['cae'] && $numero !== null) {
            $qr = [
                'ver' => 1, 'fecha' => $e['fecha'], 'cuit' => (int) ($f['cuit'] ?? 0), 'ptoVta' => (int) $e['punto_venta'],
                'tipoCmp' => $tipo, 'nroCmp' => $numero, 'importe' => (float) $e['importe_total'], 'moneda' => 'PES', 'ctz' => 1,
                'tipoDocRec' => (int) $e['doc_tipo'], 'nroDocRec' => (int) $e['doc_nro'], 'tipoCodAut' => 'E', 'codAut' => (int) $e['cae'],
            ];
            $r['qr_url'] = 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode(json_encode($qr));
        }
        if ($conEmisor) {
            $r['emisor'] = [
                'razon_social' => $f['razon_social'] ?? null,
                'cuit' => $f['cuit'] ?? null,
                'condicion_iva' => $f['condicion_iva'] ?? null,
                'domicilio' => $f['domicilio'] ?? null,
                'ingresos_brutos' => $f['ingresos_brutos'] ?? null,
                'inicio_actividades' => $f['inicio_actividades'] ?? null,
            ];
        }
        return $r;
    }

    private function hoy(): string
    {
        return (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
    }

    private function ahora(): string
    {
        return (new \DateTime('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');
    }
}
