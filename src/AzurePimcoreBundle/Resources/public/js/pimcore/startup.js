pimcore.registerNS("pimcore.plugin.pimcoreAzureBundle");

pimcore.plugin.pimcoreAzureBundle = Class.create(pimcore.plugin.admin, {
    getClassName: function () {
        return "pimcore.plugin.pimcoreAzureBundle";
    },

    initialize: function () {
        pimcore.plugin.broker.registerPlugin(this);

        Ext.Ajax.timeout = 3600000;
        Ext.Ajax.setTimeout(3600000);
        Ext.override(Ext.data.proxy.Ajax, {timeout: 3600000});        
    },

    pimcoreReady: function (params, broker) {
        if(pimcore.currentuser.permissions.indexOf("azure_blob_storage_bundle") >= 0) {
            var settingsMenu = new Ext.Action({
                text: 'The Azure Container Configurations',
                iconCls: 'pimcore_icon_gridconfig_class_attributes',
                handler: function () {
                    var dataExportMainTab = Ext.get("pimcore_settings_azure");
                    if (dataExportMainTab) {
                        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
                        tabPanel.setActiveItem("pimcore_settings_azure" );
                    } else {
                        azuretab = new pimcore.plugin.azure();
                    }
                }
            });
    
            if (layoutToolbar.settingsMenu)
                layoutToolbar.settingsMenu.add(settingsMenu);
        }
    },
});

var AzurePimcoreBundlePlugin = new pimcore.plugin.pimcoreAzureBundle();