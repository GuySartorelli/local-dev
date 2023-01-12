<?php

namespace DevTools\Command\CLI;

use DevTools\Command\BaseCommand;
use Gitonomy\Git\Repository;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

/**
 * Based on https://gist.github.com/maxime-rainville/0e2cc280cc9d2e014a21b55a192076d9
 */
class GitSetRemotes extends BaseCommand
{
    protected static $defaultName = 'cli:git-set-remotes';

    protected static $defaultDescription = 'Set the various development remotes in the current git environment.';

    protected static bool $hasEnvironment = false;

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $gitRepo = new Repository(Path::canonicalize($input->getArgument('env-path')));
        $ccAccount = 'git@github.com:creative-commoners/';
        $securityAccount = 'git@github.com:silverstripe-security/';
        $prefixAndOrgRegex = '#^(?>git@github\.com:|https://github\.com/).*/#';

        $io->writeln(self::STEP_STYLE . 'Checking out all the remotes</>');

        $originUrl = trim($gitRepo->run('remote', ['get-url', 'origin']));

        // Validate origin URL
        if (!preg_match($prefixAndOrgRegex, $originUrl)) {
            throw new LogicException("Origin $originUrl does not appear to be valid");
        }

        // Add cc remote
        $ccRemote = preg_replace($prefixAndOrgRegex, $ccAccount, $originUrl);
        $gitRepo->run('remote', ['add', 'cc', $ccRemote]);

        // Add security remote
        if ($input->getOption('include-security')) {
            $securityRemote = preg_replace($prefixAndOrgRegex, $securityAccount, $originUrl);
            $gitRepo->run('remote', ['add', 'security', $securityRemote]);
        }

        // Rename origin
        if ($input->getOption('rename-origin')) {
            $gitRepo->run('remote', ['rename', 'origin', 'orig']);
        }

        // Fetch
        if ($input->getOption('fetch')) {
            $gitRepo->run('fetch', ['--all']);
        }

        $successMsg = 'Remotes added';
        if ($input->getOption('fetch')) {
            $successMsg .= ' and fetched';
        }
        $io->success($successMsg);
        return Command::SUCCESS;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['git-set-remotes']);

        $desc = static::$defaultDescription;
        $this->setHelp($desc);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment.',
            './'
        );
        $this->addOption(
            'rename-origin',
            null,
            InputOption::VALUE_NEGATABLE,
            'Rename the "origin" remote to "orig"',
            true
        );
        $this->addOption(
            'include-security',
            null,
            InputOption::VALUE_NEGATABLE,
            'Include the "Security" remote',
            true
        );
        $this->addOption(
            'fetch',
            null,
            InputOption::VALUE_NEGATABLE,
            'Run git fetch after defining remotes',
            false
        );
    }
}
