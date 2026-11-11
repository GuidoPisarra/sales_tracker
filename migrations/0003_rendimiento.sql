-- Rendimiento con miles de productos por negocio.
-- Las columnas e índices que ya existan se saltean (ver app:migraciones).

-- Marca de última modificación de cada producto. La usa el catálogo liviano
-- del front para pedir solo lo que cambió (GET /api/products/catalogo?desde=).
-- ON UPDATE hace que cualquier UPDATE (venta, stock, precio, traslado) la mueva
-- sin tener que tocar cada consulta.
ALTER TABLE product ADD COLUMN modificado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Búsqueda paginada por negocio (orden alfabético) y por códigos.
CREATE INDEX idx_product_negocio_activo_desc ON product (id_negocio, activo, description(100));
CREATE INDEX idx_product_negocio_modificado ON product (id_negocio, modificado);
CREATE INDEX idx_product_negocio_barcode ON product (id_negocio, barcode);
CREATE INDEX idx_product_negocio_codigo_interno ON product (id_negocio, codigo_interno);

-- Reporte de ventas por rango de fechas y resumen del día.
CREATE INDEX idx_sales_product_negocio_activa_fecha ON sales_product (id_negocio, active, sale_product_date);
CREATE INDEX idx_cambios_negocio_fecha ON cambios (id_negocio, fecha_cambio);

-- Ventas hechas sin conexión: el front manda una clave única por venta y, si
-- el reintento llega dos veces, la segunda se ignora en vez de duplicarse.
CREATE TABLE IF NOT EXISTS venta_clave (
    id_negocio INT NOT NULL,
    clave VARCHAR(64) NOT NULL,
    creada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_negocio, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
