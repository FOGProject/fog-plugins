<?php
/**
 * This is only used for capone plugin.
 *
 * PHP version 5
 *
 * @category CaponeTasking
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Capone\Util;

/**
 * This is only used for capone plugin.
 *
 * @category CaponeTasking
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class CaponeTasking extends \FOG\Base\FOGBase
{
    /**
     * The actions supported fog capone.
     *
     * @var array
     */
    protected $actions = [
        'dmi',
        'imagelookup'
    ];
    /**
     * The image types so capone follows.
     *
     * @var array
     */
    protected $imgTypes = [
        1 => 'n',
        2 => 'mps',
        3 => 'mpa',
        4 => 'dd'
    ];
    /**
     * Builds the unencoded line FOS reads for one Capone image.
     *
     * Fields: path|osid|type|parttype|format|storage|storageip|osfamily.
     * Fields 1-7 keep their order because an old FOS reads them by position
     * and ignores a field it does not know. The eighth is the OS family, so
     * FOS can place an osid it has no table entry for. It is empty when core
     * has no Image::getOSFamily() (before fogproject#2085), which FOS reads
     * as "not sent". This lives in its own static method because the
     * constructor reads filter_input() and builds its own Image, so a test
     * cannot drive it (FOGProject/fog-plugins#43).
     *
     * @param object $Image       the image
     * @param object $StorageNode the storage node the image comes from
     * @param array  $imgTypes    image type id => FOS type name
     *
     * @return string
     */
    public static function imageLine($Image, $StorageNode, array $imgTypes)
    {
        $strSetup = '%s|%s|%s|%s|%s|%s|%s|%s';
        $family = method_exists($Image, 'getOSFamily') ?
            $Image->getOSFamily() :
            '';
        return sprintf(
            $strSetup,
            $Image->get('path'),
            $Image->get('osID'),
            $imgTypes[$Image->get('imageTypeID')],
            $Image->getPartitionType(),
            (int)$Image->get('format'),
            sprintf(
                '%s:%s',
                $StorageNode->get('ip'),
                $StorageNode->get('path')
            ),
            $StorageNode->get('ip'),
            $family
        );
    }
    /**
     * Initializes the Capone tasking class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $action = filter_input(INPUT_POST, 'action');
        switch ($action) {
            case 'dmi':
                echo self::getSetting('FOG_PLUGIN_CAPONE_DMI');
                break;
            case 'imagelookup':
                $key = filter_input(INPUT_POST, 'key');
                if (!$key) {
                    break;
                }
                try {
                    ob_start();
                    $capones = \FOG\Router\Route::getList(
                        'capone',
                        ['key' => $key]
                    );
                    foreach ($capones as &$Capone) {
                        $Image = new \FOG\Items\Image($Capone->imageID);
                        $OS = $Image->getOS();
                        $StorageNode = $Image
                            ->getStorageGroup()
                            ->getOptimalStorageNode();
                        if (!$Image->isValid()
                            || !$OS->isValid()
                            || !$StorageNode->isValid()
                        ) {
                            continue;
                        }
                        printf(
                            "%s\n",
                            base64_encode(
                                self::imageLine(
                                    $Image,
                                    $StorageNode,
                                    $this->imgTypes
                                )
                            )
                        );
                        unset($Capone);
                    }
                    throw new \Exception(
                        (
                            ob_get_contents() ?
                            ob_get_clean() :
                            base64_encode('')
                        )
                    );
                } catch (\Exception $e) {
                    echo $e->getMessage();
                }
        }
    }
}
