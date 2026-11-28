<?php

namespace App\Command;

use App\Service\FacturacionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reintenta las facturas que quedaron pendientes porque ARCA no respondió.
 * Va en el cron cada 10 minutos (ver scripts/crontab.ejemplo).
 */
class ReintentarFacturasCommand extends Command
{
    protected static $defaultName = 'app:facturas:reintentar';
    protected static $defaultDescription = 'Reintenta las facturas electrónicas pendientes';

    public function __construct(private FacturacionService $facturacion)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->facturacion->reintentarPendientes();
        $output->writeln(sprintf('Facturas: %d emitidas, %d siguen pendientes, %d rechazadas.', $r['emitidas'], $r['pendientes'], $r['rechazadas']));
        return Command::SUCCESS;
    }
}
