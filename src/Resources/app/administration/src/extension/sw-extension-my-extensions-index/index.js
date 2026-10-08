import template from './sw-extension-my-extensions-index.html.twig';

Shopware.Component.override('sw-extension-my-extensions-index', {
    template,

    inject: ['acl'],

    data() {
        return {
            showDhGithubInstallModal: false,
        };
    },

    computed: {
        dhGithubUpdaterAllowed() {
            return this.acl.can('system.plugin_maintain')
                && this.acl.can('system.plugin_upload')
                && !this.extensionManagementDisabled;
        },
    },
});
