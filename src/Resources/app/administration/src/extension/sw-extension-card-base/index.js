import template from './sw-extension-card-base.html.twig';
import './sw-extension-card-base.scss';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';

Shopware.Component.override('sw-extension-card-base', {
    template,

    inject: ['acl', 'dhGithubUpdaterApiService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    data() {
        return {
            dhGithub: {
                loaded: false,
                repository: null,
                installedRef: null,
                editRepository: '',
                isEditing: false,
                isBusy: false,
                updates: null,
                selectedKey: null,
            },
        };
    },

    computed: {
        dhGithubVisible() {
            return this.extension?.type === 'plugin'
                && this.acl.can('system.plugin_maintain')
                && this.acl.can('system.plugin_upload')
                && !this.extensionManagementDisabled;
        },

        dhGithubUpdateOptions() {
            return (this.dhGithub.updates || []).map((update) => ({
                value: `${update.type}:${update.ref}`,
                label: update.label,
            }));
        },

        dhGithubSelectedUpdate() {
            return (this.dhGithub.updates || [])
                .find((update) => `${update.type}:${update.ref}` === this.dhGithub.selectedKey) || null;
        },
    },

    created() {
        this.dhGithubLoadSource();
    },

    methods: {
        async dhGithubLoadSource(force = false) {
            if (!this.dhGithubVisible) {
                return;
            }

            try {
                const result = await this.dhGithubUpdaterApiService.getSources(force);
                const source = result.sources.find((entry) => entry.pluginName === this.extension.name);
                this.dhGithub.repository = source?.repository ?? null;
                this.dhGithub.installedRef = source?.installedRef ?? null;
            } catch {
                this.dhGithub.repository = null;
            } finally {
                this.dhGithub.loaded = true;
            }
        },

        dhGithubStartEdit() {
            this.dhGithub.editRepository = this.dhGithub.repository ? `https://github.com/${this.dhGithub.repository}` : '';
            this.dhGithub.isEditing = true;
        },

        async dhGithubSaveLink() {
            this.dhGithub.isBusy = true;

            try {
                const result = await this.dhGithubUpdaterApiService.link(this.extension.name, this.dhGithub.editRepository.trim());
                this.dhGithub.repository = result.repository;
                this.dhGithub.updates = null;
                this.dhGithub.isEditing = false;
                await this.dhGithubLoadSource(true);
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isBusy = false;
            }
        },

        async dhGithubCheck() {
            this.dhGithub.isBusy = true;

            try {
                const result = await this.dhGithubUpdaterApiService.check(this.extension.name);
                this.dhGithub.updates = result.updates;
                this.dhGithub.selectedKey = this.dhGithubUpdateOptions[0]?.value ?? null;

                if (result.updates.length === 0) {
                    this.createNotificationInfo({
                        message: this.$tc('dh-github-updater.card.upToDate', 0, { name: this.extension.label }),
                    });
                }
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isBusy = false;
            }
        },

        async dhGithubUpdate() {
            const update = this.dhGithubSelectedUpdate;
            if (!update) {
                return;
            }

            this.dhGithub.isBusy = true;

            try {
                const result = await this.dhGithubUpdaterApiService.update(this.extension.name, update.ref, update.type);

                // Der eigentliche Plugin-Update-Lebenszyklus (Migrationen etc.) läuft
                // über den regulären Shopware-Endpunkt in einem neuen Request mit dem neuen Code.
                if (result.installed) {
                    await this.shopwareExtensionService.updateExtension(this.extension.name, 'plugin', false);
                }

                this.createNotificationSuccess({
                    message: this.$tc('dh-github-updater.card.updateSuccess', 0, { name: this.extension.label }),
                });

                this.clearCacheAndReloadPage();
            } catch (error) {
                this.dhGithubNotifyError(error);
                this.dhGithub.isBusy = false;
            }
        },

        dhGithubNotifyError(error) {
            this.createNotificationError({
                message: DhGithubUpdaterApiService.getErrorMessage(error, this.$tc('dh-github-updater.general.unknownError')),
            });
        },
    },
});
