const ApiService = Shopware.Classes.ApiService;
const WARNINGS_KEY = 'dh-github-updater-warnings';

/**
 * Zugriff auf die Admin-API-Endpunkte des GitHub-Updaters.
 * Nutzt den Shopware-HTTP-Client, d. h. Token-Handling und Refresh
 * übernimmt die Administration selbst.
 */
class DhGithubUpdaterApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/dh-github-updater') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'dhGithubUpdaterApiService';
        this.sourcesPromise = null;
        this.updateStatusPromise = null;
    }

    resetCaches() {
        this.sourcesPromise = null;
        this.updateStatusPromise = null;
    }

    getCatalog(refresh = false) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/catalog`, {
                params: refresh ? { refresh: 1 } : {},
                headers: this.getBasicHeaders(),
                timeout: 60000,
            })
            .then(ApiService.handleResponse);
    }

    /**
     * Ergebnis der automatischen Update-Prüfung – wird von allen Plugin-Karten geteilt.
     */
    getUpdateStatus(refresh = false) {
        if (refresh || this.updateStatusPromise === null) {
            this.updateStatusPromise = this.httpClient
                .get(`${this.getApiBasePath()}/update-status`, {
                    params: refresh ? { refresh: 1 } : {},
                    headers: this.getBasicHeaders(),
                    timeout: 120000,
                })
                .then(ApiService.handleResponse)
                .catch((error) => {
                    this.updateStatusPromise = null;
                    throw error;
                });
        }

        return this.updateStatusPromise;
    }

    getVersions(repository) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/versions`, {
                params: { repository },
                headers: this.getBasicHeaders(),
            })
            .then(ApiService.handleResponse);
    }

    getSources(force = false) {
        if (force || this.sourcesPromise === null) {
            this.sourcesPromise = this.httpClient
                .get(`${this.getApiBasePath()}/sources`, { headers: this.getBasicHeaders() })
                .then(ApiService.handleResponse)
                .catch((error) => {
                    this.sourcesPromise = null;
                    throw error;
                });
        }

        return this.sourcesPromise;
    }

    check(pluginName) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/check/${encodeURIComponent(pluginName)}`, {
                headers: this.getBasicHeaders(),
            })
            .then(ApiService.handleResponse);
    }

    install(repository, ref, type) {
        this.resetCaches();

        return this.httpClient
            .post(`${this.getApiBasePath()}/install`, { repository, ref, type }, {
                headers: this.getBasicHeaders(),
                timeout: 300000,
            })
            .then(ApiService.handleResponse);
    }

    update(pluginName, ref, type) {
        this.resetCaches();

        return this.httpClient
            .post(`${this.getApiBasePath()}/update/${encodeURIComponent(pluginName)}`, { ref, type }, {
                headers: this.getBasicHeaders(),
                timeout: 300000,
            })
            .then(ApiService.handleResponse);
    }

    link(pluginName, repository) {
        this.resetCaches();

        return this.httpClient
            .post(`${this.getApiBasePath()}/link/${encodeURIComponent(pluginName)}`, { repository }, {
                headers: this.getBasicHeaders(),
            })
            .then(ApiService.handleResponse);
    }

    testConnection(repository, pluginName = null) {
        const params = { repository };
        if (pluginName) {
            params.pluginName = pluginName;
        }

        return this.httpClient
            .get(`${this.getApiBasePath()}/test-connection`, {
                params,
                headers: this.getBasicHeaders(),
                timeout: 60000,
            })
            .then(ApiService.handleResponse);
    }

    unlink(pluginName) {
        return this.link(pluginName, '');
    }

    /**
     * Hinweise zum installierten Paket überleben das Neuladen der Seite
     * und werden danach in „Meine Erweiterungen“ angezeigt.
     */
    static rememberWarnings(pluginName, warnings) {
        if (!Array.isArray(warnings) || warnings.length === 0) {
            return;
        }

        try {
            const stored = JSON.parse(window.sessionStorage.getItem(WARNINGS_KEY) || '[]');
            stored.push({ pluginName, warnings });
            window.sessionStorage.setItem(WARNINGS_KEY, JSON.stringify(stored));
        } catch {
            // sessionStorage nicht verfügbar – Hinweise entfallen
        }
    }

    static takeWarnings() {
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(WARNINGS_KEY) || '[]');
            window.sessionStorage.removeItem(WARNINGS_KEY);

            return Array.isArray(stored) ? stored : [];
        } catch {
            return [];
        }
    }

    static getErrorMessage(error, fallback) {
        return error?.response?.data?.message
            || error?.response?.data?.errors?.[0]?.detail
            || fallback;
    }
}

Shopware.Service().register('dhGithubUpdaterApiService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new DhGithubUpdaterApiService(initContainer.httpClient, container.loginService);
});

export default DhGithubUpdaterApiService;
