const ApiService = Shopware.Classes.ApiService;
const WARNINGS_KEY = 'dh-github-updater-warnings';

/**
 * Zugriff auf die Admin-API-Endpunkte des GitHub-Updaters.
 * Nutzt den Shopware-HTTP-Client, d. h. Token-Handling und Refresh
 * übernimmt die Administration selbst.
 */
class DhGithubUpdaterApiService extends ApiService {
    /** Wird nach „Nach Updates suchen“ ausgelöst – die Plugin-Karten laden dann ihren Status neu */
    static STATUS_EVENT = 'dh-github-updater:status-refreshed';

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

    /** Schritt 1: Shopware-Update ohne Theme-Kompilierung (eigene Anfrage, neuer Code geladen) */
    lifecycleUpdate(pluginName) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/lifecycle/update/${encodeURIComponent(pluginName)}`, {}, {
                headers: this.getBasicHeaders(),
                timeout: 300000,
            })
            .then(ApiService.handleResponse);
    }

    /** Installieren und aktivieren ohne Theme-Kompilierung */
    lifecycleActivate(pluginName) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/lifecycle/activate/${encodeURIComponent(pluginName)}`, {}, {
                headers: this.getBasicHeaders(),
                timeout: 300000,
            })
            .then(ApiService.handleResponse);
    }

    /**
     * Schritt 2: Themes Shop für Shop kompilieren – je eine Anfrage, damit keine in ein Zeitlimit läuft.
     * onProgress(done, total, job) wird vor jedem Shop aufgerufen. Liefert die Shops, bei denen es nicht geklappt hat.
     */
    async compileThemes(jobs, onProgress = () => {}) {
        const failed = [];
        for (let index = 0; index < jobs.length; index += 1) {
            const job = jobs[index];
            onProgress(index, jobs.length, job);
            try {
                await this.httpClient.post(`${this.getApiBasePath()}/compile-theme`, {
                    salesChannelId: job.salesChannelId,
                    themeId: job.themeId,
                }, {
                    headers: this.getBasicHeaders(),
                    timeout: 300000,
                });
            } catch {
                failed.push(job);
            }
        }
        onProgress(jobs.length, jobs.length, null);

        return failed;
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

    getMissingPackages(packages) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/missing-packages`, {
                params: { packages: packages.join(',') },
                headers: this.getBasicHeaders(),
            })
            .then(ApiService.handleResponse);
    }

    /**
     * Hinweise nach einer Installation/Aktualisierung bereinigen: Composer-Pakete erst nachfragen –
     * Shopware lädt sie beim Installieren meist selbst nach. Nur was danach wirklich fehlt, bleibt.
     */
    async confirmWarnings(warnings) {
        const confirmed = [];
        for (const warning of Array.isArray(warnings) ? warnings : []) {
            if (warning.code !== 'missingDependencies') {
                confirmed.push(warning);
                continue;
            }

            try {
                const packages = String(warning.params?.packages || '').split(/\s+/).filter(Boolean);
                const { missing } = await this.getMissingPackages(packages);
                if (missing.length > 0) {
                    confirmed.push({ code: warning.code, params: { packages: missing.join(' ') } });
                }
            } catch {
                confirmed.push(warning);
            }
        }

        return confirmed;
    }

    /**
     * Wartet, bis Shopware im Hintergrund fertig ist (z. B. nach einer Zeitüberschreitung beim
     * Kompilieren der Themes). Liefert true, sobald die Bedingung erfüllt ist.
     */
    static async waitForPlugin(repositoryFactory, pluginName, condition, timeoutMs = 90000, intervalMs = 3000) {
        const repository = repositoryFactory.create('plugin');
        const criteria = new Shopware.Data.Criteria(1, 1);
        criteria.addFilter(Shopware.Data.Criteria.equals('name', pluginName));
        const until = Date.now() + timeoutMs;

        while (Date.now() < until) {
            try {
                const plugin = (await repository.search(criteria)).first();
                if (plugin && condition(plugin)) {
                    return true;
                }
            } catch {
                // Server noch beschäftigt – weiter warten
            }
            await new Promise((resolve) => { window.setTimeout(resolve, intervalMs); });
        }

        return false;
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
        const status = error?.response?.status;

        return error?.response?.data?.message
            || error?.response?.data?.errors?.[0]?.detail
            || (status ? `${fallback} (HTTP ${status})` : null)
            || (error?.code === 'ECONNABORTED' ? `${fallback} (Timeout)` : fallback);
    }
}

Shopware.Service().register('dhGithubUpdaterApiService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new DhGithubUpdaterApiService(initContainer.httpClient, container.loginService);
});

export default DhGithubUpdaterApiService;
