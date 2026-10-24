import template from './sw-extension-my-extensions-index.html.twig';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';

Shopware.Component.override('sw-extension-my-extensions-index', {
    template,

    inject: ['acl', 'repositoryFactory', 'dhGithubUpdaterApiService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            showDhGithubInstallModal: false,
            showDhGithubCatalogModal: false,
            dhGithubInstallRepository: '',
            dhGithubCheckingAll: false,
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
        /**
         * Alle verknüpften Plugins sofort auf Updates prüfen und die Karten aktualisieren.
         */
        async dhGithubCheckAll() {
            this.dhGithubCheckingAll = true;
            try {
                this.dhGithubUpdaterApiService.resetCaches();
                // Quellen zuerst: stellt umbenannte Repositories laut Katalog auf den neuen Namen um
                await this.dhGithubUpdaterApiService.getSources(true);
                const status = await this.dhGithubUpdaterApiService.getUpdateStatus(true);
                const entries = Object.entries(status.plugins || {});
                const updates = entries.filter(([, entry]) => !entry.error && (entry.updates || []).length > 0);
                const failed = entries.filter(([, entry]) => entry.error);

                window.dispatchEvent(new CustomEvent(DhGithubUpdaterApiService.STATUS_EVENT));

                if (updates.length > 0) {
                    this.createNotificationInfo({
                        title: this.$tc('dh-github-updater.checkAll.updatesTitle', updates.length, { count: updates.length }),
                        message: updates.map(([name, entry]) => `${name} → ${entry.latestVersion || entry.updates[0]?.label || ''}`).join(', '),
                        autoClose: false,
                    });
                } else {
                    this.createNotificationSuccess({
                        message: this.$tc('dh-github-updater.checkAll.upToDate', entries.length, { count: entries.length }),
                    });
                }

                if (failed.length > 0) {
                    this.createNotificationWarning({
                        title: this.$tc('dh-github-updater.checkAll.failedTitle', failed.length, { count: failed.length }),
                        message: failed.map(([name, entry]) => `${name}: ${entry.error}`).join(' · '),
                        autoClose: false,
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.errors?.[0]?.detail || error?.response?.data?.message || this.$tc('dh-github-updater.checkAll.error'),
                });
            } finally {
                this.dhGithubCheckingAll = false;
            }
        },

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
