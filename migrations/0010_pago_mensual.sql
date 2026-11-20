-- Pago mes a mes (pedido de Guido, 2026-11-20): además del débito automático, el
-- titular puede pagar cada mes con un pago único de Mercado Pago (Checkout Pro:
-- dinero en cuenta, tarjeta, etc.). Cada pago aprobado suma un mes a periodo_hasta.

-- 'debito' (preapproval de Mercado Pago) o 'mensual' (pagos sueltos). NULL = sin pagos
-- todavía, o datos de antes de esta migración (se tratan como débito).
ALTER TABLE suscripcion ADD COLUMN modo_pago VARCHAR(10) NULL;

-- Avisos del pago mensual ya enviados para el período actual:
-- 0 = ninguno, 1 = "vence en 3 días", 2 = "venció".
ALTER TABLE suscripcion ADD COLUMN aviso_vencimiento TINYINT NOT NULL DEFAULT 0;
