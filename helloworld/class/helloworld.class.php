<?php
/**
 * Hello World example plugin (single-entity model).
 *
 * A model extends FOGController and describes one row/entity. The ORM is
 * driven entirely by $databaseTable and $databaseFields; access values with
 * get('name') / set('name', ...) / save() / load() / destroy(), and
 * instantiate with an id (new HelloWorld(42)) to auto-load.
 *
 * NOTE: FOG's own autoloader, Initiator::autoload(), resolves the class --
 * there is no spl_autoload fallback (fogproject ADR 0013 §2b removed it, since
 * it let a plugin shadow a core class by filename). The filename still has to
 * match the class name (case-insensitively), because that match is how
 * Initiator finds the file. So class HelloWorld must live in the file
 * helloworld.class.php, and declares namespace FOG\Plugins\Helloworld.
 *
 * PHP version 5
 *
 * @category HelloWorld
 * @package  FOGProject
 * @author   FOG Project <info@fogproject.org>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Helloworld;

/**
 * Hello World example plugin (model).
 *
 * @category HelloWorld
 * @package  FOGProject
 * @author   FOG Project <info@fogproject.org>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class HelloWorld extends \FOG\Base\FOGController
{
    /**
     * The database table this model maps to.
     *
     * @var string
     */
    protected $databaseTable = 'helloWorld';
    /**
     * friendly name => real column name.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'hwID',
        'name' => 'hwName',
        'description' => 'hwDesc',
    ];
    /**
     * Fields that must be set before save() will succeed.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'name',
    ];
}
