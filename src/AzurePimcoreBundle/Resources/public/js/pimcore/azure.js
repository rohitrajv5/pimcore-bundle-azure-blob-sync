pimcore.registerNS("pimcore.plugin.azure");

pimcore.plugin.azure = Class.create({
    initialize: function () {
        this.getData();
    },
    getData: function () {
        Ext.Ajax.request({
            url: "/admin/settings/get-azure",
            success: function (response) {

                this.data = Ext.decode(response.responseText);
                this.getTabPanel();

            }.bind(this)
        });
    },
    getValue: function (key, ignoreCheck) {

        var nk = key.split("\.");
        var current = this.data.values;

        for (var i = 0; i < nk.length; i++) {
            if (current[nk[i]]) {
                current = current[nk[i]];
            } else {
                current = null;
                break;
            }
        }

        if (ignoreCheck || (typeof current != "object" && typeof current != "array" && typeof current != "function")) {
            return current;
        }

        return "";
    },
    activate: function () {
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.setActiveItem("pimcore_settings_azure");
    },

    save: function () {
        var values = this.layout.getForm().getFieldValues();

        Ext.Ajax.request({
            url: "/admin/settings/set-azure",
            method: "PUT",
            params: {
                data: Ext.encode(values)
            },
            success: function (response) {
                try {
                    var res = Ext.decode(response.responseText);
                    if (res.success) {
                        pimcore.helpers.showNotification(t("success"), t("saved_successfully"), "success");

                        Ext.MessageBox.confirm(t("info"), t("reload_pimcore_changes"), function (buttonValue) {
                            if (buttonValue == "yes") {
                                window.location.reload();
                            }
                        }.bind(this));
                    } else {
                        pimcore.helpers.showNotification(t("error"), t("saving_failed"),
                                "error", t(res.message));
                    }
                } catch (e) {
                    pimcore.helpers.showNotification(t("error"), t("saving_failed"), "error");
                }
            }
        });
    },
    getTabPanel: function () {

        if (!this.panel) {
            this.panel = Ext.create('Ext.panel.Panel', {
                id: "pimcore_settings_azure",
                title: t("Azure Blob Container Settings"),
                iconCls: "pimcore_icon_printpage pimcore_icon_overlay_setting",
                border: false,
                layout: "fit",
                closable: true
            });

            this.panel.on("destroy", function () {
                pimcore.globalmanager.remove("settings_web2print");
            }.bind(this));


            this.pdfReactorSettings = Ext.create("Ext.form.FieldSet", {
                title: t('Azure Blob Container Settings'),
                collapsible: true,
                collapsed: false,
                hidden: this.getValue("generalTool") != 'pdfreactor',
                autoHeight: true,
                defaultType: 'textfield',
                defaults: {width: 450},
                items: [
                    {
                        fieldLabel: t("web2print_version"),
                        xtype: "combo",
                        width: 600,
                        editable: false,
                        name: "pdfreactorVersion",
                        value: this.getValue("pdfreactorVersion"),
                        store: [
                            ["8.0", "8.0"],
                            ["8.1", "8.1"],
                            ["9.0", "9.0"]
                        ],
                        mode: "local",
                        triggerAction: "all"
                    }, {
                        fieldLabel: t("web2print_protocol"),
                        xtype: "combo",
                        width: 600,
                        editable: false,
                        name: "pdfreactorProtocol",
                        value: this.getValue("pdfreactorProtocol"),
                        store: [
                            ["http", "http"],
                            ["https", "https"]
                        ],
                        mode: "local",
                        triggerAction: "all"
                    }, {
                        xtype: 'textfield',
                        width: 650,
                        fieldLabel: t("web2print_server"),
                        name: 'pdfreactorServer',
                        value: this.getValue("pdfreactorServer")
                    }, {
                        xtype: 'textfield',
                        width: 650,
                        fieldLabel: t("web2print_port"),
                        name: 'pdfreactorServerPort',
                        value: this.getValue("pdfreactorServerPort"),
                        emptyText: "9423"
                    }, {
                        xtype: 'textfield',
                        width: 650,
                        fieldLabel: t("web2print_baseURL"),
                        name: 'pdfreactorBaseUrl',
                        value: this.getValue("pdfreactorBaseUrl")
                    }, {
                        xtype: "displayfield",
                        hideLabel: true,
                        width: 600,
                        value: t('web2print_baseURL_txt'),
                        emptyText: "http://my-domain.org",
                        cls: "pimcore_extra_label_bottom"
                    }, {
                        xtype: 'textfield',
                        width: 650,
                        fieldLabel: t("web2print_apiKey"),
                        name: 'pdfreactorApiKey',
                        value: this.getValue("pdfreactorApiKey")
                    }, {
                        xtype: "displayfield",
                        hideLabel: true,
                        width: 600,
                        value: t('web2print_apiKey_txt'),
                        cls: "pimcore_extra_label_bottom"
                    }, {
                        xtype: 'textarea',
                        width: 650,
                        height: 200,
                        fieldLabel: t("web2print_licence"),
                        name: 'pdfreactorLicence',
                        value: this.getValue("pdfreactorLicence")
                    }
                ]
            });
            this.wkhtmlToPdfSettings = Ext.create("Ext.form.FieldSet", {
                title: t('web2print_wkhtmltopdf_settings'),
                collapsible: true,
                collapsed: false,
                autoHeight: true,
                hidden: this.getValue("generalTool") != 'wkhtmltopdf',
                defaultType: 'textfield',
                defaults: {width: 450},
                items: [
                    {
                        xtype: 'textfield',
                        width: 650,
                        fieldLabel: t("web2print_wkhtmltopdf_binary"),
                        name: 'wkhtmltopdfBin',
                        value: this.getValue("wkhtmltopdfBin")
                    }, {
                        xtype: 'textarea',
                        width: 650,
                        height: 200,
                        fieldLabel: t("web2print_wkhtmltopdf_options"),
                        name: 'wkhtml2pdfOptions',
                        value: this.getValue("wkhtml2pdfOptions")
                    }, {
                        xtype: "displayfield",
                        hideLabel: true,
                        width: 600,
                        value: t('web2print_wkhtmltopdf_options_txt'),
                        cls: "pimcore_extra_label_bottom"
                    }, {
                        xtype: 'textfield',
                        width: 650,
                        fieldLabel: t("web2print_hostname"),
                        name: 'wkhtml2pdfHostname',
                        value: this.getValue("wkhtml2pdfHostname")
                    }
                ]
            });

            this.layout = Ext.create('Ext.form.Panel', {
                bodyStyle: 'padding:20px 5px 20px 5px;',
                border: false,
                autoScroll: true,
                forceLayout: true,
                defaults: {
                    forceLayout: true
                },
                fieldDefaults: {
                    labelWidth: 150
                },
                buttons: [
                    {
                        text: t("save"),
                        handler: this.save.bind(this),
                        iconCls: "pimcore_icon_apply"
                    }
                ],
                items: [
                    {
                        xtype: 'fieldset',
                        title: t('general'),
                        collapsible: false,
                        autoHeight: true,
                        defaultType: 'textfield',
                        defaults: {width: 650},
                        items: [
                            {
                                xtype: 'textfield',
                                width: 650,
                                fieldLabel: t("Account URL"),
                                name: 'accountUrl',
                                value: this.getValue("accountUrl")
                            },
                            {
                                xtype: 'textfield',
                                width: 650,
                                fieldLabel: t("Account Name"),
                                name: 'accountName',
                                value: this.getValue("accountName")
                            },
                            {
                                xtype: 'textfield',
                                width: 650,
                                fieldLabel: t("Account Key"),
                                name: 'accountKey',
                                value: this.getValue("accountKey")
                            },
                            {
                                xtype: 'textfield',
                                width: 650,
                                fieldLabel: t("Container"),
                                name: 'container',
                                value: this.getValue("container")
                            },
                            {
                                xtype: 'checkboxfield',
                                name: 'enableAzure',
                                value: this.getValue("enableAzure"),
                                fieldLabel: t("Enable Azure Container?"),
                                width: 650,
                            }

                        ]
                    }

                ]
            });

            this.panel.add(this.layout);

            var tabPanel = Ext.getCmp("pimcore_panel_tabs");
            tabPanel.add(this.panel);
            tabPanel.setActiveItem(this.panel);

            pimcore.layout.refresh();
        }

        return this.panel;
    }
});