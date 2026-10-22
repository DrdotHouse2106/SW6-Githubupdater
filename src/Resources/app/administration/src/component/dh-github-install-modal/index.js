import template from './dh-github-install-modal.html.twig';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';
import './dh-github-install-modal.scss';

const { Component, Mixin } = Shopware;

Component.register('dh-github-install-modal', {
    template,

    inject: ['dhGithubUpdaterApiService', 'shopwareExtensionService', 'cacheApiService'],

    emits: ['modal-close'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            repository: '',
            versions: [],
            selectedKey: null,
            branchesAllowed: false,
            loadedRepository: null,
            isLoadingVersions: false,
            isInstalling: false,
            activateAfterInstall: true,
        };
    },

    computed: {
        versionOptions() {
            return this.versions.map((version) => ({
                value: `${version.type}:${version.ref}`,
                label: version.label,
            }));
        },

        selectedVersion() {
            return this.versions.find((version) => `${version.type}:${version.ref}` === this.selectedKey) || null;
        },

        canInstall() {
            return this.selectedVersion !== null && !this.isInstalling && !this.isLoadingVersions;
        },
    },

    methods: {
        async loadVersions() {
            if (!this.repository.trim()) {
                return;
            }

            this.isLoadingVersions = true;
            this.versions = [];
            this.selectedKey = null;
            this.loadedRepository = null;

            try {
                const result = await this.dhGithubUpdaterApiService.getVersions(this.repository.trim());
                this.versions = result.versions;
                this.branchesAllowed = result.branchesAllowed;
                this.loadedRepository = result.repository;
                this.selectedKey = this.versionOptions[0]?.value ?? null;
            } catch (error) {
                this.createNotificationError({
                    message: DhGithubUpdaterApiService.getErrorMessage(error, this.$tc('dh-github-updater.general.unknownError')),
                });
            } finally {
                this.isLoadingVersions = false;
            }
        },

        async install() {
            if (!this.canInstall) {
                return;
            }

            this.isInstalling = true;
            const version = this.selectedVersion;

            try {
                const result = await this.dhGithubUpdaterApiService.install(this.loadedRepository, version.ref, version.type);

                if (this.activateAfterInstall) {
                    await this.shopwareExtensionService.installAndActivateExtension(result.pluginName, 'plugin');
                }

                this.createNotificationSuccess({
                    message: this.$t('dh-github-updater.install.success', { name: result.pluginName }),
                });

                await this.cacheApiService.clear();
                window.location.reload();
            } catch (error) {
                this.createNotificationError({
                    message: DhGithubUpdaterApiService.getErrorMessage(error, this.$tc('dh-github-updater.general.unknownError')),
                });
                this.isInstalling = false;
            }
        },
    },
});
