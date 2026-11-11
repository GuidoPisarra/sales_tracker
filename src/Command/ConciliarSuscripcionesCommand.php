<?php

namespace App\Command;

use App\Service\CobrosService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tarea diaria (cron): revisa contra Mercado Pago el estado de cada débito
 * automático por si se perdió algún webhook, y avisa por mail a los negocios
 * cuya prueba gratis vence en 3 días.
 */
class ConciliarSuscripcionesCommand extends Command
{
    protected static $defaultName = 'app:suscripciones:conciliar';
    protected static $defaultDescription = 'Concilia suscripciones con Mercado Pago y avisa pruebas por vencer';

    private CobrosService $cobros;

    public function __construct(CobrosService $cobros)
    {
        parent::__construct();
        $this->cobros = $cobros;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Suscripciones revisadas: ' . $this->cobros->conciliar());
        $output->writeln('Avisos de fin de prueba: ' . $this->cobros->avisarPruebasPorVencer(3));
        return Command::SUCCESS;
    }
}
