--[[
    Tweller Auto Organizer — Core Logic

    Replaces Lightroom's built-in Auto Import with session-aware organization.

    Flow:
    1. Watcher copies Imagen-edited files to LR-AUTO-IMPORT (flat)
    2. Watcher writes .session-tracking.json mapping filenames → session names
    3. This plugin watches LR-AUTO-IMPORT for new files
    4. Reads tracking data to determine which session each file belongs to
    5. Creates {destination}/{session} To Export/ folders on disk
    6. Moves files from LR-AUTO-IMPORT → organized destination
    7. Adds the organized folders to the LR catalog

    The user DISABLES Lightroom's built-in Auto Import and uses this instead.
]]

local LrApplication     = import 'LrApplication'
local LrTasks           = import 'LrTasks'
local LrFileUtils       = import 'LrFileUtils'
local LrPathUtils       = import 'LrPathUtils'
local LrDialogs         = import 'LrDialogs'
local LrLogger          = import 'LrLogger'
local LrPrefs           = import 'LrPrefs'
local LrStringUtils     = import 'LrStringUtils'
local LrFunctionContext = import 'LrFunctionContext'
local LrProgressScope   = import 'LrProgressScope'

local logger = LrLogger( 'TwellerAutoOrganizer' )
logger:enable( 'logfile' )

local AutoOrganizer = {}

--------------------------------------------------------------------------------
-- Helpers
--------------------------------------------------------------------------------

local RAW_EXTENSIONS = {
    cr2 = true, cr3 = true, nef = true, arw = true, dng = true,
    orf = true, rw2 = true, raf = true, tif = true, tiff = true,
    jpg = true, jpeg = true, png = true,
}

local function log( msg )
    logger:trace( msg )
end

local function isPhotoFile( filename )
    local ext = LrStringUtils.lower( LrPathUtils.extension( filename ) or '' )
    return RAW_EXTENSIONS[ext] == true
end

local function isXmpFile( filename )
    local ext = LrStringUtils.lower( LrPathUtils.extension( filename ) or '' )
    return ext == 'xmp'
end

local function isSupportedFile( filename )
    return isPhotoFile( filename ) or isXmpFile( filename )
end

--- Read and parse .session-tracking.json from the watch directory.
--- Returns a table: { sessionName = { "file1.cr3", "file2.cr3", ... }, ... }
local function readSessionTracking( watchDir )
    local trackingPath = LrPathUtils.child( watchDir, '.session-tracking.json' )

    if not LrFileUtils.exists( trackingPath ) then
        return nil
    end

    local fh = io.open( trackingPath, 'r' )
    if not fh then return nil end

    local content = fh:read( '*all' )
    fh:close()

    if not content or content == '' then return nil end

    -- Minimal JSON parser for our simple structure:
    -- { "sessionName": ["file1.cr3", "file2.cr3"], ... }
    local tracking = {}

    for sessionName, fileList in content:gmatch( '"([^"]+)"%s*:%s*(%b[])' ) do
        local files = {}
        for fileName in fileList:gmatch( '"([^"]+)"' ) do
            files[#files + 1] = fileName
        end
        tracking[sessionName] = files
    end

    return tracking
end

--- Build a reverse lookup: filename (without ext) → sessionName
local function buildFileToSessionMap( tracking )
    local map = {}
    for sessionName, files in pairs( tracking ) do
        for _, fileName in ipairs( files ) do
            -- Store both full filename and base name (without extension)
            map[fileName] = sessionName
            local baseName = LrPathUtils.removeExtension( fileName )
            map[baseName] = sessionName
        end
    end
    return map
end

--- Get all files in a directory (non-recursive)
local function getFilesInDir( dirPath )
    local files = {}
    for filePath in LrFileUtils.files( dirPath ) do
        local name = LrPathUtils.leafName( filePath )
        if isSupportedFile( name ) then
            files[#files + 1] = {
                path = filePath,
                name = name,
                baseName = LrPathUtils.removeExtension( name ),
            }
        end
    end
    return files
end

--- Ensure a directory exists, creating it if necessary
local function ensureDir( dirPath )
    if not LrFileUtils.exists( dirPath ) then
        LrFileUtils.createAllDirectories( dirPath )
        log( 'Created directory: ' .. dirPath )
    end
end

--- Clean up the tracking file after files have been moved
local function cleanupTracking( watchDir, movedFiles )
    local trackingPath = LrPathUtils.child( watchDir, '.session-tracking.json' )
    if not LrFileUtils.exists( trackingPath ) then return end

    local tracking = readSessionTracking( watchDir )
    if not tracking then return end

    -- Remove moved files from tracking
    local movedSet = {}
    for _, name in ipairs( movedFiles ) do
        movedSet[name] = true
        movedSet[LrPathUtils.removeExtension( name )] = true
    end

    local updated = {}
    local hasEntries = false

    for sessionName, files in pairs( tracking ) do
        local remaining = {}
        for _, f in ipairs( files ) do
            if not movedSet[f] and not movedSet[LrPathUtils.removeExtension( f )] then
                remaining[#remaining + 1] = f
            end
        end
        if #remaining > 0 then
            updated[sessionName] = remaining
            hasEntries = true
        end
    end

    -- Write updated tracking or delete if empty
    if not hasEntries then
        LrFileUtils.delete( trackingPath )
        log( 'Cleaned up tracking file (all files processed)' )
    else
        local fh = io.open( trackingPath, 'w' )
        if fh then
            fh:write( '{\n' )
            local first = true
            for sessionName, files in pairs( updated ) do
                if not first then fh:write( ',\n' ) end
                fh:write( '  "' .. sessionName .. '": [' )
                for i, f in ipairs( files ) do
                    if i > 1 then fh:write( ', ' ) end
                    fh:write( '"' .. f .. '"' )
                end
                fh:write( ']' )
                first = false
            end
            fh:write( '\n}\n' )
            fh:close()
        end
    end
end

--------------------------------------------------------------------------------
-- Core: Organize files from watch dir into session folders
--------------------------------------------------------------------------------

function AutoOrganizer.organize( showProgress )
    local prefs = LrPrefs.prefsForPlugin()
    local watchDir = prefs.watchDir or ''
    local destDir = prefs.destinationDir or ''

    if watchDir == '' or destDir == '' then
        if showProgress then
            LrDialogs.message(
                'Tweller Auto Organizer',
                'Please configure watch and destination directories first.\n\n' ..
                'Go to Library > Plug-in Extras > Auto Organizer Settings',
                'warning'
            )
        end
        return 0, 0
    end

    if not LrFileUtils.exists( watchDir ) then
        log( 'Watch directory does not exist: ' .. watchDir )
        return 0, 0
    end

    -- Read session tracking data from watcher
    local tracking = readSessionTracking( watchDir )
    if not tracking then
        log( 'No session tracking data found' )
        return 0, 0
    end

    local fileMap = buildFileToSessionMap( tracking )

    -- Get files waiting in the watch directory
    local pendingFiles = getFilesInDir( watchDir )
    if #pendingFiles == 0 then
        return 0, 0
    end

    log( 'Found ' .. #pendingFiles .. ' files to organize' )

    -- Group files by session
    local sessionGroups = {}  -- sessionName → { file info, ... }
    local unmatchedFiles = {}

    for _, fileInfo in ipairs( pendingFiles ) do
        local sessionName = fileMap[fileInfo.name] or fileMap[fileInfo.baseName]
        if sessionName then
            if not sessionGroups[sessionName] then
                sessionGroups[sessionName] = {}
            end
            local group = sessionGroups[sessionName]
            group[#group + 1] = fileInfo
        else
            unmatchedFiles[#unmatchedFiles + 1] = fileInfo
        end
    end

    -- Move files into organized session folders
    ensureDir( destDir )

    local totalMoved = 0
    local sessionCount = 0
    local movedFileNames = {}
    local organizedFolders = {}

    for sessionName, files in pairs( sessionGroups ) do
        local sessionFolder = LrPathUtils.child( destDir, sessionName .. ' To Export' )
        ensureDir( sessionFolder )
        organizedFolders[#organizedFolders + 1] = sessionFolder

        for _, fileInfo in ipairs( files ) do
            local destPath = LrPathUtils.child( sessionFolder, fileInfo.name )

            -- Handle filename conflicts
            if LrFileUtils.exists( destPath ) then
                destPath = LrFileUtils.chooseUniqueFileName( destPath )
            end

            local ok, err = pcall( function()
                LrFileUtils.move( fileInfo.path, destPath )
            end )

            if ok then
                totalMoved = totalMoved + 1
                movedFileNames[#movedFileNames + 1] = fileInfo.name
                log( 'Moved: ' .. fileInfo.name .. ' → ' .. sessionName .. ' To Export/' )
            else
                log( 'ERROR moving ' .. fileInfo.name .. ': ' .. tostring( err ) )
            end
        end

        sessionCount = sessionCount + 1
        log( 'Session "' .. sessionName .. ' To Export": ' .. #files .. ' files' )
    end

    -- Move unmatched files to an _unmatched folder
    if #unmatchedFiles > 0 then
        local unmatchedDir = LrPathUtils.child( destDir, '_unmatched' )
        ensureDir( unmatchedDir )

        for _, fileInfo in ipairs( unmatchedFiles ) do
            local destPath = LrPathUtils.child( unmatchedDir, fileInfo.name )
            if LrFileUtils.exists( destPath ) then
                destPath = LrFileUtils.chooseUniqueFileName( destPath )
            end

            pcall( function()
                LrFileUtils.move( fileInfo.path, destPath )
            end )
            movedFileNames[#movedFileNames + 1] = fileInfo.name
        end
        log( #unmatchedFiles .. ' unmatched files moved to _unmatched/' )
    end

    -- Clean up tracking file
    if #movedFileNames > 0 then
        cleanupTracking( watchDir, movedFileNames )
    end

    -- Import the organized folders into Lightroom's catalog
    if totalMoved > 0 then
        AutoOrganizer.importOrganizedFolders( organizedFolders )
    end

    return totalMoved, sessionCount
end

--------------------------------------------------------------------------------
-- Import organized folders into the LR catalog
--------------------------------------------------------------------------------

function AutoOrganizer.importOrganizedFolders( folderPaths )
    local catalog = LrApplication.activeCatalog()

    for _, folderPath in ipairs( folderPaths ) do
        -- Collect all photo files in the folder
        local photoPaths = {}
        for filePath in LrFileUtils.files( folderPath ) do
            local name = LrPathUtils.leafName( filePath )
            if isPhotoFile( name ) then
                photoPaths[#photoPaths + 1] = filePath
            end
        end

        if #photoPaths == 0 then
            log( 'No photos to import from: ' .. folderPath )
        else
            log( 'Importing ' .. #photoPaths .. ' photos from: ' .. folderPath )

            -- Try catalog:addPhotos (available in newer LR SDK versions)
            local importOk = pcall( function()
                catalog:withWriteAccessDo( 'Import session photos', function()
                    for _, photoPath in ipairs( photoPaths ) do
                        catalog:addPhoto( photoPath )
                    end
                end )
            end )

            if importOk then
                log( 'Successfully imported ' .. #photoPaths .. ' photos into catalog' )
            else
                -- Fallback: try adding just the folder so LR knows about it
                local folderOk = pcall( function()
                    catalog:withWriteAccessDo( 'Add folder to catalog', function()
                        catalog:addFolder( folderPath )
                    end )
                end )

                if folderOk then
                    log( 'Added folder to catalog: ' .. folderPath )
                    log( 'Photos will appear after synchronizing the folder in LR' )
                else
                    -- Final fallback: just log — user will manually import
                    log( 'Auto-import not available. Photos are organized at: ' .. folderPath )
                    log( 'User should use File > Import in Lightroom to add them.' )
                end
            end
        end
    end
end

--------------------------------------------------------------------------------
-- Create collections for organized sessions
--------------------------------------------------------------------------------

function AutoOrganizer.createSessionCollections()
    local prefs = LrPrefs.prefsForPlugin()
    local destDir = prefs.destinationDir or ''

    if destDir == '' or not LrFileUtils.exists( destDir ) then return end

    local catalog = LrApplication.activeCatalog()
    local collectionSetName = 'Sessions To Export'

    catalog:withWriteAccessDo( 'Create session collections', function()
        -- Find or create the parent collection set
        local collectionSet = nil
        for _, cs in ipairs( catalog:getChildCollectionSets() ) do
            if cs:getName() == collectionSetName then
                collectionSet = cs
                break
            end
        end

        if not collectionSet then
            collectionSet = catalog:createCollectionSet( collectionSetName, nil, true )
            log( 'Created collection set: ' .. collectionSetName )
        end

        if not collectionSet then
            log( 'ERROR: Could not create collection set' )
            return
        end

        -- Scan destination for session folders
        for entry in LrFileUtils.directoryEntries( destDir ) do
            local entryPath = entry
            local entryName = LrPathUtils.leafName( entryPath )

            -- Only process folders ending in "To Export"
            if LrFileUtils.exists( entryPath )
               and entryName:match( 'To Export$' )
               and LrFileUtils.isReadable( entryPath ) then

                -- Check if it's a directory by looking for children
                local hasFiles = false
                pcall( function()
                    for _ in LrFileUtils.files( entryPath ) do
                        hasFiles = true
                        break
                    end
                end )

                if hasFiles then
                    -- Find or create collection
                    local collection = nil
                    for _, c in ipairs( collectionSet:getChildCollections() ) do
                        if c:getName() == entryName then
                            collection = c
                            break
                        end
                    end

                    if not collection then
                        collection = catalog:createCollection( entryName, collectionSet, true )
                        log( 'Created collection: ' .. entryName )
                    end

                    if collection then
                        -- Find photos in catalog that match files in this folder
                        local photosToAdd = {}
                        for filePath in LrFileUtils.files( entryPath ) do
                            if isPhotoFile( LrPathUtils.leafName( filePath ) ) then
                                -- Try to find this photo in the catalog
                                local photo = catalog:findPhotoByPath( filePath )
                                if photo then
                                    -- Optionally filter by green label
                                    local addIt = true
                                    if prefs.greenOnly then
                                        local label = photo:getRawMetadata( 'colorNameForLabel' )
                                        addIt = ( label == 'green' )
                                    end
                                    if addIt then
                                        photosToAdd[#photosToAdd + 1] = photo
                                    end
                                end
                            end
                        end

                        if #photosToAdd > 0 then
                            collection:addPhotos( photosToAdd )
                            log( 'Added ' .. #photosToAdd .. ' photos to collection: ' .. entryName )
                        end
                    end
                end
            end
        end
    end )
end

--------------------------------------------------------------------------------
-- Background watcher loop
--------------------------------------------------------------------------------

local watcherRunning = false

function AutoOrganizer.startWatcher()
    if watcherRunning then
        log( 'Watcher already running' )
        return
    end

    watcherRunning = true
    local prefs = LrPrefs.prefsForPlugin()

    LrTasks.startAsyncTask( function()
        log( 'Background watcher started' )

        while watcherRunning do
            -- Re-read prefs each cycle in case user changes settings
            local enabled = prefs.enabled
            local watchDir = prefs.watchDir or ''
            local destDir = prefs.destinationDir or ''
            local pollSeconds = prefs.pollSeconds or 10

            if enabled and watchDir ~= '' and destDir ~= '' then
                local ok, err = pcall( function()
                    local moved, sessions = AutoOrganizer.organize( false )
                    if moved > 0 then
                        log( 'Organized ' .. moved .. ' files into ' .. sessions .. ' session folders' )

                        -- Also create/update collections after organizing
                        LrTasks.sleep( 2 )  -- Give catalog a moment to process
                        pcall( function()
                            AutoOrganizer.createSessionCollections()
                        end )
                    end
                end )

                if not ok then
                    log( 'Watcher error: ' .. tostring( err ) )
                end
            end

            LrTasks.sleep( pollSeconds )
        end

        log( 'Background watcher stopped' )
    end )
end

function AutoOrganizer.stopWatcher()
    watcherRunning = false
    log( 'Watcher stop requested' )
end

function AutoOrganizer.isRunning()
    return watcherRunning
end

return AutoOrganizer
