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
local LrPathUtils       = import 'LrPathUtils'
local LrFileUtils       = import 'LrFileUtils'

local AutoOrganizer = require 'AutoOrganizer'

--- Pick a folder using the OS dialog. On Windows the native folder picker
--- can sometimes appear empty; if the user cancels, fall back to prompting
--- them to paste the path manually.
local function pickFolder( title, currentPath )
    local initial = ( currentPath and currentPath ~= '' ) and currentPath or nil

    -- Try the native folder picker first (canChooseFiles=false gives "Select Folder" on Windows)
    local dir = LrDialogs.runOpenPanel {
        title = title,
        prompt = 'Select Folder',
        canChooseFiles = false,
        canChooseDirectories = true,
        allowsMultipleSelection = false,
        initialDirectory = initial,
    }

    if dir then return dir[1] end

    -- User cancelled — offer to paste the path manually
    local result = LrDialogs.confirm(
        title,
        'If the folder browser appeared empty, you can paste the full path directly ' ..
        'into the text field in the settings dialog.\n\n' ..
        'Example: T:\\TWELLER STUDIOS\\PHOTOGRAPHY\\LR-AUTO-IMPORT',
        'OK'
    )

    return nil
end

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

            f:static_text {
                title = 'Tip: You can paste folder paths directly into the text fields below.',
                text_color = LrView.kTextColorForInfoLabel,
            },

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
                        local picked = pickFolder(
                            'Select Watch Folder (LR-AUTO-IMPORT)',
                            props.watchDir
                        )
                        if picked then props.watchDir = picked end
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
                        local picked = pickFolder(
                            'Select Destination Folder',
                            props.destinationDir
                        )
                        if picked then props.destinationDir = picked end
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
        -- Validate paths before saving
        local watchOk = props.watchDir ~= '' and LrFileUtils.exists( props.watchDir )
        local destOk  = props.destinationDir ~= '' and LrFileUtils.exists( props.destinationDir )

        if props.enabled and ( not watchOk or not destOk ) then
            local missing = {}
            if not watchOk then missing[#missing + 1] = 'Watch Folder' end
            if not destOk  then missing[#missing + 1] = 'Destination'  end

            LrDialogs.message(
                'Tweller Auto Organizer',
                'The following directories do not exist:\n  • ' ..
                table.concat( missing, '\n  • ' ) ..
                '\n\nPlease check the paths and try again.\n' ..
                'Tip: Copy the path from Windows Explorer\'s address bar and paste it into the text field.',
                'warning'
            )
            return
        end

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
