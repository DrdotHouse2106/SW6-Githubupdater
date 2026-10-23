import template from './sw-extension-my-extensions-index.html.twig';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';

Shopware.Component.override('sw-extension-my-extensions-index', {
    template,

    inject: ['acl', 'repositoryFactory'],

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
        // Hinweise aus einer Installation/Aktualisierung vor dem Neuladen der Seite.
        // Kurz warten: Direkt nach dem Neuladen ist die Sprache der Administration evtl. noch nicht gesetzt.
        const stored = DhGithubUpdaterApiService.takeWarnings();
        if (stored.length > 0) {
            window.setTimeout(() => this.dhGithubShowStoredWarnings(stored), 1500);
        }
    },

    methods: {
        async dhGithubShowStoredWarnings(stored) {
            for (const { pluginName, warnings } of stored) {
                const plugin = await this.dhGithubFindPlugin(pluginName);

                for (const warning of warnings) {
                    // Zeitüberschreitung beim Aktivieren, Shopware hat aber im Hintergrund fertig installiert
                    if (warning.code === 'activationFailed' && plugin?.active) {
                        this.createNotificationSuccess({
                            title: pluginName,
                            message: this.$t('dh-github-updater.warnings.activatedAfterTimeout', { name: pluginName }),
                        });
                        continue;
                    }

                    const notify = warning.code === 'missingDependencies' ? this.createNotificationInfo : this.createNotificationWarning;
                    notify.call(this, {
                        title: pluginName,
                        message: this.$t(`dh-github-updater.warnings.${warning.code}`, warning.params || {}),
                        autoClose: false,
                    });
                }
            }
        },

        async dhGithubFindPlugin(pluginName) {
            try {
                const criteria = new Shopware.Data.Criteria(1, 1);
                criteria.addFilter(Shopware.Data.Criteria.equals('name', pluginName));
                const result = await this.repositoryFactory.create('plugin').search(criteria);

                return result.first();
            } catch {
                return null;
            }
        },

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
