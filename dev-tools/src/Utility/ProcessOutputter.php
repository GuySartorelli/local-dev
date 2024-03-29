<?php

namespace DevTools\Utility;

use LogicException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProcessOutputter
{
    private OutputInterface $output;

    private ?ProgressBar $progressBar = null;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function output(string $type, string $buffer): void
    {
        if (!$this->output->isVerbose()) {
            if (!$this->progressBar) {
                throw new LogicException('You forgot to call startCommand()');
            }
            $lines = array_filter(explode(PHP_EOL, $buffer));
            $this->progressBar->setMessage(end($lines));
            $this->progressBar->advance();
        } else {
            $this->output->write($buffer);
        }
    }

    public function startCommand(): void
    {
        if (!$this->output->isVerbose()) {
            if (!$this->output instanceof SymfonyStyle) {
                $this->output = new SymfonyStyle(new ArrayInput([]), $this->output);
            }
            $this->progressBar = $this->output->createProgressBar();
            // $this->progressBar->setFormat('Current step: %bar% %elapsed:6s%');
            $this->progressBar->setFormat('%message% <info>(%elapsed:4s%)</info>');
            $this->progressBar->start();
        }
    }

    public function endCommand(): void
    {
        if (!$this->output->isVerbose()) {
            $this->progressBar->finish();
            $this->progressBar->clear();
            $this->progressBar = null;
        }
    }
}
