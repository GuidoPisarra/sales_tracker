-- Fotos de productos para el catálogo online (pedido de Guido, 2026-11-18).
-- Los archivos (WebP) viven en el almacén de fotos (carpeta del VPS o Cloudflare R2,
-- ver FOTOS_* en docs/SAAS.md); acá solo se guarda su nombre, nunca la URL completa.

CREATE TABLE IF NOT EXISTS producto_foto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_negocio INT NOT NULL,
    id_producto INT NOT NULL,
    -- Clave del archivo en el almacén, ej. n/42/9f3c1a7b0d2e4f60.webp (lado mayor 1200 px).
    archivo VARCHAR(120) NOT NULL,
    -- Miniatura cuadrada de 400 px, ej. n/42/9f3c1a7b0d2e4f60-m.webp.
    miniatura VARCHAR(120) NOT NULL,
    ancho SMALLINT NOT NULL,
    alto SMALLINT NOT NULL,
    -- Bytes de los dos archivos juntos (para ver el disco usado por negocio).
    bytes INT NOT NULL DEFAULT 0,
    -- 0 = portada.
    orden TINYINT NOT NULL DEFAULT 0,
    creada_en DATETIME NOT NULL,
    KEY idx_producto_foto_producto (id_negocio, id_producto, orden),
    KEY idx_producto_foto_archivo (archivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fotos por producto según el plan. NULL = el valor por defecto (5).
ALTER TABLE plan_suscripcion ADD COLUMN max_fotos_producto INT NULL;
UPDATE plan_suscripcion SET max_fotos_producto = 1 WHERE codigo = 'starter';
UPDATE plan_suscripcion SET max_fotos_producto = 3 WHERE codigo = 'pro';
UPDATE plan_suscripcion SET max_fotos_producto = 5 WHERE codigo = 'premium';
UPDATE plan_suscripcion SET max_fotos_producto = 5 WHERE codigo = 'fundador';
