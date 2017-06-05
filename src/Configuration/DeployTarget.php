<?php

namespace Bolt\SimpleDeploy\Configuration;

use Bolt\Collection\Bag;

/**
 * Target host deployment configuration.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class DeployTarget
{
    /** @var string */
    private $name;
    /** @var string */
    private $protocol;
    /** @var Bag */
    private $excludeDirs;
    /** @var Bag */
    private $options;

    /**
     * Constructor.
     *
     * @param string $name
     * @param string $protocol
     * @param array  $excludeDirs
     * @param array  $options
     */
    public function __construct($name, $protocol, array $excludeDirs, array $options)
    {
        $this->name = $name;
        $this->protocol = $protocol;
        $this->excludeDirs = Bag::fromRecursive($excludeDirs);
        $this->options = Bag::fromRecursive($options);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * @return Bag
     */
    public function getExcludeDirs()
    {
        return $this->excludeDirs;
    }

    /**
     * @return Bag
     */
    public function getOptions()
    {
        return $this->options;
    }
}
