import template from './sw-extension-card-base.html.twig';
import './sw-extension-card-base.scss';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';

// Erlaubt „https://github.com/owner/repo“ (optional mit .git oder / am Ende) und „owner/repo“
const REPOSITORY_PATTERN = /^(?:https:\/\/github\.com\/)?[A-Za-z0-9-]{1,39}\/[A-Za-z0-9._-]{1,100}?(?:\.git)?\/?$/;

const STATUS_ICONS = {
    ok: 'regular-checkmark-xs',
    warning: 'regular-exclamation-triangle',
    error: 'regular-times-xs',
    skipped: 'regular-minus-xs',
};

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
                showModal: false,
                isBusy: false,
                isTesting: false,
                testResult: null,
                updates: null,
                selectedKey: null,
            },
        };
    },

    computed: {
        /** Grundvoraussetzung: Plugin (keine App) und passende Rechte */
        dhGithubAllowed() {
            return this.extension?.type === 'plugin'
                && this.acl.can('system.plugin_maintain')
                && this.acl.can('system.plugin_upload')
                && !this.extensionManagementDisabled;
        },

        /** Verknüpfen wird für alle Plugins angeboten – auch für Plugins, die sonst aus dem Store kämen */
        dhGithubCanLink() {
            return this.dhGithubAllowed && this.isInstalled;
        },

        /** Kontextmenü auch dann anzeigen, wenn Shopware selbst keinen Eintrag hätte */
        showContextMenu() {
            return this.$super('showContextMenu')
                || (this.dhGithub.loaded && (this.dhGithubCanLink || !!this.dhGithub.repository));
        },

        dhGithubEditValid() {
            return REPOSITORY_PATTERN.test(this.dhGithub.editRepository.trim());
        },

        dhGithubEditError() {
            const value = this.dhGithub.editRepository.trim();
            if (value === '' || this.dhGithubEditValid) {
                return null;
            }

            return { detail: this.$tc('dh-github-updater.linkModal.invalid') };
        },

        dhGithubTestChecks() {
            return (this.dhGithub.testResult?.checks || []).map((check) => ({
                ...check,
                icon: STATUS_ICONS[check.status] || STATUS_ICONS.skipped,
                label: this.$tc(`dh-github-updater.test.checks.${check.key}`),
            }));
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
            if (!this.dhGithubAllowed) {
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

        dhGithubOpenModal(runTest = false) {
            this.dhGithub.editRepository = this.dhGithub.repository ? `https://github.com/${this.dhGithub.repository}` : '';
            this.dhGithub.testResult = null;
            this.dhGithub.showModal = true;

            if (runTest === true && this.dhGithubEditValid) {
                this.dhGithubTest();
            }
        },

        dhGithubCloseModal() {
            if (this.dhGithub.isBusy) {
                return;
            }

            this.dhGithub.showModal = false;
            this.dhGithub.testResult = null;
        },

        async dhGithubTest() {
            if (!this.dhGithubEditValid) {
                return;
            }

            this.dhGithub.isTesting = true;
            this.dhGithub.testResult = null;

            try {
                this.dhGithub.testResult = await this.dhGithubUpdaterApiService.testConnection(
                    this.dhGithub.editRepository.trim(),
                    this.extension.name,
                );
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isTesting = false;
            }
        },

        async dhGithubSaveLink() {
            if (!this.dhGithubEditValid || this.dhGithub.isBusy) {
                return;
            }

            this.dhGithub.isBusy = true;

            try {
                const result = await this.dhGithubUpdaterApiService.link(this.extension.name, this.dhGithub.editRepository.trim());
                this.dhGithub.repository = result.repository;
                this.dhGithub.updates = null;
                this.dhGithub.showModal = false;
                this.createNotificationSuccess({
                    message: this.$tc('dh-github-updater.card.linkSuccess', 0, { name: this.extension.label, repository: result.repository }),
                });
                await this.dhGithubLoadSource(true);
            } catch (error) {
                this.dhGithubNotifyError(error);
            } finally {
                this.dhGithub.isBusy = false;
            }
        },

        async dhGithubUnlink() {
            this.dhGithub.isBusy = true;

            try {
                await this.dhGithubUpdaterApiService.unlink(this.extension.name);
                this.dhGithub.repository = null;
                this.dhGithub.installedRef = null;
                this.dhGithub.updates = null;
                this.createNotificationInfo({
                    message: this.$tc('dh-github-updater.card.unlinkSuccess', 0, { name: this.extension.label }),
                });
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
