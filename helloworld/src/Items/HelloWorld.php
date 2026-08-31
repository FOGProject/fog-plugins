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
 * it let a plugin shadow a core class by filename). It DERIVES the file from
 * the class name, so path and name are the same fact written twice:
 * FOG\Plugins\HelloWorld\Items\HelloWorld is
 * helloworld/src/Items/HelloWorld.php and nothing else. Rename one, rename
 * both. The plugin directory stays lowercase -- it is also the routing node
 * and the permission string -- while the namespace segment carries the
 * casing, and lowercasing the segment must land back on the directory name
 * (fogproject ADR 0035).
 *
 * Items/ is not enumerated by anything: a model is loaded when something
 * names it. Pages/, Hooks/, Events/, Reports/ and Tasks/ ARE enumerated, so
 * a class in the wrong one of those is loadable and never registered.
 *
 * PHP version 5
 *
 * @category HelloWorld
 * @package  FOGProject
 * @author   FOG Project <info@fogproject.org>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\HelloWorld\Items;

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
