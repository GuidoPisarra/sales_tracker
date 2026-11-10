<?php

namespace App\Service;

use App\DTO\Products\AddProductDTO;
use App\DTO\Products\AddStockDTO;
use App\DTO\Products\DeleteProductDTO;
use App\DTO\Products\OneProductDTO;
use App\Repository\ProductsRepository;

class ProductsService
{
    protected $rep_products;

    public function __construct(ProductsRepository $rep_products)
    {
        $this->rep_products = $rep_products;
    }

    public function list_products(int $id_local)
    {
        return $this->rep_products->list_products($id_local);
    }

    public function obtenerIdNegocio(int $id_producto): ?int
    {
        return $this->rep_products->obtenerIdNegocio($id_producto);
    }

    public function add_product(AddProductDTO $dto)
    {
        return $this->rep_products->add_product($dto, $this->get_fecha_hora_actual());
    }

    public function del_product(DeleteProductDTO $dto)
    {
        return $this->rep_products->del_product($dto);
    }

    public function one_product(OneProductDTO $dto)
    {
        $dto->setCode(str_replace(';', '-', $dto->getCode()));
        return $this->rep_products->one_product($dto);
    }

    public function products_price_percentage(float $percentage, int $id_negocio, int $proveedor)
    {
        $this->rep_products->actualizar_cta_cte($percentage, $id_negocio, $proveedor);
        return $this->rep_products->products_price_percentage($percentage, $id_negocio, $proveedor, $this->get_fecha_hora_actual());
    }

    public function add_stock_product(AddStockDTO $dto)
    {
        return $this->rep_products->add_products_stcok($dto);
    }

    public function add_stock_product_edit(AddStockDTO $dto)
    {
        return $this->rep_products->add_products_stock_edit($dto, $this->get_fecha_hora_actual());
    }

    public function trasladar_product(array $datos_dto)
    {
        return $this->rep_products->realizarTraslado($datos_dto);
    }

    public function buscar(int $idNegocio, string $texto, ?int $proveedor, ?string $filtro, ?int $sucursal, int $pagina, int $limite): array
    {
        $limite = max(1, min(200, $limite));
        $pagina = max(1, $pagina);
        $resultado = $this->rep_products->buscar($idNegocio, $texto, $proveedor, $filtro, $sucursal, $limite, ($pagina - 1) * $limite);
        $resultado['pagina'] = $pagina;
        $resultado['limite'] = $limite;
        $resultado['hayMas'] = $pagina * $limite < $resultado['total'];
        return $resultado;
    }

    public function porIds(int $idNegocio, array $ids): array
    {
        return $this->rep_products->porIds($idNegocio, array_slice($ids, 0, 500));
    }

    public function catalogo(int $idNegocio, ?string $desde): array
    {
        return $this->rep_products->catalogo($idNegocio, $desde);
    }

    public function actualizarRapido(int $idNegocio, int $id, ?float $precio, ?int $stock): bool
    {
        return $this->rep_products->actualizarRapido($idNegocio, $id, $precio, $stock, $this->get_fecha_hora_actual());
    }

    /** @param array<int, float> $precios id => precio */
    public function actualizarPrecios(int $idNegocio, array $precios): int
    {
        return $this->rep_products->actualizarPrecios($idNegocio, array_slice($precios, 0, 1000, true), $this->get_fecha_hora_actual());
    }

    public function editarMasivo(int $idNegocio, array $ids, array $cambios): int
    {
        return $this->rep_products->editarMasivo($idNegocio, array_slice($ids, 0, 5000), $cambios, $this->get_fecha_hora_actual());
    }

    public function contarNuevosImportacion(int $idNegocio, int $sucursal, array $filas): int
    {
        return $this->rep_products->contarNuevosImportacion($idNegocio, $sucursal, array_column($filas, 'code'));
    }

    public function importar(int $idNegocio, int $sucursal, array $filas): array
    {
        return $this->rep_products->importar($idNegocio, $sucursal, $filas, $this->get_fecha_hora_actual());
    }

    /**
     * Valida y normaliza las filas de una planilla. Devuelve [filas, errores]; los
     * errores dicen la fila (contando el encabezado como fila 1, como en Excel).
     */
    public static function normalizarFilasImportacion(array $crudas): array
    {
        $filas = [];
        $errores = [];
        $codigos = [];
        foreach (array_values($crudas) as $i => $c) {
            $n = $i + 2;
            $code = trim((string) ($c['code'] ?? ''));
            $description = trim((string) ($c['description'] ?? ''));
            $sale = self::numero($c['salePrice'] ?? null);
            $cost = self::numero($c['costPrice'] ?? null);
            if ($code === '') {
                $errores[] = "Fila $n: falta el código.";
                continue;
            }
            if (isset($codigos[$code])) {
                $errores[] = "Fila $n: el código \"$code\" está repetido (también en la fila {$codigos[$code]}).";
                continue;
            }
            if ($description === '') {
                $errores[] = "Fila $n: falta la descripción.";
                continue;
            }
            if ($sale === null || $sale < 0) {
                $errores[] = "Fila $n: el precio de venta no es un número válido.";
                continue;
            }
            $codigos[$code] = $n;
            $cantidad = self::numero($c['quantity'] ?? null);
            $proveedor = self::numero($c['idProveedor'] ?? null);
            $barcode = trim((string) ($c['barcode'] ?? ''));
            $size = trim((string) ($c['size'] ?? ''));
            $filas[] = [
                'code' => mb_substr($code, 0, 100),
                'description' => mb_substr($description, 0, 255),
                'costPrice' => $cost !== null && $cost >= 0 ? $cost : 0,
                'salePrice' => $sale,
                'quantity' => $cantidad !== null ? (int) $cantidad : null,
                'idProveedor' => $proveedor !== null && $proveedor > 0 ? (int) $proveedor : null,
                'barcode' => $barcode !== '' ? mb_substr($barcode, 0, 100) : null,
                'size' => $size !== '' ? mb_substr($size, 0, 50) : null,
            ];
        }
        return [$filas, $errores];
    }

    /** Acepta "1234.5", "1234,5" y "1.234,50". */
    private static function numero($valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        $t = str_replace(['$', ' '], '', trim((string) $valor));
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $t)) {
            $t = str_replace(['.', ','], ['', '.'], $t);
        } else {
            $t = str_replace(',', '.', $t);
        }
        return is_numeric($t) ? (float) $t : null;
    }

    private function get_fecha_hora_actual(): string
    {
        $timezone = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $dateTime = new \DateTime('now', $timezone);
        $fechaHoraArgentina = $dateTime->format('Y-m-d H:i:s');
        return $fechaHoraArgentina;
    }

}
