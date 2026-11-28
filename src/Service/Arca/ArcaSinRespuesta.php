<?php

namespace App\Service\Arca;

/**
 * ARCA no respondió (sin conexión, demora o servicio caído). La factura queda
 * pendiente y se reintenta sola; no es un error de los datos.
 */
class ArcaSinRespuesta extends \RuntimeException
{
}
