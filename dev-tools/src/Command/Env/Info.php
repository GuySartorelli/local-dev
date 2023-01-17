<?php

namespace DevTools\Command\Env;

use DevTools\Command\BaseCommand;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use DevTools\Utility\PHPService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class Info extends BaseCommand
{
    protected static $defaultName = 'env:info';

    protected static $defaultDescription = 'Get information about or change settings in a dev environment.';

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $env */
        $env = $this->getVar('env');

        $phpService = new PHPService($env, $this->getVar('output'));
        $dockerService = new DockerService($this->getVar('env'), $output);
        $containers = $dockerService->getContainersStatus();
        $canCheckContainer = $containers['webserver container'] === 'running';

        $io->horizontalTable([
            'URL',
            'CMS URL',
            'Mailhog',
            'DB Port',
            'Web IP',
            'XDebug',
            'CLI PHP Version',
            'Apache PHP Version',
            'Available PHP Versions',
            ...array_keys($containers),
        ], [[
            "<href={$env->getBaseURL()}/>{$env->getBaseURL()}/</>",
            "<href={$env->getBaseURL()}/admin>{$env->getBaseURL()}/admin</>",
            "<href={$env->getBaseURL()}:8025>{$env->getBaseURL()}:8025</>",
            "33{$env->getSuffix()}",
            "{$env->getIpAddress()}",
            $canCheckContainer ? ($phpService->debugIsEnabled() ? 'On' : 'Off') : null,
            $canCheckContainer ? $phpService->getCliPhpVersion() : null,
            $canCheckContainer ? $phpService->getApachePhpVersion(): null,
            implode(', ', PHPService::getAvailableVersions()),
            ...array_values($containers),
        ]]);

        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['info']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        With no options or arguments, this just prints information.
        HELP);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment.',
            './'
        );
    }
}
