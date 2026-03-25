--[[
    Settings dialog: Library > Plug-in Extras > Auto Organizer Settings
    Configure watch directory, destination, and polling interval.
]]

local LrFunctionContext = import 'LrFunctionContext'
local LrDialogs         = import 'LrDialogs'
local LrView            = import 'LrView'
local LrPrefs           = import 'LrPrefs'
local LrBinding         = import 'LrBinding'
local LrTasks           = import 'LrTasks'

local AutoOrganizer = require 'AutoOrganizer'

LrFunctionContext.callWithContext( 'AutoOrganizerSettings', function( context )
    local prefs = LrPrefs.prefsForPlugin()
    local f = LrView.osFactory()

    -- Create observable properties bound to prefs
    local props = LrBinding.makePropertyTable( context )
    props.watchDir       = prefs.watchDir or ''
    props.destinationDir = prefs.destinationDir or ''
    props.pollSeconds    = prefs.pollSeconds or 10
    props.enabled        = prefs.enabled or false
    props.greenOnly      = prefs.greenOnly ~= false

    local contents = f:column {
        spacing = f:control_spacing(),
        fill_horizontal = 1,

        f:group_box {
            title = 'Directories',
            fill_horizontal = 1,

            f:row {
                f:static_text {
                    title = 'Watch Folder (LR-AUTO-IMPORT):',
                    width = 220,
                    alignment = 'right',
                },
                f:edit_field {
                    value = LrView.bind 'watchDir',
                    width_in_chars = 45,
                    tooltip = 'The folder where the watcher copies Imagen-edited files.\nExample: T:\\TWELLER STUDIOS\\PHOTOGRAPHY\\LR-AUTO-IMPORT',
                },
                f:push_button {
                    title = 'Browse...',
                    action = function()
                        local initial = props.watchDir ~= '' and props.watchDir or nil
                        local dir = LrDialogs.runOpenPanel {
                            title = 'Select Watch Folder (LR-AUTO-IMPORT)',
                            canChooseFiles = true,
                            canChooseDirectories = true,
                            allowsMultipleSelection = false,
                            initialDirectory = initial,
                        }
                        if dir then props.watchDir = dir[1] end
                    end,
                },
            },

            f:row {
                f:static_text {
                    title = 'Destination (organized output):',
                    width = 220,
                    alignment = 'right',
                },
                f:edit_field {
                    value = LrView.bind 'destinationDir',
                    width_in_chars = 45,
                    tooltip = 'Where session folders will be created.\nExample: T:\\TWELLER STUDIOS\\PHOTOGRAPHY\\EDITED CULLED AUTO IMPORTS',
                },
                f:push_button {
                    title = 'Browse...',
                    action = function()
                        local initial = props.destinationDir ~= '' and props.destinationDir or nil
                        local dir = LrDialogs.runOpenPanel {
                            title = 'Select Destination Folder',
                            canChooseFiles = true,
                            canChooseDirectories = true,
                            allowsMultipleSelection = false,
                            initialDirectory = initial,
                        }
                        if dir then props.destinationDir = dir[1] end
                    end,
                },
            },
        },

        f:group_box {
            title = 'Options',
            fill_horizontal = 1,

            f:row {
                f:static_text {
                    title = 'Poll interval (seconds):',
                    width = 220,
                    alignment = 'right',
                },
                f:edit_field {
                    value = LrView.bind 'pollSeconds',
                    width_in_chars = 5,
                    min = 5,
                    max = 300,
                    tooltip = 'How often to check for new files (5-300 seconds)',
                },
            },

            f:row {
                f:static_text { title = '', width = 220 },
                f:checkbox {
                    value = LrView.bind 'greenOnly',
                    title = 'Only add green-labeled photos to collections',
                },
            },

            f:row {
                f:static_text { title = '', width = 220 },
                f:checkbox {
                    value = LrView.bind 'enabled',
                    title = 'Enable background auto-organizer',
                },
            },
        },

        f:group_box {
            title = 'How It Works',
            fill_horizontal = 1,

            f:static_text {
                title = '1. DISABLE Lightroom\'s built-in Auto Import (File > Auto Import > uncheck Enable)\n' ..
                        '2. The watcher copies Imagen-edited files to the Watch Folder\n' ..
                        '3. This plugin automatically moves them into "{session} To Export" subfolders\n' ..
                        '4. Files are imported into your catalog organized by session\n' ..
                        '5. Collections are created: "Sessions To Export > {session} To Export"\n\n' ..
                        'You can also manually trigger: Library > Plug-in Extras > Organize Imports by Session',
                width = 520,
                height_in_lines = 7,
            },
        },

        f:row {
            f:static_text {
                title = 'Status: ' .. (AutoOrganizer.isRunning() and 'RUNNING' or 'STOPPED'),
                text_color = AutoOrganizer.isRunning() and LrView.kGreenColor or LrView.kRedColor,
            },
        },
    }

    local result = LrDialogs.presentModalDialog {
        title = 'Tweller Auto Organizer Settings',
        contents = contents,
        actionVerb = 'Save',
    }

    if result == 'ok' then
        -- Save settings
        prefs.watchDir       = props.watchDir
        prefs.destinationDir = props.destinationDir
        prefs.pollSeconds    = tonumber( props.pollSeconds ) or 10
        prefs.enabled        = props.enabled
        prefs.greenOnly      = props.greenOnly

        -- Start or stop watcher based on new settings
        if prefs.enabled and prefs.watchDir ~= '' and prefs.destinationDir ~= '' then
            if not AutoOrganizer.isRunning() then
                LrTasks.startAsyncTask( function()
                    AutoOrganizer.startWatcher()
                end )
            end
            LrDialogs.message(
                'Tweller Auto Organizer',
                'Settings saved. Background organizer is ENABLED.\n\n' ..
                'Remember to DISABLE Lightroom\'s built-in Auto Import\n' ..
                '(File > Auto Import > uncheck Enable Auto Import)',
                'info'
            )
        else
            AutoOrganizer.stopWatcher()
            LrDialogs.message(
                'Tweller Auto Organizer',
                'Settings saved. Background organizer is DISABLED.\n\n' ..
                'You can still use Library > Plug-in Extras > Organize Imports by Session\n' ..
                'to manually organize files.',
                'info'
            )
        end
    end
end )
