<?php
/**
 * The Capone image line carries the OS family as its eighth field.
 *
 * FOS reads each Capone image as a base64 line
 * path|osid|type|parttype|format|storage|storageip. It carried no OS family,
 * so a Capone image whose osid FOS does not know stopped the listing. The line
 * now ends with the family (FOGProject/fog-plugins#43), the way fog.checkin
 * already sends it for a normal task.
 *
 * Pinned here:
 *  - fields 1-7 keep their order, because an old FOS reads them by position;
 *  - the eighth is Image::getOSFamily() when the core has it;
 *  - the eighth is empty on a core without that method (FOS reads empty as
 *    "not sent"), so the plugin keeps working on any fogproject 1.6.
 *
 * CaponeTasking::__construct() reads filter_input(), which is null under CLI,
 * so the test drives the static imageLine() the constructor calls instead.
 *
 * Usage: php tests/capone-image-line.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);

require $root . '/tests/stubs/fog-stubs.php';
require $root . '/capone/src/Util/CaponeTasking.php';

/**
 * Image with the data accessors Capone reads, plus getOSFamily().
 */
class CaponeLineImageWithFamily
{
    /**
     * Read a field.
     *
     * @param string $key the field
     *
     * @return mixed
     */
    public function get($key)
    {
        $data = [
            'path' => 'win11',
            'osID' => 10,
            'imageTypeID' => 1,
            'format' => 1,
        ];
        return $data[$key];
    }
    /**
     * The partition type id.
     *
     * @return int
     */
    public function getPartitionType()
    {
        return 7;
    }
    /**
     * The OS family, as core has had it since fogproject#2085.
     *
     * @return string
     */
    public function getOSFamily()
    {
        return 'windows';
    }
}

/**
 * Image on an older core: identical data, and no getOSFamily() at all.
 *
 * Not a subclass of the class above: method_exists() would find the
 * inherited method.
 */
class CaponeLineImageOldCore
{
    /**
     * Read a field.
     *
     * @param string $key the field
     *
     * @return mixed
     */
    public function get($key)
    {
        $data = [
            'path' => 'win11',
            'osID' => 10,
            'imageTypeID' => 1,
            'format' => 1,
        ];
        return $data[$key];
    }
    /**
     * The partition type id.
     *
     * @return int
     */
    public function getPartitionType()
    {
        return 7;
    }
}

/**
 * Storage node with the two fields Capone reads.
 */
class CaponeLineNode
{
    /**
     * Read a field.
     *
     * @param string $key the field
     *
     * @return string
     */
    public function get($key)
    {
        $data = ['ip' => '10.0.0.5', 'path' => '/images'];
        return $data[$key];
    }
}

$types = [1 => 'n', 2 => 'mps', 3 => 'mpa', 4 => 'dd'];
$node = new CaponeLineNode();
$failures = [];
$checks = 0;

/**
 * Records one assertion.
 *
 * @param bool   $ok   whether it held
 * @param string $what what was asserted
 *
 * @return void
 */
function check($ok, $what)
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures[] = $what;
    }
}

$with = \FOG\Plugins\Capone\Util\CaponeTasking::imageLine(
    new CaponeLineImageWithFamily(),
    $node,
    $types
);
check(
    $with === 'win11|10|n|7|1|10.0.0.5:/images|10.0.0.5|windows',
    'core with getOSFamily(): got ' . var_export($with, true)
);

$without = \FOG\Plugins\Capone\Util\CaponeTasking::imageLine(
    new CaponeLineImageOldCore(),
    $node,
    $types
);
check(
    $without === 'win11|10|n|7|1|10.0.0.5:/images|10.0.0.5|',
    'core without getOSFamily(): got ' . var_export($without, true)
);
check(
    count(explode('|', $without)) === 8,
    'the line has eight fields even when the family is empty'
);

if ($failures) {
    foreach ($failures as $f) {
        fwrite(STDERR, "FAIL: $f\n");
    }
    exit(1);
}
echo "capone-image-line: $checks checks passed\n";
exit(0);
