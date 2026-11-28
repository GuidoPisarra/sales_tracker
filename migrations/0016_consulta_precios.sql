-- "Consultá el precio" (pedido de Guido, 2026-11-28): el local pega un cartel con un QR;
-- el cliente lo abre con su celular y escanea las etiquetas para ver el precio.
-- El link es el mismo del catálogo online (negocio_config.catalogo_slug).
ALTER TABLE negocio_config ADD COLUMN precios_activo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE negocio_config ADD COLUMN precios_sucursal INT NULL;
