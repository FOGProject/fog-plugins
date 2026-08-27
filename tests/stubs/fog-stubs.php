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
