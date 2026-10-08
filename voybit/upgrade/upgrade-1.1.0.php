<?php
/**
 * Upgrade legacy direct-payment settings to hosted checkout sessions.
 *
 * @license GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Voybit $module
 *
 * @return bool
 */
function upgrade_module_1_1_0($module)
{
    if (!VoybitStorage::upgrade()) {
        return false;
    }
    if (VoybitApi::normalizeBase((string) Configuration::get('VOYBIT_API_BASE')) === '') {
        Configuration::updateValue('VOYBIT_API_BASE', VoybitApi::DEFAULT_API_BASE);
    }
    Configuration::deleteByName('VOYBIT_ASSET_ID');

    if ($module->apiKey() !== '') {
        $module->configureIntegration(false);
    }

    return true;
}
