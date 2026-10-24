import template from './dh-github-changelog-modal.html.twig';
import './dh-github-changelog-modal.scss';

const { Component } = Shopware;

/**
 * „Was ist neu?“ – Release-Notizen eines oder mehrerer Plugins.
 * Vor einem Update (von der installierten bis zur gewählten Version) und nach einem Update
 * (von der alten bis zur neuen Version).
 */
Component.register('dh-github-changelog-modal', {
    template,

    inject: ['dhGithubUpdaterApiService'],

    emits: ['modal-close', 'update'],

    props: {
        // [{ pluginName, label, from, to }]
        entries: {
            type: Array,
            required: true,
        },
        // „before“ zeigt zusätzlich den Button „Jetzt aktualisieren“
        mode: {
            type: String,
            required: false,
            default: 'after',
        },
    },

    data() {
        return {
            isLoading: true,
            sections: [],
        };
    },

    computed: {
        title() {
            if (this.mode === 'before') {
                return this.$tc('dh-github-updater.changelog.titleBefore');
            }

            return this.$tc('dh-github-updater.changelog.titleAfter');
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;
            this.sections = await Promise.all(this.entries.map(async (entry) => {
                try {
                    const result = await this.dhGithubUpdaterApiService.getChangelog(entry.pluginName, entry.from, entry.to);

                    return { ...entry, releases: result.releases || [], error: null };
                } catch {
                    return { ...entry, releases: [], error: this.$tc('dh-github-updater.changelog.loadError') };
                }
            }));
            this.isLoading = false;
        },

        date(value) {
            return value ? new Date(value).toLocaleDateString() : '';
        },

        /**
         * Kleines, sicheres Markdown: erst alles maskieren, dann Listen, Überschriften,
         * **fett**, `Code` und https-Links umsetzen.
         */
        render(markdown) {
            const escape = (text) => text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
            const inline = (text) => escape(text)
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

            const html = [];
            let list = false;
            (markdown || '').replace(/\r/g, '').split('\n').forEach((line) => {
                const item = line.match(/^\s*[-*]\s+(.*)$/);
                if (item) {
                    if (!list) {
                        html.push('<ul>');
                        list = true;
                    }
                    html.push(`<li>${inline(item[1])}</li>`);

                    return;
                }
                if (list) {
                    html.push('</ul>');
                    list = false;
                }

                const heading = line.match(/^#{1,6}\s+(.*)$/);
                if (heading) {
                    html.push(`<p><strong>${inline(heading[1])}</strong></p>`);
                } else if (line.trim() !== '') {
                    html.push(`<p>${inline(line)}</p>`);
                }
            });
            if (list) {
                html.push('</ul>');
            }

            return html.join('');
        },
    },
});
