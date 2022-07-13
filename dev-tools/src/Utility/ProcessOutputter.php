<?php

namespace DevTools\Utility;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ProcessOutputter
{
    private bool $outputErrors;

    private OutputInterface $output;

    public function __construct(OutputInterface $output, bool $outputErrors = true)
    {
        $this->output = $output;
        $this->outputErrors = $outputErrors;
    }

    public function output(string $type, string $buffer)
    {
        if ($type === Process::ERR) {
            if ($this->outputErrors) {
                $this->output->write("ERR\t$buffer");
            }
        } else {
            $this->output->write($buffer);
        }
    }
}
