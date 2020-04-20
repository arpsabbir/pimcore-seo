pimcore.registerNS('Seo.MetaData.Integrator.PropertyIntegratorItem');
Seo.MetaData.Integrator.PropertyIntegratorItem = Class.create({

    id: null,
    data: null,
    fieldType: null,
    fieldTypeProperty: null,
    imageAwareTypes: [],
    configuration: null,
    removeFieldCallback: null,
    refreshFieldCallback: null,
    form: null,
    integratorValueFetcher: null,

    initialize: function (
        id,
        data,
        fieldType,
        fieldTypeProperty,
        imageAwareTypes,
        removeFieldCallback,
        refreshFieldCallback,
        configuration
    ) {
        this.id = id;
        this.data = data;
        this.fieldType = fieldType;
        this.fieldTypeProperty = fieldTypeProperty;
        this.imageAwareTypes = imageAwareTypes;
        this.removeFieldCallback = removeFieldCallback;
        this.refreshFieldCallback = refreshFieldCallback;
        this.configuration = configuration;
        this.integratorValueFetcher = new Seo.MetaData.Extension.IntegratorValueFetcher();
    },

    createItem: function () {

        this.form = new Ext.form.Panel({
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            border: false,
            style: {
                padding: '5px',
            }
        });

        this.form.add(this.getFieldContainer());

        return this.form;
    },

    getFieldContainer: function () {

        var propertyTypeStore,
            configuration = this.configuration,
            typeStoreValue = this.getStoredValue(this.fieldType, null),
            propertyTypeValue = typeStoreValue === null ? this.fieldTypeProperty : typeStoreValue,
            field = this.getContentFieldBasedOnType(propertyTypeValue, this.id);

        propertyTypeStore = new Ext.data.ArrayStore({
            fields: ['label', 'key'],
            data: configuration.hasOwnProperty('properties') ? configuration.properties : []
        });

        return {
            xtype: 'fieldcontainer',
            layout: 'hbox',
            style: {
                marginTop: '5px',
                paddingBottom: '5px',
                borderBottom: '1px dashed #b1b1b1;'
            },
            items: [
                {
                    xtype: 'combo',
                    name: this.fieldType,
                    value: propertyTypeValue,
                    fieldLabel: t(Ext.String.capitalize(this.fieldType)),
                    displayField: 'label',
                    valueField: 'key',
                    labelAlign: 'left',
                    queryMode: 'local',
                    triggerAction: 'all',
                    editable: false,
                    allowBlank: true,
                    style: 'margin: 0 10px 0 0',
                    flex: 1,
                    listeners: {
                        change: function (cb, value) {
                            var fieldContainer = cb.up('fieldcontainer'),
                                propertyType = fieldContainer.down('fieldcontainer');
                            propertyType.removeAll(true, true);
                            propertyType.add(this.getContentFieldBasedOnType(value));
                        }.bind(this)
                    },
                    store: propertyTypeStore
                },
                {
                    xtype: 'fieldcontainer',
                    label: false,
                    style: 'margin: 0 10px 0 0',
                    flex: 3,
                    autoWidth: true,
                    items: [
                        field
                    ]
                },
                {
                    xtype: 'button',
                    iconCls: 'pimcore_icon_delete',
                    width: 50,
                    listeners: {
                        click: function (btn) {
                            this.removeFieldCallback.call(this, btn, this.id);
                        }.bind(this)
                    }
                }
            ]
        };
    },

    getContentFieldBasedOnType: function (propertyTypeValue) {

        var lfExtension;

        if (propertyTypeValue === this.fieldTypeProperty) {
            return this.generateTypeField();
        } else if (this.imageAwareTypes.indexOf(propertyTypeValue) !== -1) {
            return this.generateImageField();
        }

        if (this.configuration.useLocalizedFields === false) {
            return this.generateContentField(propertyTypeValue, false, false, null);
        }

        var params = {
            showFieldLabel: true,
            fieldLabel: t('Content'),
            gridWidth: 400,
            editorWindowWidth: 700,
            editorWindowHeight: 300,
            onGridRefreshRequest: function () {
                this.refreshFieldCallback.call(this)
            }.bind(this),
            onGridStoreRequest: this.onLocalizedGridStoreRequest.bind(this),
            onLayoutRequest: this.generateContentField.bind(this, propertyTypeValue, true, true, null)
        };

        lfExtension = new Seo.MetaData.Extension.LocalizedFieldExtension(this.id);

        return lfExtension.generateLocalizedField(params);
    },

    generateTypeField: function () {

        var typeStore = new Ext.data.ArrayStore({
            fields: ['label', 'key'],
            data: this.configuration.hasOwnProperty('types') ? this.configuration.types : []
        });

        return {
            xtype: 'combo',
            name: 'value',
            value: this.getStoredValue('value', null),
            fieldLabel: t('Type'),
            displayField: 'label',
            valueField: 'key',
            labelAlign: 'left',
            queryMode: 'local',
            triggerAction: 'all',
            editable: false,
            allowBlank: true,
            width: 400,
            store: typeStore
        }
    },

    generateImageField: function () {

        var fieldConfig,
            hrefField,
            storagePathHref,
            value = this.getStoredValue('value', null);

        fieldConfig = {
            label: t('Asset Path'),
            id: 'value',
            config: {
                types: ['asset'],
                subtypes: {asset: ['image']}
            }
        };

        hrefField = new Seo.MetaData.Extension.HrefFieldExtension(fieldConfig, value, null);
        storagePathHref = hrefField.getHref();

        storagePathHref.on({
            change: function () {
                this.refreshFieldCallback.call(this);
            }.bind(this)
        });

        return storagePathHref;
    },

    generateContentField: function (type, returnAsArray, isProxy, lfIdentifier, locale) {

        var value = this.getStoredValue('value', locale),
            field = {
                xtype: 'textfield',
                fieldLabel: type,
                width: 400,
                name: 'value',
                value: value,
                enableKeyEvents: true,
                listeners: isProxy ? {} : {
                    keyup: function () {
                        this.refreshFieldCallback.call(this)
                    }.bind(this)
                }
            };

        return returnAsArray ? [field] : field;
    },

    onLocalizedGridStoreRequest: function (lfIdentifier) {
        return [
            {
                title: t('Content'),
                storeIdentifier: 'value',
                onFetchStoredValue: function (locale) {
                    return this.getStoredValue('value', locale);
                }.bind(this)
            }
        ];
    },

    getStoredValue: function (name, locale) {

        this.integratorValueFetcher.setStorageData(this.data);
        this.integratorValueFetcher.setEditData(this.getValues());

        return this.integratorValueFetcher.fetch(name, locale);
    },

    getValues: function () {

        var formValues;

        if (this.form === null) {
            return null;
        }

        formValues = this.form.getForm().getValues();

        if (!formValues.hasOwnProperty('value')) {
            return null;
        }

        if (formValues.value === null || formValues.value === '') {
            return null;
        }

        return formValues;
    },

    getValuesForPreview: function () {

        var locales,
            values = {};

        this.integratorValueFetcher.setStorageData(this.data);
        this.integratorValueFetcher.setEditData(this.getValues());

        locales = Ext.isArray(pimcore.settings.websiteLanguages) ? pimcore.settings.websiteLanguages : ['en'];

        values[this.fieldType] = this.integratorValueFetcher.fetchForPreview(this.fieldType, null);
        values['value'] = this.integratorValueFetcher.fetchForPreview('value', locales[0]);

        return values;
    }
});