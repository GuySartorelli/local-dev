<?php

namespace DevTools\Command;

use DevTools\Utility\Config;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Down extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'down';

    protected static $defaultDescription = 'Completely tears down an environment that was created with the "up" command.';

    protected static bool $notifyOnCompletion = true;

    private Filesystem $filesystem;

    private ProcessHelper $processHelper;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->filesystem = new Filesystem();
        $this->processHelper = $this->getHelper('process');
        // Need password to update hosts - get that first so user can walk away while the rest processes.
        $this->setVar('password', $this->getPassword());
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getArgument('env-path')), getcwd());
        try {
            $this->setVar('env', $environment = new Environment($proposedPath));
        } catch (LogicException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        // Make sure we're not _in_ the environment dir when we destroy it.
        if (Path::isBasePath($environment->getBaseDir(), getcwd())) {
            $projectsDir = Path::join($environment->getBaseDir(), '../');
            chdir($projectsDir);
        }

        // Pull down docker
        $failureCode = $this->pullDownDocker();
        if ($failureCode) {
            return $failureCode;
        }

        // Delete environment directory
        try {
            $io->writeln(self::STEP_STYLE . 'Removing environment directory</>');
            $this->filesystem->remove($environment->getBaseDir());
        } catch (IOException $e) {
            $io->error('Couldn\'t delete environment directory: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Release suffix now that we aren't using it for the directory.
        // This way if we can't remove the hosts entry we can still re-use this suffix.
        Config::releaseSuffix($environment->getSuffix());

        // Remove hosts entry
        $failureCode = $this->cleanUpHosts();
        if ($failureCode) {
            return $failureCode;
        }

        $io->success("Env {$environment->getName()} successfully destroyed.");
        return Command::SUCCESS;
    }

    protected function pullDownDocker(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $dockerService = new DockerService($this->getVar('env'), $this->processHelper, $io);
        $io->writeln(self::STEP_STYLE . 'Taking down docker</>');

        $success = $dockerService->down();
        if (!$success) {
            $io->error('Problem occured while stopping docker containers.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function cleanUpHosts(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $io->writeln(self::STEP_STYLE . 'Updating hosts file</>');

        $hadError = true;
        if ($password = $this->getVar('password')) {
            // Update hosts file
            $hostsContent = file_get_contents('/etc/hosts');
            $hostsContent = preg_replace(
                '/^' . preg_quote($environment->getIpAddress(), '/') . '\h+' . preg_quote($environment->getName(), '/') . '\..*$/m',
                '',
                $hostsContent
            );
            if ($hostsContent === null) {
                $hadError = true;
            } else {
                exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . trim($hostsContent) . '" > /etc/hosts\' 2> /dev/null', $execOut, $hadError);
                if ($execOut) {
                    $io->writeln($execOut);
                }
            }
        }

        if ($hadError) {
            $io->error('Couldn\'t remove hosts entry. Please manually remove the relevant line in /etc/hosts');
            return Command::FAILURE;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->filesystem = new Filesystem();

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Removes a project by pulling down the docker containers, network, and destroying the volumes,
        deleting the directory in the project directory, removing the entry in the hosts file, etc.
        HELP);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
    }
}
