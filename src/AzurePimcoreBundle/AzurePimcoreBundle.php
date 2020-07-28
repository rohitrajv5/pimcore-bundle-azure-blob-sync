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

class AzurePimcoreBundle extends AbstractPimcoreBundle {

    public function getJsPaths() {
        return [
            '/bundles/azurepimcore/js/pimcore/startup.js',
            '/bundles/azurepimcore/js/pimcore/azure.js'
        ];
    }
    
    public function getInstaller() {
        $this->getJsPaths();
        $this->getCssPaths();  
    }

}

