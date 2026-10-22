import template from './sw-extension-my-extensions-index.html.twig';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';

Shopware.Component.override('sw-extension-my-extensions-index', {
    template,

    inject: ['acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            showDhGithubInstallModal: false,
            showDhGithubCatalogModal: false,
            dhGithubInstallRepository: '',
        };
    },

    computed: {
        dhGithubUpdaterAllowed() {
            return this.acl.can('system.plugin_maintain')
                && this.acl.can('system.plugin_upload')
                && !this.extensionManagementDisabled;
        },
    },

    created() {
        // Hinweise aus einer Installation/Aktualisierung vor dem Neuladen der Seite
        DhGithubUpdaterApiService.takeWarnings().forEach(({ pluginName, warnings }) => {
            warnings.forEach((warning) => {
                this.createNotificationWarning({
                    title: pluginName,
                    message: this.$t(`dh-github-updater.warnings.${warning.code}`, warning.params || {}),
                    autoClose: false,
                });
            });
        });
    },

    methods: {
        dhGithubOpenInstall(repository = '') {
            this.dhGithubInstallRepository = repository;
            this.showDhGithubCatalogModal = false;
            this.showDhGithubInstallModal = true;
        },

        dhGithubInstallFromCatalog(plugin) {
            this.dhGithubOpenInstall(plugin.repository);
        },
    },
});
