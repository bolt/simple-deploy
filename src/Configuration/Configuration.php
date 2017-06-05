<?php

namespace Bolt\SimpleDeploy\Configuration;

use Bolt\SimpleDeploy\Exception\InvalidTargetException;

/**
 * Combined deployments configuration.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Configuration
{
    /** @var DeployTarget[] */
    private $targets;

    /**
     * Constructor.
     *
     * @param DeployTarget[] $targets
     */
    public function __construct(array $targets)
    {
        /**
         * @var string $target
         * @var array  $config
         */
        foreach ($targets as $target => $config) {
            $this->targets[$target] = new DeployTarget($target, (string) $config['protocol'], $config['exclude'], $config['options']);
        }
    }

    /**
     * Return the configuration parameters for a single deployment.
     *
     * @param string $target
     *
     * @return DeployTarget
     */
    public function getTarget($target)
    {
        if (isset($this->targets[$target])) {
            return $this->targets[$target];
        }

        throw new InvalidTargetException(sprintf('The target \'%s\' does not exist', $target));
    }

    /**
     * Return all deployment target configuration.
     *
     * @return DeployTarget[]
     */
    public function getTargets()
    {
        return $this->targets;
    }
}
