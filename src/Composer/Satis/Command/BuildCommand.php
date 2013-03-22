<?php

/*
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Satis\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Composer\Composer;
use Composer\Config;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\AliasPackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\PackageInterface;
use Composer\Json\JsonFile;
use Composer\Satis\Satis;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class BuildCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Builds a composer repository out of a json file')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
                new InputOption('no-html-output', null, InputOption::VALUE_NONE, 'Turn off HTML view'),
                new InputOption('twig-template', 't', InputArgument::OPTIONAL, 'Location of twig template to use', null),
            ))
            ->setHelp(<<<EOT
The <info>build</info> command reads the given json file
(satis.json is used by default) and outputs a composer
repository in the given output-dir.

The json config file accepts the following keys:

- "repositories": defines which repositories are searched
  for packages.
- "output-dir": where to output the repository files
  if not provided as an argument when calling build.
- "require-all": boolean, if true, all packages present
  in the configured repositories will be present in the
  dumped satis repository.
- "require": if you do not want to dump all packages,
  you can explicitly require them by name and version.
- "config": all config options from composer, see
  http://getcomposer.org/doc/04-schema.md#config
- "output-html": boolean, controls whether the repository
  has an html page as well or not.
- "name": for html output, this defines the name of the
  repository.
- "homepage": for html output, this defines the home URL
  of the repository (where you will host it).
EOT
            )
        ;
    }

    /**
     * @param InputInterface  $input  The input instance
     * @param OutputInterface $output The output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');
        $rfs = null;
        if (preg_match('{^https?://}i', $input->getArgument('file')) && $fileContents = file_get_contents($input->getArgument('file'))) {
            $io = new BufferIo($fileContents);
            $rfs = new RemoteFilesystem($io);
        }
        $file = new JsonFile($input->getArgument('file'), $rfs);
        if ($rfs === null && !$file->exists()) {
            $output->writeln('<error>File not found: '.$input->getArgument('file').'</error>');

            return 1;
        }
        $config = $file->read();

        // disable packagist by default
        unset(Config::$defaultRepositories['packagist']);

        // fetch options
        $requireAll = isset($config['require-all']) && true === $config['require-all'];
        if (!$requireAll && !isset($config['require'])) {
            $output->writeln('No explicit requires defined, enabling require-all');
            $requireAll = true;
        }

        if (!$outputDir = $input->getArgument('output-dir')) {
            $outputDir = isset($config['output-dir']) ? $config['output-dir'] : null;
        }

        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside '.$input->getArgument('file'));
        }

        $composer = $this->getApplication()->getComposer(true, $config);
        $packages = $this->selectPackages($composer, $output, $verbose, $requireAll);

        if ($htmlView = !$input->getOption('no-html-output')) {
            $htmlView = !isset($config['output-html']) || $config['output-html'];
        }

        $filename = $outputDir.'/packages.json';
        $this->dumpJson($packages, $output, $filename);

        if ($htmlView) {
            $rootPackage = $composer->getPackage();
            $this->dumpWeb($packages, $output, $rootPackage, $outputDir, $input->getOption('twig-template'));
        }
    }

    private function selectPackages(Composer $composer, OutputInterface $output, $verbose, $requireAll)
    {
        $targets = array();
        $selected = array();

        foreach ($composer->getPackage()->getRequires() as $link) {
            $targets[$link->getTarget()] = array(
                'matched' => false,
                'link' => $link,
                'constraint' => $link->getConstraint()
            );
        }

        // run over all packages and store matching ones
        $output->writeln('<info>Scanning packages</info>');
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                // skip aliases
                if ($package instanceof AliasPackage) {
                    continue;
                }

                $name = $package->getName();
                $version = $package->getVersion();

                // skip non-matching packages
                if (!$requireAll && (!isset($targets[$name]) || !$targets[$name]['constraint']->matches(new VersionConstraint('=', $version)))) {
                    continue;
                }

                // add matching package if not yet selected
                if (!isset($selected[$package->getUniqueName()])) {
                    if ($verbose) {
                        $output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                    }
                    $targets[$name]['matched'] = true;
                    $selected[$package->getUniqueName()] = $package;
                }
            }
        }

        // check for unmatched requirements
        foreach ($targets as $package => $target) {
            if (!$target['matched']) {
                $output->writeln('<error>The '.$target['link']->getTarget().' '.$target['link']->getPrettyConstraint().' requirement did not match any package</error>');
            }
        }

        asort($selected, SORT_STRING);

        return $selected;
    }

    private function dumpJson(array $packages, OutputInterface $output, $filename)
    {
        $repo = array('packages' => array());
        $dumper = new ArrayDumper;
        foreach ($packages as $package) {
            $repo['packages'][$package->getPrettyName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }
        $output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($filename);
        $repoJson->write($repo);
    }

    private function dumpWeb(array $packages, OutputInterface $output, PackageInterface $rootPackage, $directory, $template = null)
    {
        $templateDir = $template ? pathinfo($template, PATHINFO_DIRNAME) : __DIR__.'/../../../../views';
        $loader = new \Twig_Loader_Filesystem($templateDir);
        $twig = new \Twig_Environment($loader);

        $mappedPackages = $this->getMappedPackageList($packages);

        $name = $rootPackage->getPrettyName();
        if ($name === '__root__') {
            $name = 'A';
            $output->writeln('Define a "name" property in your json config to name the repository');
        }

        if (!$rootPackage->getHomepage()) {
            $output->writeln('Define a "homepage" property in your json config to configure the repository URL');
        }

        $output->writeln('<info>Writing web view</info>');

        $content = $twig->render($template ? pathinfo($template, PATHINFO_BASENAME) : 'index.html.twig', array(
            'name'          => $name,
            'url'           => $rootPackage->getHomepage(),
            'description'   => $rootPackage->getDescription(),
            'packages'      => $mappedPackages,
        ));

        file_put_contents($directory.'/index.html', $content);
    }

    private function getMappedPackageList(array $packages)
    {
        $groupedPackages = $this->groupPackagesByName($packages);

        $mappedPackages = array();
        foreach ($groupedPackages as $name => $packages) {
            $mappedPackages[$name] = array(
                'highest' => $this->getHighestVersion($packages),
                'versions' => $this->getDescSortedVersions($packages),
            );
        }

        return $mappedPackages;
    }

    private function groupPackagesByName(array $packages)
    {
        $groupedPackages = array();
        foreach ($packages as $package) {
            $groupedPackages[$package->getName()][] = $package;
        }

        return $groupedPackages;
    }

    private function getHighestVersion(array $packages)
    {
        $highestVersion = null;
        foreach ($packages as $package) {
            if (null === $highestVersion || version_compare($package->getVersion(), $highestVersion->getVersion(), '>=')) {
                $highestVersion = $package;
            }
        }

        return $highestVersion;
    }

    private function getDescSortedVersions(array $packages)
    {
        usort($packages, function ($a, $b) {
            return version_compare($b->getVersion(), $a->getVersion());
        });

        return $packages;
    }
}
