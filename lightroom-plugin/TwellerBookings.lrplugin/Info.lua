return {
    LrSdkVersion        = 10.0,
    LrSdkMinimumVersion = 6.0,
    LrToolkitIdentifier = 'com.twellerstudios.lightroom.bookings',
    LrPluginName        = 'Tweller Bookings - Auto Upload',
    LrPluginInfoUrl     = 'https://twellerstudios.com',

    LrExportServiceProvider = {
        title                = 'Tweller Bookings - Auto Upload',
        file                 = 'TwellerBookingsExport.lua',
        builtInPresetsDir    = 'presets',
    },

    VERSION = { major = 2, minor = 1, revision = 2 },
}
