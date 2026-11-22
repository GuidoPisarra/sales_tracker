<?php

namespace App\Command;

use App\Service\Entorno;
use App\Service\MailService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manda por mail el backup de la base (lo llama scripts/backup-db.sh) para
 * tener una copia fuera del VPS. Destino: BACKUP_EMAIL, o ADMIN_EMAILS si está
 * vacío. El archivo llega por stdin, así el script (root) no tiene que darle
 * permisos a www-data sobre /var/backups:
 *
 *   cat backup.sql.gz.gpg | php bin/console app:backup:enviar backup.sql.gz.gpg
 *
 * Gmail no acepta adjuntos de más de 25 MB: arriba de BACKUP_EMAIL_MAX_MB
 * (20 por defecto) manda solo un aviso para que se pase a otra copia (R2/B2).
 */
class EnviarBackupCommand extends Command
{
    protected static $defaultName = 'app:backup:enviar';
    protected static $defaultDescription = 'Manda por mail el backup de la base (leído de stdin)';

    public function __construct(private MailService $mail)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('nombre', InputArgument::REQUIRED, 'Nombre del archivo adjunto')
            ->addOption('para', null, InputOption::VALUE_REQUIRED, 'Emails separados por coma (si no, BACKUP_EMAIL o ADMIN_EMAILS)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $para = $input->getOption('para');
        $destinos = $para !== null
            ? array_values(array_filter(array_map('trim', explode(',', $para))))
            : (Entorno::lista('BACKUP_EMAIL') ?: Entorno::lista('ADMIN_EMAILS'));
        if (!$destinos) {
            $output->writeln('<error>No hay a quién mandarlo: cargá BACKUP_EMAIL (o ADMIN_EMAILS) en el .env.</error>');
            return Command::FAILURE;
        }

        $contenido = stream_get_contents(STDIN);
        if ($contenido === false || $contenido === '') {
            $output->writeln('<error>No llegó ningún archivo por stdin.</error>');
            return Command::FAILURE;
        }

        $nombre = basename((string) $input->getArgument('nombre'));
        $mb = strlen($contenido) / 1048576;
        $maxMb = Entorno::int('BACKUP_EMAIL_MAX_MB', 20);
        $tamano = $mb < 1 ? round(strlen($contenido) / 1024) . ' KB' : round($mb, 1) . ' MB';
        $cifrado = str_ends_with($nombre, '.gpg');

        if (!$cifrado) {
            // Un backup sin cifrar en un buzón de mail expone los datos de todos los clientes.
            $output->writeln('<error>No mando backups sin cifrar: cargá BACKUP_GPG_PASS en scripts/backup.conf.</error>');
            return Command::FAILURE;
        }

        if ($mb > $maxMb) {
            $ok = $this->mail->enviarAdmin($destinos, 'Backup demasiado grande para mandarlo por mail', [
                'Archivo' => $nombre,
                'Tamaño' => $tamano,
                'Qué hacer' => 'Quedó guardado en el VPS. Pasá a una copia en R2 o B2 (RCLONE_DESTINO).',
            ]);
        } else {
            $texto = 'Backup diario de la base de Kontia: ' . $nombre . ' (' . $tamano . ').' . "\n"
                . 'Está cifrado con BACKUP_GPG_PASS. Para restaurarlo en el servidor: '
                . 'gpg -d ' . $nombre . ' | gunzip | mysql --defaults-extra-file=/root/.my.cnf NOMBRE_BASE (pisa la base).'
                . "\n" . 'Guardá este mail: es tu copia fuera del servidor.';
            $ok = $this->mail->enviarConAdjunto($destinos, 'Backup ' . date('Y-m-d'), $texto, $contenido, $nombre);
        }

        if (!$ok) {
            $output->writeln('<error>No se pudo mandar el mail (mirá el error_log de PHP y MAILER_DSN).</error>');
            return Command::FAILURE;
        }
        $output->writeln('Backup enviado por mail a ' . implode(', ', $destinos) . ' (' . $tamano . ')');
        return Command::SUCCESS;
    }
}
