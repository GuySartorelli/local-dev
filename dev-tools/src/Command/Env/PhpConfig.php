<?php

namespace DevTools\Command\Env;

use DevTools\Command\BaseCommand;
use DevTools\Utility\Environment;
use DevTools\Utility\PHPService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PhpConfig extends BaseCommand
{
    protected static $defaultName = 'env:php';

    protected static $defaultDescription = 'Make changes to PHP config (e.g. change php version, toggle xdebug).';

    private bool $failedInitialisation = false;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$this->isSubCommand && !$input->getOption('quiet') && !$input->getOption('verbose')) {
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
        /** @var Environment $env */
        $env = $this->getVar('env');
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

        $oldVersionCLI = $phpService->getCliPhpVersion();
        $oldVersionApache = $phpService->getApachePhpVersion();

        if ($oldVersionCLI === $oldVersionApache && $oldVersionApache === $version) {
            $io->writeln(self::STEP_STYLE . "Already using version $version - skipping.</>");
            return false;
        }

        $ret = false;

        if ($oldVersionCLI !== $version) {
            $io->writeln(self::STEP_STYLE . "Swapping CLI PHP from $oldVersionCLI to $version.</>");
            $ret = $ret || $this->swapCliToVersion($version);
        }

        if ($oldVersionApache !== $version) {
            $io->writeln(self::STEP_STYLE . "Swapping Apache PHP from $oldVersionApache to $version.</>");
            $ret = $ret || $this->swapApacheToVersion($oldVersionApache, $version);
        }

        return $ret;
    }

    private function swapCliToVersion(string $toVersion)
    {
        $command = <<<EOL
        rm /etc/alternatives/php && \\
        ln -s /usr/bin/php{$toVersion} /etc/alternatives/php
        EOL;

        $output = clone $this->getVar('output');
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        $suppressMessages = !$this->getVar('output')->isVeryVerbose();
        return $this->runDockerCommand($command, $output, asRoot: true, suppressMessages: $suppressMessages);
    }

    private function swapApacheToVersion(string $fromVersion, string $toVersion)
    {
        $command = <<<EOL
        rm /etc/apache2/mods-enabled/php{$fromVersion}.conf && \\
        rm /etc/apache2/mods-enabled/php{$fromVersion}.load && \\
        ln -s /etc/apache2/mods-available/php$toVersion.conf /etc/apache2/mods-enabled/php$toVersion.conf && \\
        ln -s /etc/apache2/mods-available/php$toVersion.load /etc/apache2/mods-enabled/php$toVersion.load
        EOL;

        $output = clone $this->getVar('output');
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        $suppressMessages = !$this->getVar('output')->isVeryVerbose();
        return $this->runDockerCommand($command, $output, asRoot: true, requiresRestart: true, suppressMessages: $suppressMessages);
    }

    protected function toggleDebug(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var PHPService $phpService */
        $phpService = $this->getVar('phpService');
        $value = 'zend_extension=xdebug.so';
        $version = $phpService->getCliPhpVersion();
        $onOff = 'on';
        if ($phpService->debugIsEnabled($version)) {
            $onOff = 'off';
            $value = ';' . $value;
        }
        $path = $phpService->getDebugPath($version);
        $io->writeln(self::STEP_STYLE . "Turning debug $onOff</>");
        $command = "echo \"$value\" > \"{$path}\" && /etc/init.d/apache2 reload";

        $output = clone $this->getVar('output');
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        $suppressMessages = !$this->getVar('output')->isVeryVerbose();
        return $this->runDockerCommand($command, $output, asRoot: true, suppressMessages: $suppressMessages);
    }

    protected function printPhpInfo(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $io->writeln(self::STEP_STYLE . 'Printing PHP info</>');
        $suppressMessages = !$this->getVar('output')->isVeryVerbose();
        return $this->runDockerCommand('php -i', $this->getVar('output'), suppressMessages: $suppressMessages);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['php']);

        $this->setHelp(static::$defaultDescription);
        $this->addOption(
            'php-version',
            'P',
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
            'The full path to the directory of the environment.',
            './'
        );
    }
}
