<?php

namespace DevTools\Command\CLI;

use DevTools\Command\BaseCommand;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Gitonomy\Git\Admin as Git;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class MergeUp extends BaseCommand
{
    protected static $defaultName = 'cli:merge-up';

    protected static $defaultDescription = 'Merge up git repos given a github diff URL.';

    protected static bool $hasEnvironment = false;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->findRequiredMergeups($input->getArgument('diff-urls'));
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        $mergeUps = $this->getVar('mergeUps');
        $canClearMergeupDir = true;

        $io->writeln(self::STEP_STYLE . 'Starting mergeup process</>');

        if (empty($mergeUps)) {
            $io->success('No mergeups required');
            return Command::SUCCESS;
        }

        // Ensure we have a directory to do mergeups in
        $mergeupDir = Path::canonicalize($input->getOption('merge-up-dir'));
        if (!is_dir($mergeupDir)) {
            $canMerge = $io->ask("Merge up directory '$mergeupDir' doesn't exist. Create it? y/n", 'y');
            if ($canMerge !== 'y') {
                $io->warning('Opted not to merge up. Aborting.');
                return Command::FAILURE;
            }
            $fileSystem = new Filesystem();
            $fileSystem->mkdir($mergeupDir);
        }

        foreach ($mergeUps as $mergeupData) {
            $repoDir = Path::join($mergeupDir, $mergeupData['repo']);
            $alreadyMerged = false;

            $io->writeln(self::STEP_STYLE . "Starting mergeup for '{$mergeupData['repo']}' from {$mergeupData['mergeFrom']} to {$mergeupData['mergeTo']}</>");

            // Check if we've merged already or not.
            if (is_dir($repoDir)) {
                $alreadyMerged = $this->checkAlreadyMerged($repoDir);
                if (is_int($alreadyMerged)) {
                    return $alreadyMerged;
                }
            }

            // Merge up, if we haven't already.
            if (!$alreadyMerged) {
                $done = $this->startMerge($repoDir, $mergeupData);
                if ($done !== Command::SUCCESS) {
                    return $done;
                }
            }

            $repo = new Repository($repoDir);
            $actions = $repo->getWorkingCopy();

            // Check that the changes we've detected are expected
            $pendingFiles = $actions->getDiffPending()->getFiles();
            if (!empty($pendingFiles)) {
                $io->error('There are pending files here - add them to the staging area, revert their changes, or stash them before continuing.');
                return Command::FAILURE;
            }
            $stagedFiles = $actions->getDiffStaged()->getFiles();
            $stagedFileNames = [];
            foreach ($stagedFiles as $file) {
                $stagedFileNames[] = '- ' . $file->getName();
            }
            $numStaged = count($stagedFileNames);
            $io->block(["Found the following $numStaged staged file changes:", ...$stagedFileNames], style: 'fg=blue');
            $continue = $io->ask('Are we okay to commit and push? y/n', 'y');
            if ($continue !== 'y') {
                $io->warning('Opted not to continue. Aborting.');
                return Command::FAILURE;
            }

            $io->writeln(self::STEP_STYLE . 'Committing merge</>');
            $repo->run('commit', ['--no-edit']);
            if ($input->getOption('push')) {
                $io->writeln(self::STEP_STYLE . 'Pushing merge commit</>');
                $repo->run('push');
            } else {
                $io->warning('Merge commit not pushed. Push the commit yourself.');
                $canClearMergeupDir = false;
            }

            if ($canClearMergeupDir) {
                $io->writeln(self::STEP_STYLE . 'Clearing mergeup dir</>');
                $fileSystem = new Filesystem();
                $fileSystem->remove($mergeupDir);
                $fileSystem->mkdir($mergeupDir);
            } else {
                $io->warning('Cannot clear mergeup dir. Do that after whatever you still need to do.');
            }
            $io->success("Finished merge up for {$mergeupData['repo']}");
        }

        $io->success('Merged up successfully');
        return Command::SUCCESS;
    }

    private function checkAlreadyMerged(string $repoDir): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');

        $repo = new Repository($repoDir);
        try {
            $status = $repo->run('status');
            if (str_contains($status, 'All conflicts fixed but you are still merging')) {
                $io->writeln(self::STEP_STYLE . 'Detected that merge conflicts are resolved - continuing from post-merge.</>');
                return true;
            }
            if (str_contains($status, 'You have unmerged paths')) {
                $io->error("$repoDir has merge conflicts which still need to be resolved. DO NOT COMMIT THE RESULT.");
                return Command::FAILURE;
            }
            // TODO: Detect more unexpected states (e.g. merged but already committed).
            if (str_contains($status, 'Changes not staged for commit') || str_contains($status, 'nothing to commit, working tree clean')) {
                $io->error("$repoDir is in an unexpected state. You've done something weird.");
                return Command::FAILURE;
            }
        } catch (ProcessException $e) {
            if (str_contains($e->getMessage(), 'not a git repository')) {
                $io->error("$repoDir exists but isn't a git repository. You've done something weird.");
                return Command::FAILURE;
            }
            throw $e;
        }

        return false;
    }

    private function startMerge(string $repoDir, array $mergeupData): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');

        $io->writeln(self::STEP_STYLE . 'Cloning repo</>');
        $repo = Git::cloneTo($repoDir, $mergeupData['url'], false);
        $repoActions = $repo->getWorkingCopy();

        // Check out both branches so we have local copies to work with
        $io->writeln(self::STEP_STYLE . 'Checking out local copies of branches</>');
        $repoActions->checkout($mergeupData['mergeFrom']);
        $repoActions->checkout($mergeupData['mergeTo']);

        $io->writeln(self::STEP_STYLE . 'Merging</>');
        try {
            $repo->run('merge', [$mergeupData['mergeFrom'], '--no-commit', '--no-ff']);
        } catch (ProcessException $e) {
            if (str_contains($e->getMessage(), 'Merge conflict')) {
                $io->error([
                    'Merge conflict found - resolve the conflict (DO NOT COMMIT THE RESULT) and then run the merge-up command again.',
                    "cd $repoDir && git status",
                ]);
                return Command::FAILURE;
            }
            throw $e;
        }

        return Command::SUCCESS;
    }

    private function findRequiredMergeups(array $data)
    {
        $required = [];
        foreach ($data as $url) {
            // e.g. https://github.com/silverstripe/silverstripe-admin/compare/1...1.12
            if (!preg_match('%^https://github\.com/(?<repo>[^/]*/[^/]*)/compare/(?<mergeTo>\d{1,3}(\.\d{1,3})?)\.{3}(?<mergeFrom>\d{1,3}(\.\d{1,3})?)/?$%', $url, $matches)) {
                throw new RuntimeException("Invalid github diff URL: $url");
            }
            $repo = $matches['repo'];
            if (array_key_exists($repo, $required)) {
                throw new RuntimeException("URL for '$repo' included more than once.");
            }
            $required[$repo] = [
                'repo' => $repo,
                'mergeFrom' => $matches['mergeFrom'],
                'mergeTo' => $matches['mergeTo'],
                'url' => "git@github.com:$repo.git",
            ];
        }
        $this->setVar('mergeUps', $required);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['merge-up']);

        $desc = static::$defaultDescription;
        $this->setHelp($desc);
        $this->addArgument(
            'diff-urls',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'The github diff URL(s) to determine which module(s) and branches need mergeups.'
        );
        $this->addOption(
            'merge-up-dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'The directory in which the mergeups will be performed.',
            Path::canonicalize('~/dump/mergeups')
        );
        $this->addOption(
            'push',
            'p',
            InputOption::VALUE_NEGATABLE,
            'Whether to push the changes up to the remote after merging.',
            true
        );
    }
}
