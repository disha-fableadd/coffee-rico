<?php

namespace App\Services;

use App\Models\Settings;

class MetaConfigService
{
    private $config = null;

    /**
     * Load configuration from database
     */
    public function loadConfig()
    {
        if (!$this->config) {
            $this->config = Settings::first();
        }
        return $this->config;
    }

    /**
     * Refresh configuration
     */
    public function refreshConfig()
    {
        $this->config = null;
        return $this->loadConfig();
    }

    /**
     * Get access token
     */
    public function getAccessToken()
    {
        $config = $this->loadConfig();
        return $config?->access_token;
    }

    /**
     * Get WABA ID
     */
    public function getWabaId()
    {
        $config = $this->loadConfig();
        return $config?->waba_id;
    }

    /**
     * Get phone number ID
     */
    public function getPhoneNumberId()
    {
        $config = $this->loadConfig();
        return $config?->phoneNumberId;
    }
}
