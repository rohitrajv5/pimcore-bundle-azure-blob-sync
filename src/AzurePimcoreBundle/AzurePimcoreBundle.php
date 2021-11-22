<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace AzurePimcoreBundle\AzurePimcoreBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use \Pimcore\Model\User\Permission\Definition;
use Pimcore\Db;

class AzurePimcoreBundle extends AbstractPimcoreBundle {
   
    public function getJsPaths() {
        return [
            '/bundles/azurepimcore/js/pimcore/startup.js',
            '/bundles/azurepimcore/js/pimcore/azure.js'
        ];
    }

    public function getDescription()
    {
        return 'Pushes Pimcore assets to Microsoft Azure Blob Storage';
    }

    public function getVersion()
    {
        return 'v1.4';
    }
    
    public function getInstaller() {
        $this->getJsPaths();
        $this->getCssPaths();  
    }

    public function getInstaller()
    {
        $script = "INSERT IGNORE INTO users_permission_definitions (`key`) VALUES ('azure_blob_storage_bundle');";

        $db = Db::get();
        $db->query($script);
    }
}
