<?php

namespace DevTools\Command;

use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use DevTools\Utility\PHPService;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

// TODO either subclass the Docker command or call that command from here to make code more DRY
class PhpConfig extends BaseCommand
{
    protected static $defaultName = 'php';

    protected static $defaultDescription = 'Make changes to PHP config (e.g. change php version, toggle xdebug).';

    private bool $failedInitialisation = false;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
        $hasOne = false;
        foreach (['php-version', 'info', 'toggle-debug'] as $option) {
            if ($input->getOption($option)) {
                $hasOne = true;
                break;
            }
        }
        parent::initialize($input, $output);
        if (!$hasOne) {
            /** @var SymfonyStyle $io */
            $io = $this->getVar('io');
            $io->error('At least one option must be used.');
            $this->failedInitialisation = true;
        }
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->failedInitialisation) {
            return Command::INVALID;
        }

        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getArgument('env-path')), getcwd());
        try {
            $this->setVar('env', $env = new Environment($proposedPath));
        } catch (LogicException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }
        $this->setVar('phpService', new PHPService($env, $this->getVar('output')));

        if ($phpVersion = $input->getOption('php-version')) {
            $failureCode = $this->swapToVersion($phpVersion);
            if ($failureCode) {
                return $failureCode;
            }
        }

        if ($input->getOption('toggle-debug')) {
            $failureCode = $this->toggleDebug();
            if ($failureCode) {
                return $failureCode;
            }
        }

        if ($input->getOption('info')) {
            $failureCode = $this->printPhpInfo();
            if ($failureCode) {
                return $failureCode;
            }
        }

        if (!$this->isSubCommand) {
            $io->success("Sucessfully completed command");
        }
        return Command::SUCCESS;
    }

    protected function swapToVersion(string $version): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var PHPService $phpService */
        $phpService = $this->getVar('phpService');

        if (!PHPService::versionIsAvailable($version)) {
            $io->error("PHP $version is not available.");
            return Command::INVALID;
        }

        $oldVersion = $phpService->getVersion();

        if ($oldVersion === $version) {
            $io->writeln(self::STEP_STYLE . "Already using version $version - skipping.</>");
            return false;
        }

        $io->writeln(self::STEP_STYLE . "Swapping PHP from $oldVersion to $version.</>");

        $command = <<<EOL
        rm /etc/alternatives/php && \\
        ln -s /usr/bin/php{$version} /etc/alternatives/php && \\
        rm /etc/apache2/mods-enabled/php{$oldVersion}.conf && \\
        rm /etc/apache2/mods-enabled/php{$oldVersion}.load && \\
        ln -s /etc/apache2/mods-available/php$version.conf /etc/apache2/mods-enabled/php$version.conf && \\
        ln -s /etc/apache2/mods-available/php$version.load /etc/apache2/mods-enabled/php$version.load && \\
        /etc/init.d/apache2 reload
        EOL;

        // Run the command
        return $this->runDockerCommand($command, asRoot: true, requiresRestart: true);
    }

    protected function toggleDebug(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var PHPService $phpService */
        $phpService = $this->getVar('phpService');
        $value = 'zend_extension=xdebug.so';
        $version = $phpService->getVersion();
        $onOff = 'on';
        if ($phpService->debugIsEnabled($version)) {
            $onOff = 'off';
            $value = ';' . $value;
        }
        $path = $phpService->getDebugPath($version);
        $io->writeln(self::STEP_STYLE . "Turning debug $onOff</>");
        $command = "echo \"$value\" > \"{$path}\" && /etc/init.d/apache2 reload";
        return $this->runDockerCommand($command, asRoot: true);
    }

    protected function printPhpInfo(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $io->writeln(self::STEP_STYLE . 'Printing PHP info</>');
        return $this->runDockerCommand('php -i', output: $this->getVar('output'));
    }

    protected function runDockerCommand(string $command, bool $asRoot = false, ?OutputInterface $output = null, bool $requiresRestart = false): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        if (!$output) {
            // $output = $this->getVar('output');
            $output = clone $this->getVar('output');
            $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        }
        $dockerService = new DockerService($this->getVar('env'), $output);
        if ($io->isVeryVerbose()) {
            $io->writeln(self::STEP_STYLE . "Running command in docker container: '$command'</>");
        }

        $success = $dockerService->exec($command, $asRoot);
        if (!$success) {
            $io->error('Problem occured while running command in docker container.');
            return Command::FAILURE;
        }

        if ($requiresRestart) {
            sleep(1);
            $success = $dockerService->up(false);
            if (!$success) {
                $io->error('Could not restart container.');
                return Command::FAILURE;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setHelp(static::$defaultDescription);
        $this->addOption(
            'php-version',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Swap to a specific PHP version.',
        );
        $this->addOption(
            'toggle-debug',
            'd',
            InputOption::VALUE_NONE,
            'Toggle xdebug on/off.',
        );
        $this->addOption(
            'info',
            'i',
            InputOption::VALUE_NONE,
            'Print out phpinfo (for webserver - assumed same for cli).',
        );
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
    }

}
