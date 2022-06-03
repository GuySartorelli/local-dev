<?php

namespace DevTools\Command;

use DevTools\Utility\Environment as Env;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

class Environment extends BaseCommand
{
    protected static $defaultName = 'info';

    protected static $defaultDescription = 'Get information about or change settings in a dev environment.';

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getOption('env-path')), getcwd());
        try {
            $env = new Env($proposedPath);
        } catch (LogicException $e) {
            $output->writeln($e->getMessage());
            return Command::INVALID;
        }

        // TODO fetch php version
        // TODO fetch whether debug is enabled
        // TODO fetch whether docker containers are running

        $output->write([
            "URL: {$env->getBaseURL()}",
            "Mailhog: {$env->getBaseURL()}:8025",
            "DB Port: 33{$env->getSuffix()}",
            "Web IP: {$env->getIpAddress()}",
        ], true);

        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        With no options or arguments, this just prints information.
        HELP);
        $this->addOption(
            'env-path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'The full path to the directory of the environment.',
            './'
        );
        // TODO add options for setting PHP version, toggling (or setting) xdebug on/off, etc
    }
}
