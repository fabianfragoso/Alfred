<?php
/*
 * Alfred server settings — TEMPLATE.
 * Copy this file to your home folder on the server as:  ~/alfred-config.php
 * (outside the website folder) and fill in the keys you have.
 * Never put the real file in GitHub.
 */
return [
    // Access code you will type once in Alfred's Settings on each device.
    // Stops strangers from using your voice services. Make it long.
    'passcode' => 'CHANGE-ME',

    // ElevenLabs — elevenlabs.io → Profile → API Keys
    'elevenlabs_key' => '',

    // OpenAI — platform.openai.com → API keys (needs credit)
    'openai_key' => '',

    // Microsoft Azure Speech — portal.azure.com → Speech service → Keys and Endpoint
    'azure_key' => '',
    'azure_region' => '',   // e.g. canadacentral, eastus

    // Microsoft 365 email — from your app registration in entra.microsoft.com
    'ms_tenant_id' => '',      // Directory (tenant) ID
    'ms_client_id' => '',      // Application (client) ID
    'ms_client_secret' => '',  // Client secret VALUE (not the secret ID)
];
