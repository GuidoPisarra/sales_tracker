-- Precios mensuales de los planes (pedido de Guido, 2026-11-10).
UPDATE plan_suscripcion SET precio_mensual = 30000 WHERE codigo = 'starter';
UPDATE plan_suscripcion SET precio_mensual = 40000 WHERE codigo = 'pro';
UPDATE plan_suscripcion SET precio_mensual = 50000 WHERE codigo = 'premium';
