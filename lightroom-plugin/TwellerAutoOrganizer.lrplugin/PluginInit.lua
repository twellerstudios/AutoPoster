--[[
    Tweller Auto Organizer — Plugin Initialization
    Starts the background watcher that replaces Lightroom's built-in Auto Import.
    Watches LR-AUTO-IMPORT folder, organizes files into session subfolders,
    then imports them into the catalog with proper folder structure.
]]

local LrTasks         = import 'LrTasks'
local LrLogger        = import 'LrLogger'
local LrPrefs         = import 'LrPrefs'

local logger = LrLogger( 'TwellerAutoOrganizer' )
logger:enable( 'logfile' )

local AutoOrganizer = require 'AutoOrganizer'

-- Set defaults if first run
local prefs = LrPrefs.prefsForPlugin()
if not prefs.watchDir then
    prefs.watchDir = ''
end
if not prefs.destinationDir then
    prefs.destinationDir = ''
end
if not prefs.pollSeconds then
    prefs.pollSeconds = 10
end
if not prefs.enabled then
    prefs.enabled = false
end
if prefs.greenOnly == nil then
    prefs.greenOnly = true
end

-- Start background watcher
LrTasks.startAsyncTask( function()
    logger:trace( 'Tweller Auto Organizer plugin loaded' )

    if prefs.enabled and prefs.watchDir ~= '' and prefs.destinationDir ~= '' then
        logger:trace( 'Starting background watcher...' )
        logger:trace( '  Watch dir: ' .. prefs.watchDir )
        logger:trace( '  Destination: ' .. prefs.destinationDir )
        AutoOrganizer.startWatcher()
    else
        logger:trace( 'Auto organizer disabled or paths not configured. Use Library > Plug-in Extras > Auto Organizer Settings to configure.' )
    end
end )
