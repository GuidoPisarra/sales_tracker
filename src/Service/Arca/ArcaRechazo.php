<?php

namespace App\Service\Arca;

/**
 * ARCA respondió con un error de los datos (CUIT inválido, punto de venta
 * inexistente, certificado vencido, etc.). Reintentar igual no sirve: hay que corregir algo.
 */
class ArcaRechazo extends \RuntimeException
{
}
