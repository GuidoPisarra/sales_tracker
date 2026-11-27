<?php

namespace App\Service;

use App\Repository\DemoRepository;

/**
 * "Probar sin registrarme": un negocio de ejemplo compartido (una tienda de
 * ropa con un mes de ventas) al que se entra sin cuenta. Todas las noches
 * app:demo:reiniciar lo deja como nuevo. DemoListener limita lo que se puede
 * cambiar (nada de cuenta, plan, empleados, IA ni fotos).
 */
class DemoService
{
    public const EMAIL_POR_DEFECTO = 'demo@kontia.app';

    /** [descripción, talle, costo, precio, stock final, ventas por día (peso), proveedor] */
    private const PRODUCTOS = [
        ['Remera básica algodón blanca', 'M', 4500, 9900, 18, 5, 0],
        ['Remera básica algodón negra', 'M', 4500, 9900, 4, 5, 0],
        ['Remera básica algodón gris', 'L', 4500, 9900, 12, 3, 0],
        ['Remera oversize estampada', 'L', 6200, 13500, 9, 3, 0],
        ['Musculosa deportiva', 'S', 3800, 8200, 14, 2, 2],
        ['Camisa lino manga larga', 'M', 11000, 24900, 6, 1, 1],
        ['Camisa jean', 'L', 12500, 27500, 5, 1, 1],
        ['Chomba piqué azul', 'M', 7900, 16900, 8, 2, 0],
        ['Buzo canguro frisa gris', 'L', 13500, 29900, 7, 2, 0],
        ['Buzo canguro frisa negro', 'M', 13500, 29900, 0, 2, 0],
        ['Buzo cuello redondo', 'S', 11000, 24500, 10, 1, 0],
        ['Campera rompevientos', 'L', 21000, 45900, 4, 1, 2],
        ['Campera de jean', 'M', 26000, 54900, 3, 1, 1],
        ['Campera inflable', 'L', 34000, 72900, 2, 1, 2],
        ['Jean recto azul clásico', '42', 16500, 35900, 11, 3, 1],
        ['Jean mom celeste', '38', 17200, 37500, 9, 2, 1],
        ['Jean skinny negro', '40', 16500, 35900, 1, 2, 1],
        ['Pantalón cargo verde', '42', 15800, 33900, 6, 1, 1],
        ['Jogger frisa', 'M', 10500, 22900, 13, 2, 0],
        ['Short de baño', 'M', 7200, 15900, 10, 1, 2],
        ['Bermuda gabardina', '40', 9800, 21500, 7, 1, 1],
        ['Calza deportiva larga', 'S', 6900, 14900, 16, 2, 2],
        ['Vestido corto floreado', 'S', 14500, 31900, 5, 1, 0],
        ['Pollera de jean', '38', 10900, 23900, 6, 1, 1],
        ['Zapatillas urbanas blancas', '41', 32000, 69900, 6, 1, 2],
        ['Zapatillas running', '42', 38000, 82500, 3, 1, 2],
        ['Ojotas', '40', 3500, 7900, 20, 1, 2],
        ['Medias pack x3', 'U', 2600, 5900, 30, 4, 0],
        ['Medias soquete pack x3', 'U', 2300, 5200, 24, 2, 0],
        ['Boxer algodón', 'M', 2900, 6500, 22, 2, 0],
        ['Gorra trucker', 'U', 5200, 11900, 9, 1, 2],
        ['Gorro de lana', 'U', 3900, 8900, 12, 1, 0],
        ['Cinturón de cuero', 'U', 7500, 16500, 8, 1, 1],
        ['Mochila urbana', 'U', 18500, 39900, 4, 1, 2],
        ['Riñonera', 'U', 8200, 17900, 7, 1, 2],
        ['Bufanda tejida', 'U', 4800, 10900, 0, 1, 0],
    ];

    private const PROVEEDORES = [
        ['Textil del Sur', '11 4567-1200', 'Avellaneda'],
        ['Denim Factory', '11 3322-8800', 'CABA'],
        ['Sport Mayorista', '341 455-9010', 'Rosario'],
    ];

    private const PAGOS = ['efectivo', 'efectivo', 'efectivo', 'tarjeta', 'tarjeta', 'transferencia', 'mercadopago'];

    private const GASTOS = [
        [27, 280000, 'Alquiler del local'],
        [20, 38500, 'Luz'],
        [14, 12900, 'Bolsas y etiquetas'],
        [8, 22000, 'Publicidad en redes'],
        [2, 9500, 'Limpieza'],
    ];

    public function __construct(private DemoRepository $repo)
    {
    }

    public static function email(): string
    {
        return strtolower(Entorno::get('DEMO_EMAIL', self::EMAIL_POR_DEFECTO));
    }

    public static function esDemo(?string $email): bool
    {
        return $email !== null && strtolower($email) === self::email();
    }

    /** Usuario de ejemplo; si todavía no existe, lo crea con sus datos. */
    public function asegurar(): array
    {
        return $this->repo->usuario(self::email()) ?? $this->reiniciar();
    }

    /** Deja el negocio de ejemplo como nuevo (lo crea si no existe). Devuelve el usuario. */
    public function reiniciar(?\DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new \DateTimeImmutable();
        $usuario = $this->repo->usuario(self::email());
        if ($usuario === null) {
            [$idNegocio, $idUsuario] = $this->repo->crearCuenta(self::email(), 'Tienda de ejemplo');
            $usuario = ['id' => $idUsuario, 'id_negocio' => $idNegocio];
        }
        $idNegocio = (int) $usuario['id_negocio'];
        $idUsuario = (int) $usuario['id'];
        $this->repo->vaciar($idNegocio, $idUsuario);
        $this->repo->transaccion(fn () => $this->cargar($idNegocio, $idUsuario, $ahora));
        return $usuario;
    }

    private function cargar(int $n, int $usuario, \DateTimeImmutable $ahora): void
    {
        $proveedores = [];
        foreach (self::PROVEEDORES as [$nombre, $tel, $ciudad]) {
            $proveedores[] = $this->repo->insertar(
                'INSERT INTO proveedores (nombre, telefono, ciudad, id_negocio) VALUES (?, ?, ?, ?)',
                [$nombre, $tel, $ciudad, $n]
            );
        }

        $productos = [];
        foreach (self::PRODUCTOS as $i => [$desc, $talle, $costo, $precio, $stock, $peso, $prov]) {
            $codigo = '7790000' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT) . '0';
            $id = $this->repo->insertar(
                'INSERT INTO product (description, size, cost_price, sale_price, quantity, id_proveedor, code, barcode, activo, id_negocio, sucursal, fecha_actualizado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1, ?)',
                [$desc, $talle, $costo, $precio, $stock, $proveedores[$prov], (string) ($i + 101), $codigo, $n, $ahora->modify('-' . (10 + $i) . ' days')->format('Y-m-d H:i:s')]
            );
            for ($k = 0; $k < $peso; $k++) {
                $productos[] = [$id, $precio];
            }
        }

        // Ventas de los últimos 30 días y de hoy hasta la hora actual. Siempre las
        // mismas (semilla fija) para que la tienda se vea igual todos los días.
        mt_srand(20261127);
        $hoy = $ahora->setTime(0, 0);
        $horaActual = (int) $ahora->format('G');
        for ($d = 30; $d >= 0; $d--) {
            $dia = $hoy->modify("-$d days");
            $finde = (int) $dia->format('N') >= 6;
            $ventas = mt_rand(5, 9) + ($finde ? 4 : 0);
            for ($v = 0; $v < $ventas; $v++) {
                $hora = mt_rand(10, 20);
                if ($d === 0 && $hora >= max(10, $horaActual)) {
                    continue;
                }
                $fecha = $dia->setTime($hora, mt_rand(0, 59), mt_rand(0, 59))->format('Y-m-d H:i:s');
                $pago = self::PAGOS[mt_rand(0, count(self::PAGOS) - 1)];
                $idVenta = null;
                for ($k = 0, $items = mt_rand(1, 3); $k < $items; $k++) {
                    [$idProducto, $precio] = $productos[mt_rand(0, count($productos) - 1)];
                    $idSale = $this->repo->insertar('INSERT INTO sales (id_product, id_negocio) VALUES (?, ?)', [$idProducto, $n]);
                    $idVenta ??= $idSale;
                    $this->repo->insertar(
                        'INSERT INTO sales_product (id_sale, id_product, quantity, price, sale_product_date, type_payment, active, register, id_negocio, sucursal, usuario)
                         VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, 1, ?)',
                        [$idVenta, $idProducto, mt_rand(1, 10) === 1 ? 2 : 1, $precio, $fecha, $pago, $n, $usuario]
                    );
                }
            }
        }

        foreach (self::GASTOS as [$hace, $monto, $desc]) {
            $this->repo->insertar(
                'INSERT INTO expense (price, description, id_sucursal, date_expense, id_negocio) VALUES (?, ?, 1, ?, ?)',
                [$monto, $desc, $hoy->modify("-$hace days")->format('Y-m-d'), $n]
            );
        }

        foreach ([['30111222', 'Gómez', 'Laura', '11 5555-1234'], ['28999888', 'Pérez', 'Martín', '11 5555-9876']] as [$dni, $ape, $nom, $tel]) {
            $this->repo->insertar(
                'INSERT INTO clientes (id_negocio, dni, apellido, nombre, telefono) VALUES (?, ?, ?, ?, ?)',
                [$n, $dni, $ape, $nom, $tel]
            );
        }
    }
}
