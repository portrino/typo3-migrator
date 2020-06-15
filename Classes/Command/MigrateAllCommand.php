<?php
namespace AppZap\Migrator\Command;

use AppZap\Migrator\DirectoryIterator\SortableDirectoryIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;

/**
 * Class MigrateAllCommand
 *
 * @package AppZap\Migrator\Command
 */
class MigrateAllCommand extends Command
{

    /**
     * @var array
     */
    protected $extensionConfiguration;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var string
     */
    protected $shellCommandTemplate = '%s --default-character-set=UTF8 -u"%s" -p"%s" -h "%s" -D "%s" -e "source %s" 2>&1';

    /**
     *
     */
    protected function configure()
    {
        $this->extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['migrator']);
        $this->registry = GeneralUtility::makeInstance(Registry::class);

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
        $io = new SymfonyStyle($input, $output);

        $pathFromConfig = null;
        if (empty($this->extensionConfiguration['migrationFolderPath'])) {
            $io->writeln(
                '<fg=yellow>The "sqlFolderPath" configuration is deprecated. Please use "migrationFolderPath" instead.</>'
            );
            $pathFromConfig = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $this->extensionConfiguration['sqlFolderPath'];
        } else {
            $pathFromConfig = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $this->extensionConfiguration['migrationFolderPath'];
        }
        $migrationFolderPath = realpath($pathFromConfig);

        if (!$migrationFolderPath) {
            GeneralUtility::mkdir_deep($pathFromConfig);
            $migrationFolderPath = realpath($pathFromConfig);
            if (!$migrationFolderPath) {
                $io->writeln(
                    sprintf(
                        '<fg=red>Migration folder not found. Please make sure "%s" exists!</>',
                        htmlspecialchars($pathFromConfig)
                    )
                );
            }
            return 1;
        }

        $io->writeln(sprintf('Migration path: %s', $migrationFolderPath));

        $iterator = new SortableDirectoryIterator($migrationFolderPath);

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

        return 0;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateSqlFile(SplFileInfo $fileInfo, &$errors, &$output)
    {
        $filePath = $fileInfo->getPathname();

        $shellCommand = sprintf(
            $this->shellCommandTemplate,
            $this->extensionConfiguration['mysqlBinaryPath'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['username'] ?: $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['password'] ?: $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['host'] ?: $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['database'] ?: $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'],
            $filePath
        );

        $output = shell_exec($shellCommand);

        $outputMessages = explode("\n", $output);
        foreach ($outputMessages as $outputMessage) {
            if (trim($outputMessage) && strpos($outputMessage, 'ERROR') !== false) {
                $errors[] = $outputMessage;
            }
        }

        return count($errors) === 0;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateTypo3CmsFile($fileInfo, &$errors, &$output)
    {
        $migrationContent = file_get_contents($fileInfo->getPathname());
        foreach (explode(PHP_EOL, $migrationContent) as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '#') !== 0 && strpos($line, '//') !== 0) {
                $outputLines = array();
                $status = null;
                $shellCommand =
                    ($this->extensionConfiguration['typo3cmsBinaryPath'] ?: './vendor/bin/typo3cms')
                    . ' '
                    . $line;
                exec($shellCommand, $outputLines, $status);
                $output = implode(PHP_EOL, $outputLines);
                if ($status !== 0) {
                    $errors[] = $output;
                    break;
                }
            }
        }
        return count($errors) === 0;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateShellFile($fileInfo, &$errors, &$output)
    {
        $command = $fileInfo->getPathname();
        $outputLines = array();
        $status = null;
        chdir(Environment::getPublicPath());
        exec($command, $outputLines, $status);
        $output = implode(PHP_EOL, $outputLines);
        if ($status !== 0) {
            $errors[] = $output;
        }
        return count($errors) === 0;
    }

    /**
     * @param int $executedFiles
     * @param array $errors
     * @param SymfonyStyle $io
     * @throws Exception
     */
    protected function outputMessages($executedFiles, $errors, $io)
    {

        if ($executedFiles === 0 && count($errors) === 0) {
            $io->writeln('Everything up to date. No migrations needed.');
        } else {
            if ($executedFiles) {
                $io->writeln(
                    sprintf(
                        'Migration of %d file%s completed.',
                        $executedFiles,
                        ($executedFiles > 1 ? 's' : '')
                    )
                );
            } else {
                $io->writeln('<fg=red>Migration failed</>');
            }
            if (count($errors)) {
                $io->writeln(sprintf('<fg=red>The following error%s occured:</>', (count($errors) > 1 ? 's' : '')));
                foreach ($errors as $filename => $error) {
                    $io->writeln(sprintf('File %s: ', $filename));
                    $io->writeln(sprintf('%s: ', implode(PHP_EOL, $error)));
                }
            }
        }
    }
}
