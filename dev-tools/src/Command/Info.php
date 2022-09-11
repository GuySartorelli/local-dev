<?php

namespace DevTools\Command;

use DevTools\Utility\Environment;
use DevTools\Utility\PHPService;
use DevTools\Utility\ProcessOutputter;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class Info extends BaseCommand
{
    protected static $defaultName = 'info';

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
        $containers = $this->getContainersStatus();

        $io->horizontalTable([
            'URL',
            'Mailhog',
            'DB Port',
            'Web IP',
            'XDebug',
            'PHP Version',
            'Available PHP Versions',
            ...array_keys($containers),
        ], [[
            "<href={$env->getBaseURL()}/>{$env->getBaseURL()}/</>",
            "<href={$env->getBaseURL()}:8025>{$env->getBaseURL()}:8025</>",
            "33{$env->getSuffix()}",
            "{$env->getIpAddress()}",
            $phpService->debugIsEnabled() ? 'On' : 'Off',
            $phpService->getCliPhpVersion(), // Assume Apache version is the same
            implode(', ', PHPService::getAvailableVersions()),
            ...array_values($containers),
        ]]);

        return Command::SUCCESS;
    }

    private function getContainersStatus()
    {
        /** @var Environment $env */
        $env = $this->getVar('env');
        /** @var ProcessHelper $processHelper */
        $processHelper = $this->getHelper('process');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $cmd = [
            'docker',
            'compose',
            'ps',
            '--all',
            '--format=json',
        ];
        $outputFormatter = new ProcessOutputter($output);
        $process = $processHelper->run(
            $output,
            new Process($cmd, $env->getDockerDir()),
            callback: [$outputFormatter, 'output']
        );
        if (!$process->isSuccessful()) {
            $io->warning("Couldn't get status of docker containers.");
            return null;
        }

        $containers = [];
        foreach (json_decode($output->fetch(), true) as $container) {
            $name = str_replace($env->getName() . '_', '', $container['Name']) . ' container';
            $containers[$name] = $container['State'];
        }
        return $containers;
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
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment.',
            './'
        );
    }
}
