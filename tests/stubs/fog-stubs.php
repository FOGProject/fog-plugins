<?php
/**
 * Just enough of FOG for a plugin's own classes to be loaded and called.
 *
 * The plugin classes worth testing hardest are the ones that decide
 * something -- a validation rule, a URL, a lookup -- and those decisions are
 * pure. What stops them being callable in a test is only that the class
 * bodies extend FOG base classes, so the file cannot even be parsed into
 * existence without them.
 *
 * These stubs exist so that stays the only obstacle. They are deliberately
 * NOT a reimplementation: get()/set() hold values, getSetting() reads a
 * fixture, and Schema records what it was asked for. Anything a test needs
 * beyond that is a sign the thing under test is not pure and should be
 * pinned by inspection instead.
 *
 * Declared in the SAME namespaces core declares them in --
 * FOG\Base\FOGController, FOG\Base\FOGManagerController, FOG\Items\Schema --
 * and deliberately NOT also under their bare global names.
 *
 * That is what makes the plugin tests a gate for the qualification sweep. A
 * plugin file still saying `extends FOGController` resolves nothing here and
 * fails to load, which is exactly what would happen on a real server once
 * fogproject retires the compatibility aliases (ADR 0013 §2). Adding a global
 * alias back would make these stubs kinder than production, and the test
 * suite would go green on code that cannot run.
 *
 * Requires no database, and no FOG checkout.
 *
 * PHP version 7.4+
 *
 * @category Tests
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Base {
    /**
     * Enough of FOGController for a model's class body to exist.
     */
    class FOGController
    {
        /**
         * The globalSettings fixture getSetting() reads.
         *
         * @var array
         */
        public static $settings = [];
        /**
         * The values set on this object.
         *
         * @var array
         */
        protected $data = [];
        /**
         * Read a value.
         *
         * @param string $key the key
         *
         * @return mixed
         */
        public function get($key)
        {
            return $this->data[$key] ?? '';
        }
        /**
         * Write a value.
         *
         * @param string $key   the key
         * @param mixed  $value the value
         *
         * @return self
         */
        public function set($key, $value)
        {
            $this->data[$key] = $value;
            return $this;
        }
        /**
         * Read a setting from the fixture.
         *
         * @param string $key the setting name
         *
         * @return mixed
         */
        public static function getSetting($key)
        {
            return self::$settings[$key] ?? '';
        }
        /**
         * Never reached: a test asserting on save() asserts on what it refuses.
         *
         * @return bool
         */
        public function save()
        {
            return true;
        }
    }
}

namespace FOG\Base {
    /**
     * Enough of FOGManagerController for a manager's class body to exist.
     */
    class FOGManagerController
    {
        /**
         * Passes a createTable() call straight through.
         *
         * The real one (GH-1245) fills a default into every NOT NULL column that
         * has none, leaving the primary key, anything the model declares
         * required, and anything whose name ends in ID. It only ADDS -- a default
         * the caller passed explicitly always wins -- so a pass-through here
         * cannot mask an assertion about a default a manager sets deliberately,
         * which is what these tests check. Reimplementing the rule in the stub
         * would only give it somewhere to drift from.
         *
         * @return string
         */
        public function createTableSql(...$args)
        {
            // Qualified: this stub sits in FOG\Base and Schema is declared in
            // FOG\Items, so a bare name would resolve to FOG\Base\Schema and
            // find nothing. PHP falls back to the global namespace for
            // functions and constants, never for class names.
            return \FOG\Items\Schema::createTable(...$args);
        }
    }
}

namespace FOG\Items {
    /**
     * Captures what a manager asks for instead of building SQL.
     */
    class Schema
    {
        /**
         * The arguments of the last createTable() call.
         *
         * @var array
         */
        public static $lastCall = [];
        /**
         * Records the call and returns a placeholder.
         *
         * @return string
         */
        public static function createTable(...$args)
        {
            self::$lastCall = $args;
            return 'CREATE TABLE ...';
        }
    }
}

namespace FOG\Base {
    /**
     * Enough of Hook for a hook's class body to exist and be driven.
     *
     * Hooks were unreachable from these tests until now: every one of them
     * extends this class, so none could be loaded at all, and everything a
     * hook decided had to be pinned by reading its source. That is the right
     * default for a hook whose job is to echo a form -- and the wrong one for
     * a hook that WRITES, where the branch taken decides whether rows survive.
     *
     * registerInstalled() records rather than registers, so a test can assert
     * which events a hook actually asked for.
     */
    class Hook
    {
        /**
         * Events this hook registered, as [event, method] pairs.
         *
         * @var array
         */
        public $registered = [];
        /**
         * What getClass() hands back, keyed by class name.
         *
         * @var array
         */
        public static $classes = [];
        /**
         * Initialize.
         */
        public function __construct()
        {
        }
        /**
         * Records the registration.
         *
         * @param array $events the [event, method] pairs
         *
         * @return void
         */
        public function registerInstalled(array $events)
        {
            $this->registered = $events;
        }
        /**
         * Hands back a fixture, or a permissive default.
         *
         * @param string $class the class wanted
         * @param mixed  $id    optional id
         *
         * @return mixed
         */
        public static function getClass($class, $id = null)
        {
            if (isset(self::$classes[$class])) {
                $fixture = self::$classes[$class];

                return is_callable($fixture) ? $fixture($id) : $fixture;
            }

            return new StubItem($id);
        }
        /**
         * A no-op in tests; the real one throws on a bad token.
         *
         * @return void
         */
        public static function checkAuthAndCSRF()
        {
        }
    }
    /**
     * A stand-in that FORWARDS to whatever Hook::$classes has registered.
     *
     * Hook::getClass() above answers a name from that registry, and for a
     * long time that was the only way a plugin reached a collaborator -- so a
     * test could write `Hook::$classes['LocationAssociationManager'] =
     * $manager` and then assert on $manager->batches, because the plugin was
     * handed that very object.
     *
     * Plugins now write `new \FOG\Plugins\Location\Managers\
     * LocationAssociationManager()` (fogproject ADR 0043), which PHP resolves
     * for real and which cannot hand back somebody else's instance. So the
     * autoloader at the foot of this file materialises the missing class as a
     * subclass of THIS, whose every method forwards into the registered
     * fixture. The registry keeps working unchanged, and so does every test
     * that asserts on the object it registered.
     *
     * Unregistered, it is just a StubItem -- the permissive default
     * getClass() had.
     */
    class StubProxy extends StubItem
    {
        /**
         * The registered fixture, or null when there is none.
         *
         * @var StubItem|null
         */
        private $_target;
        /**
         * Resolves the fixture registered for this class's short name.
         *
         * @param mixed $id the id
         */
        public function __construct($id = null)
        {
            parent::__construct($id);
            $name = static::class;
            $short = substr($name, strrpos($name, '\\') + 1);
            $fixture = isset(Hook::$classes[$short])
                ? Hook::$classes[$short]
                : null;
            if (is_callable($fixture)) {
                $fixture = $fixture($id);
            }
            $this->_target = $fixture instanceof StubItem ? $fixture : null;
        }
        /**
         * Whether the record exists.
         *
         * @return bool
         */
        public function isValid()
        {
            return null === $this->_target
                ? parent::isValid()
                : $this->_target->isValid();
        }
        /**
         * Read a value.
         *
         * @param string $key the key
         *
         * @return mixed
         */
        public function get($key)
        {
            return null === $this->_target
                ? parent::get($key)
                : $this->_target->get($key);
        }
        /**
         * Records the batch against the fixture, so the test can see it.
         *
         * @param array $fields the columns
         * @param array $values the rows
         *
         * @return void
         */
        public function insertBatch($fields, $values)
        {
            if (null === $this->_target) {
                parent::insertBatch($fields, $values);
                return;
            }
            $this->_target->insertBatch($fields, $values);
        }
        /**
         * Returns a select box a form can render.
         *
         * @return string
         */
        public function buildSelectBox(...$args)
        {
            return null === $this->_target
                ? parent::buildSelectBox(...$args)
                : $this->_target->buildSelectBox(...$args);
        }
    }
    /**
     * A stand-in model that exists, has a name, and records writes.
     */
    class StubItem
    {
        /**
         * The id it was constructed with.
         *
         * @var mixed
         */
        public $id;
        /**
         * Whether isValid() answers true.
         *
         * @var bool
         */
        public $valid = true;
        /**
         * Every insertBatch() call made on it.
         *
         * @var array
         */
        public $batches = [];
        /**
         * Initialize.
         *
         * @param mixed $id the id
         */
        public function __construct($id = null)
        {
            $this->id = $id;
        }
        /**
         * Whether the record exists.
         *
         * @return bool
         */
        public function isValid()
        {
            return $this->valid;
        }
        /**
         * Read a value.
         *
         * @param string $key the key
         *
         * @return mixed
         */
        public function get($key)
        {
            return 'name' === $key ? 'StubName' : $this->id;
        }
        /**
         * Records the batch.
         *
         * @param array $fields the columns
         * @param array $values the rows
         *
         * @return void
         */
        public function insertBatch($fields, $values)
        {
            $this->batches[] = [$fields, $values];
        }
        /**
         * Returns a select box a form can render.
         *
         * @return string
         */
        public function buildSelectBox(...$args)
        {
            $name = $args[1] ?? '';

            return '<select name="' . $name . '"><option value=""></option>'
                . '</select>';
        }
    }
}

namespace FOG\Router {
    /**
     * Enough of Route to record a deletemass rather than perform one.
     */
    class Route
    {
        /**
         * Every deletemass() call, as [class, where].
         *
         * @var array
         */
        public static $deleted = [];
        /**
         * Records the delete.
         *
         * @param string $class the class name
         * @param array  $where  the conditions
         *
         * @return void
         */
        public static function deletemass($class, $where)
        {
            self::$deleted[] = [$class, $where];
        }
    }
}

namespace FOG\Util {
    /**
     * Enough of SharedHostValues to feed a hint a fixed answer.
     */
    class SharedHostValues
    {
        /**
         * What forHostRows() returns, keyed by friendly key.
         *
         * @var array
         */
        public static $rows = [];
        /**
         * Returns the fixture.
         *
         * @return array
         */
        public static function forHostRows(...$args)
        {
            return self::$rows;
        }
        /**
         * Renders the info as text a test can assert on.
         *
         * @param array $info the info
         *
         * @return string
         */
        public static function hint($info)
        {
            if (empty($info['uniform'])) {
                return '(varies)';
            }

            return '' === (string)$info['value']
                ? '(empty on all)'
                : (string)$info['value'];
        }
    }
}


namespace {
    /*
     * Materialise any FOG class a test did not declare itself.
     *
     * Scoped to the FOG\ prefix and registered last, so it can only answer a
     * name nothing else could: a plugin file the test require_once'd has
     * already declared its own classes and never reaches here. What reaches
     * here is the collaborator that file NAMES but the test never loaded,
     * which is exactly what Hook::getClass() used to cover.
     *
     * eval() rather than class_alias() because the proxy has to know its own
     * short name to find its fixture, and an alias reports the name of the
     * class it aliases, not the name it was reached by.
     */
    spl_autoload_register(
        function ($class) {
            if (0 !== strpos($class, 'FOG\\')) {
                return;
            }
            $cut = strrpos($class, '\\');
            eval(
                'namespace ' . substr($class, 0, $cut) . ';'
                . ' class ' . substr($class, $cut + 1)
                . ' extends \\FOG\\Base\\StubProxy {}'
            );
        }
    );
}
