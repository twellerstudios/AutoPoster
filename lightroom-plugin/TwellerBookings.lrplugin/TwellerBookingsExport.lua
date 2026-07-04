--[[
    Tweller Bookings — Auto Upload plugin for Lightroom Classic

    Exports photos straight from Lightroom to the Tweller Bookings WP
    gallery (no watcher needed). Supports:
      - Picking an existing session (loaded from the website)
      - Creating a session on the fly for last-minute / walk-in shoots
      - Optional local export copy
      - Auto-advancing the session to "delivered" (sends the delivery email)
]]

local LrView          = import 'LrView'
local LrHttp          = import 'LrHttp'
local LrPathUtils     = import 'LrPathUtils'
local LrFileUtils     = import 'LrFileUtils'
local LrDialogs       = import 'LrDialogs'
local LrTasks         = import 'LrTasks'
local LrLogger        = import 'LrLogger'
local LrStringUtils   = import 'LrStringUtils'
local LrColor         = import 'LrColor'

local logger = LrLogger( 'TwellerBookings' )
logger:enable( 'logfile' )

--------------------------------------------------------------------------------
-- Helpers
--------------------------------------------------------------------------------

local function log( msg )
    logger:trace( msg )
end

local function trim( s )
    return s and s:match( "^%s*(.-)%s*$" ) or ""
end

local function urlencode( s )
    if not s then return "" end
    s = tostring( s )
    s = s:gsub( "([^%w%-%.%_%~])", function( c )
        return string.format( "%%%02X", string.byte( c ) )
    end )
    return s
end

--- Minimal JSON string value extractor
local function jsonValue( json, key )
    if not json then return nil end
    return json:match( '"' .. key .. '"%s*:%s*"([^"]*)"' )
end

local function jsonBool( json, key )
    if not json then return false end
    return json:match( '"' .. key .. '"%s*:%s*true' ) ~= nil
end

--- API base for the Tweller Bookings WP plugin
local function apiBase( siteUrl )
    return siteUrl:gsub( "/+$", "" ) .. "/wp-json/tweller-flow-2/v1"
end

--- Fetch recent sessions from WordPress for the session picker
local function fetchSessions( siteUrl, apiKey )
    local url = apiBase( siteUrl ) .. "/automation/sessions?range=recent&api_key=" .. urlencode( apiKey )
    local body = LrHttp.get( url )

    if not body or body == "" then
        log( "fetchSessions: empty response from " .. url )
        return {}
    end

    log( "fetchSessions response: " .. body:sub( 1, 200 ) )

    local sessions = {}

    -- Parse JSON array of sessions
    -- Looking for objects like: {"id":"31","tracking_code":"BC8E62","client_name":"John Doe",...}
    local in_string = false
    local depth = 0
    local obj_start = 1

    for i = 1, #body do
        local char = body:sub( i, i )

        if char == '"' and ( i == 1 or body:sub( i - 1, i - 1 ) ~= '\\' ) then
            in_string = not in_string
        elseif not in_string then
            if char == '{' then
                if depth == 0 then obj_start = i end
                depth = depth + 1
            elseif char == '}' then
                depth = depth - 1
                if depth == 0 then
                    local obj_str = body:sub( obj_start, i )
                    local code = jsonValue( obj_str, 'tracking_code' )
                    local name = jsonValue( obj_str, 'client_name' )
                    local stage = jsonValue( obj_str, 'current_stage' )
                    local date = jsonValue( obj_str, 'session_date' )
                    if code and name then
                        sessions[#sessions + 1] = {
                            title = name .. " — " .. ( date or "no date" ) .. "  [" .. ( stage or "" ) .. "]",
                            value = code,
                        }
                        log( "Parsed session: " .. code .. " - " .. name )
                    end
                end
            end
        end
    end

    log( "fetchSessions found " .. #sessions .. " sessions" )
    return sessions
end

--------------------------------------------------------------------------------
-- Export Service Provider
--------------------------------------------------------------------------------

local exportServiceProvider = {}

exportServiceProvider.supportsIncrementalPublish = false
exportServiceProvider.hideSections               = { 'exportLocation' }
exportServiceProvider.allowFileFormats           = { 'JPEG' }
exportServiceProvider.allowColorSpaces           = { 'sRGB' }
exportServiceProvider.hidePrintResolution        = true
exportServiceProvider.canExportVideo             = false

exportServiceProvider.exportPresetFields = {
    { key = 'siteUrl',         default = 'https://twellerstudios.com' },
    { key = 'apiKey',          default = '' },
    { key = 'sessionCode',     default = '' },
    { key = 'localExportDir',  default = '' },
    { key = 'uploadToSite',    default = true },
    { key = 'advanceStage',    default = true },
    { key = 'galleryPassword', default = '' },
    -- New-session fields (for last-minute bookings)
    { key = 'newClientName',   default = '' },
    { key = 'newClientEmail',  default = '' },
    { key = 'newSessionDate',  default = '' },
    { key = 'newPackage',      default = 'mini' },
}

function exportServiceProvider.sectionsForTopOfDialog( f, propertyTable )
    local bind = LrView.bind

    if propertyTable.sessionItems == nil then
        propertyTable.sessionItems = { { title = 'Click "Load Sessions" to fetch from website', value = '' } }
    end
    if propertyTable.statusText == nil then
        propertyTable.statusText = ''
    end

    return {
        -- Connection Settings
        {
            title   = 'Tweller Bookings Connection',
            synopsis = function( props )
                if props.siteUrl and props.siteUrl ~= '' then
                    return props.siteUrl
                end
                return 'Not configured'
            end,

            f:row {
                f:static_text { title = 'Website URL:', width = 120, alignment = 'right' },
                f:edit_field {
                    value          = bind 'siteUrl',
                    width_in_chars = 40,
                    tooltip        = 'Your WordPress site URL (e.g., https://twellerstudios.com)',
                },
            },
            f:row {
                f:static_text { title = 'API Key:', width = 120, alignment = 'right' },
                f:password_field {
                    value          = bind 'apiKey',
                    width_in_chars = 40,
                    tooltip        = 'Automation API key from Tweller Bookings settings in WordPress',
                },
            },
        },

        -- Session selection
        {
            title = 'Session',
            synopsis = function( props )
                if props.sessionCode and props.sessionCode ~= '' then
                    return props.sessionCode
                end
                return 'No session selected'
            end,

            f:row {
                f:static_text { title = 'Shoot Code:', width = 120, alignment = 'right' },
                f:edit_field {
                    value          = bind 'sessionCode',
                    width_in_chars = 34,
                    tooltip        = 'The session shoot code (e.g., 04-July-2024-JohnDoe-Mini)',
                },
            },
            f:row {
                f:static_text { title = '', width = 120 },
                f:popup_menu {
                    value = bind 'sessionCode',
                    items = bind 'sessionItems',
                    width_in_chars = 30,
                    tooltip = 'Pick from recent sessions on the website',
                },
                f:push_button {
                    title  = 'Load Sessions',
                    action = function()
                        LrTasks.startAsyncTask( function()
                            propertyTable.statusText = 'Loading sessions...'
                            local ok, sessions = pcall( fetchSessions, trim( propertyTable.siteUrl ), trim( propertyTable.apiKey ) )
                            if ok and #sessions > 0 then
                                propertyTable.sessionItems = sessions
                                propertyTable.statusText = #sessions .. ' session(s) loaded — pick one from the menu'
                            else
                                propertyTable.statusText = 'No sessions found. Check URL / API key.'
                            end
                        end )
                    end,
                },
            },

            f:separator { fill_horizontal = 1 },

            f:row {
                f:static_text { title = 'New session:', width = 120, alignment = 'right' },
                f:static_text { title = 'For last-minute shoots not booked on the website yet.', text_color = LrColor( 0.5, 0.5, 0.5 ) },
            },
            f:row {
                f:static_text { title = 'Client Name:', width = 120, alignment = 'right' },
                f:edit_field { value = bind 'newClientName', width_in_chars = 24 },
            },
            f:row {
                f:static_text { title = 'Client Email:', width = 120, alignment = 'right' },
                f:edit_field { value = bind 'newClientEmail', width_in_chars = 24, tooltip = 'Optional — needed for the delivery email' },
            },
            f:row {
                f:static_text { title = 'Date:', width = 120, alignment = 'right' },
                f:edit_field { value = bind 'newSessionDate', width_in_chars = 12, tooltip = 'YYYY-MM-DD (leave blank for today)' },
                f:static_text { title = 'Package:' },
                f:popup_menu {
                    value = bind 'newPackage',
                    items = {
                        { title = 'Mini Session',     value = 'mini' },
                        { title = 'Full Session',     value = 'full' },
                        { title = 'Extended Session', value = 'extended' },
                        { title = 'Event 1hr',        value = 'event_1hr' },
                        { title = 'Event 2hr',        value = 'event_2hr' },
                        { title = 'Event 3hr',        value = 'event_3hr' },
                        { title = 'Event 4hr',        value = 'event_4hr' },
                    },
                },
            },
            f:row {
                f:static_text { title = '', width = 120 },
                f:push_button {
                    title  = 'Create / Find Session',
                    action = function()
                        LrTasks.startAsyncTask( function()
                            local siteUrl = trim( propertyTable.siteUrl )
                            local apiKey  = trim( propertyTable.apiKey )
                            local name    = trim( propertyTable.newClientName )
                            if name == '' then
                                propertyTable.statusText = 'Enter a client name first.'
                                return
                            end
                            propertyTable.statusText = 'Creating session...'
                            local url = apiBase( siteUrl ) .. "/automation/create-session"
                                .. "?client_name=" .. urlencode( name )
                                .. "&client_email=" .. urlencode( trim( propertyTable.newClientEmail ) )
                                .. "&session_date=" .. urlencode( trim( propertyTable.newSessionDate ) )
                                .. "&package_type=" .. urlencode( propertyTable.newPackage or 'mini' )
                                .. "&api_key=" .. urlencode( apiKey )
                            local body = LrHttp.get( url )
                            if body and jsonBool( body, 'ok' ) then
                                local code = jsonValue( body, 'tracking_code' )
                                propertyTable.sessionCode = code or ''
                                if jsonBool( body, 'existing' ) then
                                    propertyTable.statusText = 'Found existing session: ' .. tostring( code )
                                else
                                    propertyTable.statusText = 'Session created: ' .. tostring( code )
                                end
                            else
                                propertyTable.statusText = 'Could not create session. Check URL / API key.'
                                log( 'create-session failed: ' .. tostring( body ) )
                            end
                        end )
                    end,
                },
            },
            f:row {
                f:static_text { title = '', width = 120 },
                f:static_text { title = bind 'statusText', width_in_chars = 50 },
            },
        },

        -- Export Options
        {
            title   = 'Gallery Upload Options',
            synopsis = function( props )
                local parts = {}
                if props.localExportDir and props.localExportDir ~= '' then
                    parts[#parts + 1] = 'Local'
                end
                if props.uploadToSite then
                    parts[#parts + 1] = 'Website'
                end
                return table.concat( parts, ' + ' )
            end,

            f:row {
                f:static_text { title = 'Local Folder:', width = 120, alignment = 'right' },
                f:edit_field {
                    value          = bind 'localExportDir',
                    width_in_chars = 35,
                    tooltip        = 'Local folder to also save exported photos (leave blank to skip)',
                },
                f:push_button {
                    title  = 'Browse...',
                    action = function()
                        local dir = LrDialogs.runOpenPanel( {
                            title                   = 'Choose Export Folder',
                            canChooseFiles          = false,
                            canChooseDirectories    = true,
                            allowsMultipleSelection = false,
                        } )
                        if dir then
                            propertyTable.localExportDir = dir[1]
                        end
                    end,
                },
            },
            f:row {
                f:static_text { title = '', width = 120 },
                f:checkbox {
                    value = bind 'uploadToSite',
                    title = 'Upload photos to website gallery',
                },
            },
            f:row {
                f:static_text { title = '', width = 120 },
                f:checkbox {
                    value = bind 'advanceStage',
                    title = 'Mark session "delivered" when done (sends the delivery email to the client)',
                },
            },
            f:row {
                f:static_text { title = 'Gallery Password:', width = 120, alignment = 'right' },
                f:edit_field {
                    value          = bind 'galleryPassword',
                    width_in_chars = 20,
                    tooltip        = 'Optional password to protect the client gallery (leave blank for none)',
                },
            },
        },
    }
end

--------------------------------------------------------------------------------
-- Export process
--------------------------------------------------------------------------------

function exportServiceProvider.processRenderedPhotos( functionContext, exportContext )
    local exportSession = exportContext.exportSession
    local propertyTable = exportContext.propertyTable
    local nPhotos       = exportSession:countRenditions()

    local siteUrl         = trim( propertyTable.siteUrl )
    local apiKey          = trim( propertyTable.apiKey )
    local sessionCode     = trim( propertyTable.sessionCode )
    local localDir        = trim( propertyTable.localExportDir )
    local uploadToSite    = propertyTable.uploadToSite
    local advanceStage    = propertyTable.advanceStage
    local galleryPassword = trim( propertyTable.galleryPassword )

    -- Validate
    if sessionCode == "" then
        LrDialogs.message( "Tweller Bookings", "Please pick a session or create one first (Session section).", "critical" )
        return
    end

    if siteUrl == "" and uploadToSite then
        LrDialogs.message( "Tweller Bookings", "Please enter your website URL to upload photos.", "critical" )
        return
    end

    local base = apiBase( siteUrl )

    -- Create local export subfolder: localDir/SESSION_CODE/
    local localSessionDir = nil
    if localDir ~= "" then
        localSessionDir = LrPathUtils.child( localDir, sessionCode )
        LrFileUtils.createAllDirectories( localSessionDir )
    end

    local progressScope = exportContext:configureProgress {
        title = "Tweller Bookings: Exporting " .. nPhotos .. " photos",
    }

    local uploadedCount = 0
    local failedCount   = 0
    local photoIndex    = 0

    for i, rendition in exportContext:renditions { stopIfCanceled = true } do
        progressScope:setPortionComplete( photoIndex, nPhotos )
        photoIndex = photoIndex + 1

        local success, pathOrMessage = rendition:waitForRender()

        if not success then
            log( "Render failed: " .. tostring( pathOrMessage ) )
            failedCount = failedCount + 1
        else
            local renderedPath = pathOrMessage
            local fileName     = LrPathUtils.leafName( renderedPath )

            -- 1. Copy to local folder
            if localSessionDir then
                local destPath = LrPathUtils.child( localSessionDir, fileName )
                local ok, err = pcall( function()
                    LrFileUtils.copy( renderedPath, destPath )
                end )
                if not ok then
                    log( "Local save failed: " .. tostring( err ) )
                end
            end

            -- 2. Upload to WordPress
            if uploadToSite and siteUrl ~= "" then
                progressScope:setCaption( "Uploading " .. fileName .. " (" .. photoIndex .. "/" .. nPhotos .. ")" )

                local uploadUrl = base .. "/photo-upload"

                local fileContents = nil
                local fh = io.open( renderedPath, "rb" )
                if fh then
                    fileContents = fh:read( "*all" )
                    fh:close()
                end

                if fileContents then
                    local boundary = "----TwellerBookings" .. tostring( math.random( 100000, 999999 ) )
                    local body = ""

                    body = body .. "--" .. boundary .. "\r\n"
                    body = body .. 'Content-Disposition: form-data; name="session_code"\r\n\r\n'
                    body = body .. sessionCode .. "\r\n"

                    -- API key in body (Authorization header may be stripped by hosts)
                    if apiKey ~= "" then
                        body = body .. "--" .. boundary .. "\r\n"
                        body = body .. 'Content-Disposition: form-data; name="api_key"\r\n\r\n'
                        body = body .. apiKey .. "\r\n"
                    end

                    -- Gallery password (only on first photo)
                    if photoIndex == 1 and galleryPassword ~= "" then
                        body = body .. "--" .. boundary .. "\r\n"
                        body = body .. 'Content-Disposition: form-data; name="gallery_password"\r\n\r\n'
                        body = body .. galleryPassword .. "\r\n"
                    end

                    body = body .. "--" .. boundary .. "\r\n"
                    body = body .. 'Content-Disposition: form-data; name="photo"; filename="' .. fileName .. '"\r\n'
                    body = body .. "Content-Type: image/jpeg\r\n\r\n"
                    body = body .. fileContents .. "\r\n"

                    body = body .. "--" .. boundary .. "--\r\n"

                    local headers = {
                        { field = "Content-Type",  value = "multipart/form-data; boundary=" .. boundary },
                        { field = "Authorization", value = "Bearer " .. apiKey },
                    }

                    local respBody = LrHttp.post( uploadUrl, body, headers )

                    if respBody and jsonBool( respBody, "ok" ) then
                        uploadedCount = uploadedCount + 1
                        log( "Uploaded: " .. fileName )
                    else
                        failedCount = failedCount + 1
                        log( "Upload failed for " .. fileName .. ": " .. tostring( respBody ) )
                    end
                else
                    failedCount = failedCount + 1
                    log( "Could not read file: " .. renderedPath )
                end
            end

            LrFileUtils.delete( renderedPath )
        end
    end

    -- Advance stage to delivered (this triggers the delivery email in WP)
    local deliveredMsg = ""
    if advanceStage and uploadToSite and uploadedCount > 0 and siteUrl ~= "" then
        progressScope:setCaption( "Marking session delivered..." )

        local advanceUrl = base .. "/automation/advance"
            .. "?session_code=" .. urlencode( sessionCode )
            .. "&target_stage=delivered"
            .. "&notes=" .. urlencode( uploadedCount .. " photos delivered via Lightroom" )
            .. "&photo_count=" .. uploadedCount
            .. "&api_key=" .. urlencode( apiKey )

        local body = LrHttp.get( advanceUrl )
        if body and jsonBool( body, "ok" ) then
            if jsonBool( body, "notified" ) then
                deliveredMsg = "\n\nSession marked delivered — delivery email sent to the client."
            else
                deliveredMsg = "\n\nSession marked delivered. (No email sent — the session has no client email.)"
            end
        else
            deliveredMsg = "\n\nWarning: could not mark session delivered: " .. tostring( body )
            log( "Stage advance warning: " .. tostring( body ) )
        end
    end

    -- Summary dialog
    local summary = uploadedCount .. " of " .. nPhotos .. " photos exported successfully."
    if localSessionDir then
        summary = summary .. "\n\nLocal: " .. localSessionDir
    end
    if uploadToSite then
        summary = summary .. "\nUploaded to: " .. siteUrl .. " (session " .. sessionCode .. ")"
    end
    if failedCount > 0 then
        summary = summary .. "\n\n" .. failedCount .. " photo(s) failed."
    end
    summary = summary .. deliveredMsg

    LrDialogs.message( "Tweller Bookings Export Complete", summary, "info" )
end

return exportServiceProvider
