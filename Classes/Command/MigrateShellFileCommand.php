<?php
namespace AppZap\Migrator\Command;

use AppZap\Migrator\DirectoryIterator\SortableDirectoryIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class MigrateShellFileCommand
 *
 * @package AppZap\Migrator\Command
 */
class MigrateShellFileCommand extends AbstractMigrateCommand
{

    /**
     *
     */
    protected function configure()
    {
        parent::configure();

        $this->setDescription(
            'Migrates *.sh files from the configured migrations directory.'
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

                if ((bool)$input->getOption('dry-run') === false) {
                    $this->registry->set(
                        'AppZap\\Migrator',
                        'migrationStatus:' . $fileInfo->getBasename(),
                        ['tstamp' => time(), 'success' => $success]
                    );
                }
            }

            $this->outputMessages($executedFiles, $errors, $io);
            $result = 0;
        }

        return $result;
    }
}
