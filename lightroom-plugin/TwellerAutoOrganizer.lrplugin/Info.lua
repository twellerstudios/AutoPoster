return {
    LrSdkVersion        = 10.0,
    LrSdkMinimumVersion = 6.0,
    LrToolkitIdentifier = 'com.twellerstudios.lightroom.autoorganizer',
    LrPluginName        = 'Tweller Auto Organizer',
    LrPluginInfoUrl     = 'https://twellerstudios.com',

    LrInitPlugin        = 'PluginInit.lua',

    LrPluginInfoProvider = 'PluginInfoProvider.lua',

    LrLibraryMenuItems  = {
        {
            title    = 'Organize Imports by Session',
            file     = 'OrganizeMenuItem.lua',
        },
        {
            title    = 'Auto Organizer Settings',
            file     = 'SettingsMenuItem.lua',
        },
    },

    VERSION = { major = 1, minor = 0, revision = 0 },
}
