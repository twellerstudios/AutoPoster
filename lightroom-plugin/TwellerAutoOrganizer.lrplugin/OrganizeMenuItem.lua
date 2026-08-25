--[[
    Manual trigger: Library > Plug-in Extras > Organize Imports by Session
    Runs the organizer immediately and shows a summary dialog.
]]

local LrFunctionContext = import 'LrFunctionContext'
local LrDialogs         = import 'LrDialogs'
local LrTasks           = import 'LrTasks'
local LrPrefs           = import 'LrPrefs'

local AutoOrganizer = require 'AutoOrganizer'

LrFunctionContext.callWithContext( 'OrganizeImports', function( context )
    LrTasks.startAsyncTask( function()
        local prefs = LrPrefs.prefsForPlugin()

        if (prefs.watchDir or '') == '' or (prefs.destinationDir or '') == '' then
            LrDialogs.message(
                'Tweller Auto Organizer',
                'Please configure your directories first.\n\n' ..
                'Go to Library > Plug-in Extras > Auto Organizer Settings',
                'warning'
            )
            return
        end

        local moved, sessions = AutoOrganizer.organize( true )

        if moved == 0 then
            LrDialogs.message(
                'Tweller Auto Organizer',
                'No new files found to organize.\n\n' ..
                'Watch folder: ' .. prefs.watchDir,
                'info'
            )
        else
            -- Also create collections
            pcall( function()
                LrTasks.sleep( 2 )
                AutoOrganizer.createSessionCollections()
            end )

            local msg = moved .. ' photos organized into ' .. sessions .. ' session folder(s).\n\n'
            msg = msg .. 'Destination: ' .. prefs.destinationDir .. '\n\n'
            msg = msg .. 'If photos don\'t appear in the Library, use:\n'
            msg = msg .. 'File > Import to add the new session folders.\n\n'
            msg = msg .. 'Or right-click the destination folder in the Folders panel\n'
            msg = msg .. 'and choose "Synchronize Folder..." to detect new files.'

            LrDialogs.message( 'Tweller Auto Organizer', msg, 'info' )
        end
    end )
end )
