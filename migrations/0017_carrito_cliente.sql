-- Carrito de la clienta (pedido de Guido, 2026-11-28): desde "Consultá el precio" arma
-- un carrito en su celular y al llegar a la caja se carga con un código corto o un QR.
-- No reserva stock ni cobra nada: la venta se hace en Caja como siempre.
CREATE TABLE carrito_cliente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    sucursal INT NOT NULL,
    codigo CHAR(4) NOT NULL,
    token CHAR(24) NOT NULL,
    items TEXT NOT NULL,
    creado_en DATETIME NOT NULL,
    tomado_en DATETIME NULL,
    UNIQUE KEY uq_carrito_token (token),
    KEY idx_carrito_negocio_codigo (id_negocio, codigo, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
