--[[
    Plugin Info Provider — Shows plugin status in Plugin Manager
]]

local LrView  = import 'LrView'
local LrPrefs = import 'LrPrefs'

local AutoOrganizer = require 'AutoOrganizer'

local infoProvider = {}

function infoProvider.sectionsForTopOfDialog( f, _ )
    local prefs = LrPrefs.prefsForPlugin()

    return {
        {
            title = 'Tweller Auto Organizer',

            f:row {
                f:static_text {
                    title = 'Status:',
                    width = 100,
                    alignment = 'right',
                },
                f:static_text {
                    title = AutoOrganizer.isRunning() and 'Running' or 'Stopped',
                },
            },

            f:row {
                f:static_text {
                    title = 'Watch Folder:',
                    width = 100,
                    alignment = 'right',
                },
                f:static_text {
                    title = (prefs.watchDir and prefs.watchDir ~= '') and prefs.watchDir or '(not set)',
                },
            },

            f:row {
                f:static_text {
                    title = 'Destination:',
                    width = 100,
                    alignment = 'right',
                },
                f:static_text {
                    title = (prefs.destinationDir and prefs.destinationDir ~= '') and prefs.destinationDir or '(not set)',
                },
            },

            f:row {
                f:static_text {
                    title = 'Configure via Library > Plug-in Extras > Auto Organizer Settings',
                },
            },
        },
    }
end

return infoProvider
