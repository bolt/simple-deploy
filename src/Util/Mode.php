<?php

namespace Bolt\SimpleDeploy\Util;

/**
 * UNIX permission mode utility.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Mode
{
    /** @var int[] */
    private static $modeMap = [
        400 => 0400, 440 => 0440, 444 => 0444,
        600 => 0600, 660 => 0660, 664 => 0664, 666 => 0666,
        700 => 0700, 750 => 0750, 755 => 0755,
        770 => 0770, 775 => 0775, 777 => 0777,
    ];

    /**
     * @param $permission
     *
     * @return int
     */
    public static function resolve($permission)
    {
        if (array_key_exists($permission, static::$modeMap)) {
            return static::$modeMap[$permission];
        }

        return $permission;
    }
}
