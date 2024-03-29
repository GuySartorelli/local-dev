<?php

namespace DevTools\Command\Database;

use LogicException;
use RuntimeException;
use DevTools\Command\BaseCommand;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Command which restores a database from some file in the host
 */
class Restore extends BaseCommand
{
    private string $sourceFile;

    protected static $defaultName = 'database:restore';

    protected static $defaultDescription = 'Restore the database from a file.';

    public const VALID_FILE_TYPES = [
        '.sql.zip',
        '.sql.tar.gz',
        '.sql.tgz',
        '.sql.tar',
        '.sql.gz',
        '.sql.bz2',
        '.sql',
    ];

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->validateOptions($input);
    }

    private function validateOptions($input)
    {
        $input->validate();

        $fileSystem = new Filesystem();
        $this->sourceFile = Path::canonicalize($input->getArgument('source-file'));
        if (!Path::isAbsolute($this->sourceFile)) {
            $this->sourceFile = Path::makeAbsolute($this->sourceFile, getcwd());
        }

        if (!$fileSystem->exists($this->sourceFile)) {
            throw new RuntimeException("source-file '$this->sourceFile' does not exist.");
        }

        if (!file_exists($this->sourceFile)) {
            throw new RuntimeException("source-file must be a file.");
        }

        if (str_contains($this->sourceFile, ':')) {
            throw new RuntimeException('source-file cannot contain a colon.');
        }

        $validExt = false;
        foreach (self::VALID_FILE_TYPES as $ext) {
            if (!str_ends_with($this->sourceFile, $ext)) {
                $validExt = true;
                break;
            }
        }
        if (!$validExt) {
            throw new RuntimeException('source-file filetype must be one of ' . implode(', ', self::VALID_FILE_TYPES));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $env */
        $env = $this->getVar('env');

        $io->writeln(self::STEP_STYLE . 'Restoring database.</>');

        $filename = basename($this->sourceFile);
        $tmpFilePath = "/tmp/$filename";

        $io->writeln(self::STEP_STYLE . 'Copying database to container.</>');
        $dockerService = new DockerService($env, $this->getVar('output'));
        $success = $dockerService->copyToContainer(
            DockerService::CONTAINER_DATABASE,
            $this->sourceFile,
            $tmpFilePath
        );
        if (!$success) {
            $io->error('Problem occured while copying file to docker container.');
            return self::FAILURE;
        }

        $io->writeln(self::STEP_STYLE . 'Restoring database from file.</>');
        $success = $this->runDockerCommand(
            $this->getRestoreCommand($tmpFilePath),
            $this->getVar('output'),
            suppressMessages: !$io->isVerbose(),
            container: DockerService::CONTAINER_DATABASE
        );
        if (!$success) {
            $io->error('Problem occured while restoring database from file.');
            return self::FAILURE;
        }

        $io->writeln(self::STEP_STYLE . 'Cleaning up inside container.</>');
        $success = $this->runDockerCommand(
            "rm $tmpFilePath",
            $this->getVar('output'),
            suppressMessages: !$io->isVerbose(),
            container: DockerService::CONTAINER_DATABASE
        );
        if (!$success) {
            $io->error('Problem occured during cleanup.');
            return self::FAILURE;
        }

        $io->success('Database restored successfully.');
        return self::SUCCESS;
    }

    private function getRestoreCommand(string $filePath): string
    {
        $mysqlPart = 'mysql -u root --password=root SS_mysite';
        foreach (self::VALID_FILE_TYPES as $ext) {
            if (str_ends_with($this->sourceFile, $ext)) {
                switch ($ext) {
                    case '.sql.zip':
                        return "unzip -p $filePath | $mysqlPart";
                    case '.sql.tar.gz':
                    case '.sql.tgz':
                        return "tar -O -xzf $filePath | $mysqlPart";
                    case '.sql.tar':
                        return "tar -O -xf $filePath | $mysqlPart";
                    case '.sql.gz':
                        return "zcat $filePath | $mysqlPart";
                    case '.sql.bz2':
                        return "bunzip2 < $filePath | $mysqlPart";
                    case '.sql':
                        return "cat $filePath | $mysqlPart";
                    default:
                        throw new LogicException("Unexpected file extension $ext");
                }
            }
        }
        throw new LogicException('source-file has an unexpected file extension');
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['restore']);

        $this->addArgument(
            'source-file',
            InputArgument::REQUIRED,
            'The path to the file from which the database will be restored. Valid filetypes are ' . implode(', ', self::VALID_FILE_TYPES),
        );
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment to restore.',
            './'
        );
    }
}
