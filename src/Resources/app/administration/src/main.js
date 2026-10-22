import './service/dh-github-updater-api.service';
import './component/dh-github-install-modal';
import './component/dh-github-catalog-modal';
import './extension/sw-extension-my-extensions-index';
import './extension/sw-extension-card-base';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
