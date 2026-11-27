-- Preferencias de cada usuario que tienen que seguirlo si cambia de teléfono
-- (por ahora: los accesos rápidos del Inicio, pedido de Guido 2026-11-26).
CREATE TABLE IF NOT EXISTS usuario_preferencia (
    id_usuario INT NOT NULL,
    clave VARCHAR(40) NOT NULL,
    valor TEXT NOT NULL,
    modificado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_usuario, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
