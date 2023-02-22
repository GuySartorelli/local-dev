<?php

namespace DevTools\Command;

use DevTools\Utility\Environment;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Filesystem\Path;

trait FindsModule
{
    private function normaliseModuleInput(InputInterface $input): void
    {
        $module = $input->getOption('module');
        if (!$module && preg_match('@/vendor/(?<module>[^/]*/[^/]*)@', getcwd(), $match)) {
            $module = $match['module'];
        }
        $input->setOption('module', $module);
    }

    /**
     * Get the relative path for the given module (starting with vendor/)
     *
     * @TODO: Make (or reuse an existing) recursive function instead of duplicating logic here and in Phpunit
     */
    private function getModuleDir(string $module): string
    {
        /** @var Environment $env */
        $env = $this->getVar('env');
        // TODO see if we can get the vendor dir in the even we don't have an env
        $vendorDir = Path::join($env->getWebRoot(), 'vendor');

        if (str_contains($module, '/')) {
            return Path::join('vendor', $module);
        } else {
            $checked = [];
            // Look at all the org dirs in the vendor directory
            foreach (scandir($vendorDir) as $orgDir) {
                if ($orgDir === '.' || $orgDir === '..') {
                    continue;
                }

                $currentPath = Path::join($vendorDir, $orgDir);

                if (is_dir($currentPath) && !array_key_exists($currentPath, $checked)) {
                    // Look at all repo dirs in each organisation directory
                    foreach (scandir($currentPath) as $repoDir) {
                        if ($repoDir === '.' || $repoDir === '..') {
                            continue;
                        }

                        $repoPath = Path::join($currentPath, $repoDir);

                        if ($repoDir === $module && is_dir($repoPath) && !array_key_exists($repoPath, $checked)) {
                            // Found the correct module (assuming there aren't duplicate module names across orgs)
                            return Path::join('vendor', $orgDir, $repoDir);
                        }

                        $checked[$repoPath] = true;
                    }
                }

                $checked[$currentPath] = true;
            }
        }
        // If we get to this point, we weren't able to find that module.
        throw new InvalidArgumentException("Module '$module' was not found.");
    }

    private function findVendorDir()
    {
        $cwd = getcwd();
        if (preg_match('@^(.*/vendor)\b@', $cwd, $match)) {
            return $match[1];
        }
        $fromHere = Path::join($cwd, 'vendor');
        if (is_dir($fromHere)) {
            return $fromHere;
        }
        throw new LogicException('No vendor dir found');
    }
}
