<?php

namespace App\Command;

use App\Service\Fotos\FotosService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tarea diaria (cron): borra las fotos de productos que ya no existen.
 * Con --negocio=N borra todas las fotos de ese negocio (baja definitiva).
 */
class LimpiarFotosCommand extends Command
{
    protected static $defaultName = 'app:fotos:limpiar';
    protected static $defaultDescription = 'Borra fotos de productos que ya no existen (o todas las de un negocio con --negocio)';

    public function __construct(private FotosService $fotos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('negocio', null, InputOption::VALUE_REQUIRED, 'Borrar todas las fotos de este id_negocio');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $negocio = $input->getOption('negocio');
        $output->writeln('Fotos borradas: ' . $this->fotos->limpiar($negocio !== null ? (int) $negocio : null));
        return Command::SUCCESS;
    }
}
