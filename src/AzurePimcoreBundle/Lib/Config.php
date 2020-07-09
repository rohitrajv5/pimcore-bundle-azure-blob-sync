<?php

namespace AzurePimcoreBundle\Lib;

use Pimcore\Config\EnvironmentConfig;
use Pimcore\Config\EnvironmentConfigInterface;
use Pimcore\FeatureToggles\Features\DebugMode;
use Pimcore\Model\WebsiteSetting;
use Symfony\Cmf\Bundle\RoutingBundle\Routing\DynamicRouter;
use Pimcore\Config as ParentConfig;

class Config extends ParentConfig{
        
    /**
     * @static
     *
     * @return \Pimcore\Config\Config
     */
    public static function getAzureConfig()
    {
        if (\Pimcore\Cache\Runtime::isRegistered('pimcore_config_azure')) {
            $config = \Pimcore\Cache\Runtime::get('pimcore_config_azure');
        } else {
            try {
                $file = self::locateConfigFile('azure.php');
                $config = static::getConfigInstance($file);
            } catch (\Exception $e) {
                $config = new \Pimcore\Config\Config([]);
            }

            self::setAzureConfig($config);
        }

        return $config;
    }
    
    /**
     * @static
     *
     * @param \Pimcore\Config\Config $config
     */
    public static function setAzureConfig(\Pimcore\Config\Config $config)
    {
        \Pimcore\Cache\Runtime::set('pimcore_config_azure', $config);
    }
}
