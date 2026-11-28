<?php

namespace App\Service;

use App\DTO\SalesProduct\DeleteSalesProductDTO;
use App\DTO\SalesProduct\RegisterSalesProductDTO;
use App\Repository\SalesProductRepository;

class SalesProductService
{
    /** Formas de pago que se pueden asignar al editar una venta ("Cta Cte" queda afuera). */
    public const FORMAS_PAGO_EDITABLES = ['efectivo', 'tarjeta', 'dni', 'mercadopago'];

    protected $rep_salesProduct;
    private ?FacturacionService $facturacion;

    public function __construct(SalesProductRepository $rep_salesProduct, ?FacturacionService $facturacion = null)
    {
        $this->rep_salesProduct = $rep_salesProduct;
        $this->facturacion = $facturacion;
    }

    /**
     * Valida el detalle de un pago mixto contra el total de la venta.
     * Devuelve los pagos limpios (mismas formas juntas, montos con 2 decimales) o el
     * mensaje de error para mostrar.
     *
     * @return array<int, array{forma: string, monto: float}>|string
     */
    public static function normalizarPagos(array $pagos, float $total)
    {
        $porForma = [];
        foreach ($pagos as $p) {
            $forma = is_array($p) ? strtolower(trim((string) ($p['forma'] ?? ''))) : '';
            $monto = is_array($p) && is_numeric($p['monto'] ?? null) ? round((float) $p['monto'], 2) : 0.0;
            if ($monto == 0.0) {
                continue;
            }
            if (!in_array($forma, self::FORMAS_PAGO_EDITABLES, true)) {
                return 'En un pago mixto solo se puede usar efectivo, tarjeta, Cuenta DNI o Mercado Pago.';
            }
            if ($monto < 0) {
                return 'Los montos del pago mixto no pueden ser negativos.';
            }
            $porForma[$forma] = round(($porForma[$forma] ?? 0) + $monto, 2);
        }
        if (count($porForma) < 2) {
            return 'Un pago mixto necesita al menos dos medios de pago con monto.';
        }
        $suma = array_sum($porForma);
        // Hasta $1 de diferencia por redondeos de descuentos y recargos.
        if (abs($suma - $total) > 1.0) {
            return sprintf('Los pagos suman $%s y la venta es de $%s. Tienen que dar lo mismo.', number_format($suma, 2, ',', '.'), number_format($total, 2, ',', '.'));
        }
        $resultado = [];
        foreach ($porForma as $forma => $monto) {
            $resultado[] = ['forma' => $forma, 'monto' => $monto];
        }
        return $resultado;
    }

    /** Número (id_sale) de la última venta guardada. */
    public function ultimaVenta(): ?int
    {
        return $this->rep_salesProduct->ultimaVenta;
    }

    /**
     * Si el negocio factura todas las ventas automáticamente, emite la factura de esta
     * venta (consumidor final). Nunca hace fallar la venta: si ARCA no responde, la
     * factura queda pendiente y se reintenta sola.
     */
    public function facturaAutomatica(int $idNegocio, ?int $idVenta): ?array
    {
        if ($this->facturacion === null || !$idVenta) {
            return null;
        }
        try {
            return $this->facturacion->facturarSiEsAutomatico($idNegocio, $idVenta);
        } catch (\Throwable $e) {
            error_log('factura automática: ' . $e->getMessage());
            return null;
        }
    }

    public function obtenerIdNegocioVenta(int $idSaleProduct): ?int
    {
        return $this->rep_salesProduct->obtenerIdNegocioVenta($idSaleProduct);
    }

    /**
     * @return bool|string true si se guardó, "duplicada" si esa clave ya se había registrado.
     */
    public function save_salesProduct(array $datosDto, ?string $clave = null, array $forzarStock = [], bool $sinConexion = false, array $pagos = [])
    {
        $resultado = $this->rep_salesProduct->save_salesProduct($datosDto, $clave, $forzarStock, $sinConexion, $pagos);
        if ($resultado === true && $this->rep_salesProduct->ultimosFaltantes && $datosDto) {
            $this->rep_salesProduct->avisarFaltantes(
                (int) $datosDto[0]->to_array()['id_negocio'],
                $this->rep_salesProduct->ultimosFaltantes,
                $sinConexion
            );
        }
        return $resultado;
    }

    /** Productos que se vendieron sin stock en la última venta guardada. */
    public function ultimosFaltantes(): array
    {
        return $this->rep_salesProduct->ultimosFaltantes;
    }

    public function faltantes(int $idNegocio, bool $incluirRevisados = false): array
    {
        return $this->rep_salesProduct->faltantes($idNegocio, $incluirRevisados);
    }

    public function contarFaltantes(int $idNegocio): int
    {
        return $this->rep_salesProduct->contarFaltantes($idNegocio);
    }

    public function marcarFaltantesRevisados(int $idNegocio, array $ids): int
    {
        return $this->rep_salesProduct->marcarFaltantesRevisados($idNegocio, $ids);
    }

    public function delete_salesProduct(DeleteSalesProductDTO $dto)
    {
        return $this->rep_salesProduct->delete_salesProduct($dto);
    }

    public function register_salesProduct(RegisterSalesProductDTO $dto)
    {
        return $this->rep_salesProduct->register_salesProduct($dto);
    }

    public function cambiarFormaPago(int $idSale, string $typePayment, int $idNegocio): bool
    {
        return $this->rep_salesProduct->cambiarFormaPago($idSale, $typePayment, $idNegocio);
    }
}
