<?php
namespace AppZap\Migrator\Command;

use AppZap\Migrator\DirectoryIterator\SortableDirectoryIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MigrateAllCommand
 *
 * @package AppZap\Migrator\Command
 */
class MigrateAllCommand extends AbstractMigrateCommand
{

    /**
     *
     */
    protected function configure()
    {
        parent::configure();

        $this->setDescription(
            'Migrates *.sh, *.sql and *.typo3cms files from the configured migrations directory.'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = parent::execute($input, $output);

        if ($result === 0) {
            $iterator = new SortableDirectoryIterator($this->migrationDirectoryPath);
            $io = new SymfonyStyle($input, $output);

            $highestExecutedVersion = 0;
            $errors = [];
            $executedFiles = 0;

            /** @var $fileInfo SplFileInfo */
            foreach ($iterator as $fileInfo) {
                $fileVersion = (int)$fileInfo->getBasename('.' . $fileInfo->getExtension());

                if ($fileInfo->getType() !== 'file') {
                    continue;
                }

                $migrationStatus = $this->registry->get(
                    'AppZap\\Migrator',
                    'migrationStatus:' . $fileInfo->getBasename(),
                    ['tstamp' => null, 'success' => false]
                );

                if ($migrationStatus['success']) {
                    // already successfully executed
                    continue;
                }

                $io->writeln(sprintf('execute %s', $fileInfo->getBasename()));

                $migrationErrors = array();
                $migrationOutput = '';
                switch ($fileInfo->getExtension()) {
                    case 'sql':
                        $success = $this->migrateSqlFile($fileInfo, $migrationErrors, $migrationOutput);
                        break;
                    case 'typo3cms':
                        $success = $this->migrateTypo3CmsFile($fileInfo, $migrationErrors, $migrationOutput);
                        break;
                    case 'sh':
                        $success = $this->migrateShellFile($fileInfo, $migrationErrors, $migrationOutput);
                        break;
                    default:
                        // ignore other files
                        $success = true;
                }

                $io->write(sprintf('done %s ', $fileInfo->getBasename()));
                $io->writeln($success ? '<fg=green>OK</>' : '<fg=red>ERROR</>');

                $io->writeln(trim($migrationOutput));

                // migration stops on the 1st erroneous sql file
                if (!$success || count($migrationErrors) > 0) {
                    $errors[$fileInfo->getFilename()] = $migrationErrors;
                    break;
                }

                if ($success) {
                    $executedFiles++;
                    $highestExecutedVersion = max($highestExecutedVersion, $fileVersion);
                }

                $this->registry->set(
                    'AppZap\\Migrator',
                    'migrationStatus:' . $fileInfo->getBasename(),
                    ['tstamp' => time(), 'success' => $success]
                );
            }

            $this->outputMessages($executedFiles, $errors, $io);
            $result = 0;
        }

        return $result;
    }
}
