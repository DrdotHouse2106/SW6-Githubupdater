const ApiService = Shopware.Classes.ApiService;

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
        this.sourcesPromise = null;

        return this.httpClient
            .post(`${this.getApiBasePath()}/install`, { repository, ref, type }, {
                headers: this.getBasicHeaders(),
                timeout: 300000,
            })
            .then(ApiService.handleResponse);
    }

    update(pluginName, ref, type) {
        this.sourcesPromise = null;

        return this.httpClient
            .post(`${this.getApiBasePath()}/update/${encodeURIComponent(pluginName)}`, { ref, type }, {
                headers: this.getBasicHeaders(),
                timeout: 300000,
            })
            .then(ApiService.handleResponse);
    }

    link(pluginName, repository) {
        this.sourcesPromise = null;

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
