import template from './dh-github-catalog-modal.html.twig';
import DhGithubUpdaterApiService from '../../service/dh-github-updater-api.service';
import './dh-github-catalog-modal.scss';

const { Component, Mixin } = Shopware;

export const CONTRIBUTE_URL = 'https://github.com/DrdotHouse2106/SW6-Nova-Updater/issues/new?template=plugin-vorschlagen.yml';
export const CONTRIBUTING_DOC_URL = 'https://github.com/DrdotHouse2106/SW6-Nova-Updater/blob/main/CONTRIBUTING.md';

/**
 * Plugin-Katalog: kuratierte Liste von Shopware-Plugins auf GitHub –
 * installieren, verknüpfen oder auf GitHub ansehen.
 */
Component.register('dh-github-catalog-modal', {
    template,

    inject: ['dhGithubUpdaterApiService'],

    emits: ['modal-close', 'install'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: true,
            busyPlugin: null,
            source: null,
            plugins: [],
            categories: [],
            term: '',
            category: null,
            contributeUrl: CONTRIBUTE_URL,
            contributingDocUrl: CONTRIBUTING_DOC_URL,
        };
    },

    computed: {
        locale() {
            // 6.7: Pinia-Store, 6.6: Vuex-Modul
            for (const getStore of [() => Shopware.Store?.get?.('session'), () => Shopware.State?.get?.('session')]) {
                try {
                    const locale = getStore()?.currentLocale;
                    if (locale) {
                        return locale;
                    }
                } catch {
                    // Store in dieser Version nicht vorhanden
                }
            }

            return 'de-DE';
        },

        categoryOptions() {
            return this.categories
                .filter((category) => this.plugins.some((plugin) => plugin.category === category))
                .map((category) => ({
                    value: category,
                    label: this.$tc(`dh-github-updater.catalog.categories.${category}`),
                    count: this.plugins.filter((plugin) => plugin.category === category).length,
                }));
        },

        filteredPlugins() {
            const term = this.term.trim().toLowerCase();

            return this.plugins.filter((plugin) => {
                if (this.category && plugin.category !== this.category) {
                    return false;
                }

                if (!term) {
                    return true;
                }

                return [plugin.name, plugin.vendor, plugin.pluginName, plugin.repository, this.description(plugin)]
                    .some((value) => (value || '').toLowerCase().includes(term));
            });
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load(refresh = false) {
            this.isLoading = true;

            try {
                const result = await this.dhGithubUpdaterApiService.getCatalog(refresh);
                this.plugins = result.plugins;
                this.categories = result.categories;
                this.source = result.source;
            } catch (error) {
                this.createNotificationError({
                    message: DhGithubUpdaterApiService.getErrorMessage(error, this.$tc('dh-github-updater.general.unknownError')),
                });
            } finally {
                this.isLoading = false;
            }
        },

        description(plugin) {
            const texts = plugin.description || {};

            return texts[this.locale] || texts['de-DE'] || texts['en-GB'] || Object.values(texts)[0] || '';
        },

        /**
         * installable | link | linked | linkedOther | notAllowed
         */
        status(plugin) {
            if (!plugin.allowed) {
                return 'notAllowed';
            }

            if (!plugin.plugin) {
                return 'installable';
            }

            if (!plugin.linkedRepository) {
                return 'link';
            }

            return plugin.linkedRepository.toLowerCase() === plugin.repository.toLowerCase() ? 'linked' : 'linkedOther';
        },

        async link(plugin) {
            this.busyPlugin = plugin.pluginName;

            try {
                const result = await this.dhGithubUpdaterApiService.link(plugin.pluginName, plugin.repository);
                plugin.linkedRepository = result.repository;
                this.createNotificationSuccess({
                    message: this.$t('dh-github-updater.card.linkSuccess', { name: plugin.name, repository: result.repository }),
                });
            } catch (error) {
                this.createNotificationError({
                    message: DhGithubUpdaterApiService.getErrorMessage(error, this.$tc('dh-github-updater.general.unknownError')),
                });
            } finally {
                this.busyPlugin = null;
            }
        },
    },
});
