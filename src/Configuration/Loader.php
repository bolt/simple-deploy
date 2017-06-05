<?php

namespace Bolt\SimpleDeploy\Configuration;

use Bolt\Filesystem\FilesystemInterface;
use Bolt\Filesystem\Handler\YamlFile;
use Bolt\SimpleDeploy\Configuration\Definition\TargetDefinition;
use Bolt\SimpleDeploy\Exception\InvalidTargetException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Yaml\Yaml;

/**
 * Configuration loader.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Loader
{
    /** @var FilesystemInterface */
    private $filesystem;
    private $reservedNames = [
        'app', 'bolt', 'bolt_assets', 'cache', 'config',
        'default', 'extensions', 'extensions_config',
        'files', 'root', 'themes', 'web', 'view',
    ];

    /**
     * Constructor.
     *
     * @param FilesystemInterface|null $filesystem
     */
    public function __construct(FilesystemInterface $filesystem = null)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @param FilesystemInterface|null $filesystem
     *
     * @return Loader
     */
    public static function create(FilesystemInterface $filesystem = null)
    {
        return new static($filesystem);
    }

    /**
     * @return Configuration
     */
    public function load()
    {
        $rawConfig = $this->loadYamlFile();
        $processor = new Processor();
        $deployments = [];
        foreach ($rawConfig as $target => $config) {
            $configuration = new TargetDefinition($target);
            $deployments[$target] = $processor->processConfiguration($configuration, [$target => $config]);
        }

        return new Configuration($deployments);
    }

    /**
     * @param string $target
     *
     * @return DeployTarget
     */
    public function loadTarget($target)
    {
        $rawConfig = $this->loadYamlFile();
        if (!isset($rawConfig[$target])) {
            throw new InvalidTargetException(sprintf('The target "%s" does not exist', $target));
        }
        $processor = new Processor();
        $configuration = new TargetDefinition($target);
        $config = $rawConfig[$target];
        $deployment = $processor->processConfiguration($configuration, [$target => $config]);

        return new DeployTarget($target, (string) $deployment['protocol'], $deployment['exclude'], $deployment['options']);
    }

    /**
     * @return array
     */
    private function loadYamlFile()
    {
        if ($this->filesystem === null) {
            return $this->loadTestYamlFile();
        }
        /** @var YamlFile $deployFile */
        $deployFile = $this->filesystem->getFile('.deploy.yml');

        return $this->validateYamlKeys((array) $deployFile->parse());
    }

    /**
     * @return array
     */
    private function loadTestYamlFile()
    {
        $configDirectories = [
            __DIR__ . '/../../../../..',
            __DIR__ . '/../..',
        ];
        $locator = new FileLocator($configDirectories);
        $yamlFile = $locator->locate('.deploy.yml', null, true);

        return $this->validateYamlKeys((array) Yaml::parse(file_get_contents($yamlFile)));
    }

    /**
     * Validate loaded YAML doesn't use any names of Bolt mount points.
     *
     * @param array $yaml
     *
     * @throws InvalidTargetException
     *
     * @return array
     */
    private function validateYamlKeys(array $yaml)
    {
        $keys = array_keys($yaml);
        if (array_intersect($keys, $this->reservedNames)) {
            throw new InvalidTargetException(sprintf(
                'The .deploy.yml file loaded contains a reserved key name. Reserved keys are: %s%s',
                PHP_EOL,
                implode(', ', $this->reservedNames)
            ));
        }

        return $yaml;
    }
}
