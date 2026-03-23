--[[
    Tweller Flow Export Plugin for Lightroom Classic
    Exports photos to a local folder AND uploads them to the WordPress gallery.
]]

local LrView          = import 'LrView'
local LrHttp          = import 'LrHttp'
local LrPathUtils     = import 'LrPathUtils'
local LrFileUtils     = import 'LrFileUtils'
local LrDialogs       = import 'LrDialogs'
local LrFunctionContext = import 'LrFunctionContext'
local LrTasks         = import 'LrTasks'
local LrLogger        = import 'LrLogger'
local LrStringUtils   = import 'LrStringUtils'
local LrBinding       = import 'LrBinding'
local LrApplication   = import 'LrApplication'

local logger = LrLogger( 'TwellerFlow' )
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

--- Simple JSON encoder for flat tables
local function jsonEncode( tbl )
    local parts = {}
    for k, v in pairs( tbl ) do
        local val
        if type( v ) == "number" then
            val = tostring( v )
        elseif type( v ) == "boolean" then
            val = v and "true" or "false"
        else
            val = '"' .. tostring( v ):gsub( '"', '\\"' ) .. '"'
        end
        parts[#parts + 1] = '"' .. tostring( k ) .. '":' .. val
    end
    return "{" .. table.concat( parts, "," ) .. "}"
end

--- Minimal JSON string value extractor
local function jsonValue( json, key )
    if not json then return nil end
    local pattern = '"' .. key .. '"%s*:%s*"([^"]*)"'
    return json:match( pattern )
end

local function jsonBool( json, key )
    if not json then return false end
    local pattern = '"' .. key .. '"%s*:%s*(true)'
    return json:match( pattern ) ~= nil
end

--- Fetch sessions from WordPress for the session picker
local function fetchSessions( siteUrl, apiKey )
    local url = siteUrl .. "/wp-json/tweller-flow/v1/automation/sessions"
    local headers = {}
    if apiKey and apiKey ~= "" then
        headers[#headers + 1] = { field = "Authorization", value = "Bearer " .. apiKey }
    end

    local body, respHeaders = LrHttp.get( url, headers )
    if not body then return {} end

    -- Parse JSON array of sessions (simple pattern matching)
    local sessions = {}
    for item in body:gmatch( "%{(.-)%}" ) do
        local code = item:match( '"tracking_code"%s*:%s*"([^"]*)"' )
        local name = item:match( '"client_name"%s*:%s*"([^"]*)"' )
        local stage = item:match( '"current_stage"%s*:%s*"([^"]*)"' )
        local date = item:match( '"session_date"%s*:%s*"([^"]*)"' )
        if code and name then
            sessions[#sessions + 1] = {
                code  = code,
                name  = name,
                stage = stage or "",
                date  = date or "",
                title = code .. " - " .. name .. " (" .. (date or "no date") .. ") [" .. (stage or "") .. "]",
            }
        end
    end
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
    { key = 'siteUrl',        default = 'https://twellerstudios.com' },
    { key = 'apiKey',         default = '' },
    { key = 'sessionCode',    default = '' },
    { key = 'localExportDir', default = '' },
    { key = 'uploadToSite',   default = true },
    { key = 'advanceStage',   default = true },
    { key = 'galleryPassword', default = '' },
    { key = 'jpegQuality',    default = 92 },
}

function exportServiceProvider.sectionsForTopOfDialog( f, propertyTable )
    local siteUrl = propertyTable.siteUrl or ''
    local apiKey  = propertyTable.apiKey or ''
    local bind    = LrView.bind

    return {
        -- Connection Settings
        {
            title   = 'Tweller Flow Connection',
            synopsis = function( props )
                if props.siteUrl and props.siteUrl ~= '' then
                    return props.siteUrl
                end
                return 'Not configured'
            end,

            f:row {
                f:static_text { title = 'Website URL:', width = 120, alignment = 'right' },
                f:edit_field {
                    value       = bind 'siteUrl',
                    width_in_chars = 40,
                    tooltip     = 'Your WordPress site URL (e.g., https://twellerstudios.com)',
                },
            },
            f:row {
                f:static_text { title = 'API Key:', width = 120, alignment = 'right' },
                f:password_field {
                    value       = bind 'apiKey',
                    width_in_chars = 40,
                    tooltip     = 'Tweller Flow API key from WordPress settings',
                },
            },
            f:row {
                f:static_text { title = 'Session Code:', width = 120, alignment = 'right' },
                f:edit_field {
                    value       = bind 'sessionCode',
                    width_in_chars = 12,
                    tooltip     = 'Enter the 6-character tracking code (e.g., A1B2C3)',
                },
            },
        },

        -- Export Options
        {
            title   = 'Gallery Export Options',
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
                    value       = bind 'localExportDir',
                    width_in_chars = 35,
                    tooltip     = 'Local folder to save exported photos (leave blank to skip)',
                },
                f:push_button {
                    title   = 'Browse...',
                    action  = function( button )
                        local dir = LrDialogs.runOpenPanel( {
                            title          = 'Choose Export Folder',
                            canChooseFiles = false,
                            canChooseDirectories = true,
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
                    title = 'Auto-advance session to "delivered" when done',
                },
            },
            f:row {
                f:static_text { title = 'Gallery Password:', width = 120, alignment = 'right' },
                f:edit_field {
                    value       = bind 'galleryPassword',
                    width_in_chars = 20,
                    tooltip     = 'Optional password to protect the client gallery (leave blank for no password)',
                },
            },
        },
    }
end

--------------------------------------------------------------------------------
-- Export process
--------------------------------------------------------------------------------

function exportServiceProvider.processRenderedPhotos( functionContext, exportContext )
    local exportSession   = exportContext.exportSession
    local propertyTable   = exportContext.propertyTable
    local nPhotos         = exportSession:countRenditions()

    local siteUrl    = trim( propertyTable.siteUrl )
    local apiKey     = trim( propertyTable.apiKey )
    local sessionCode = trim( propertyTable.sessionCode ):upper()
    local localDir   = trim( propertyTable.localExportDir )
    local uploadToSite = propertyTable.uploadToSite
    local advanceStage = propertyTable.advanceStage
    local galleryPassword = trim( propertyTable.galleryPassword )

    -- Validate
    if sessionCode == "" then
        LrDialogs.message( "Tweller Flow", "Please enter a session tracking code.", "critical" )
        return
    end

    if siteUrl == "" and uploadToSite then
        LrDialogs.message( "Tweller Flow", "Please enter your website URL to upload photos.", "critical" )
        return
    end

    -- Create local export subfolder: localDir/SESSION_CODE/
    local localSessionDir = nil
    if localDir ~= "" then
        localSessionDir = LrPathUtils.child( localDir, sessionCode )
        LrFileUtils.createAllDirectories( localSessionDir )
    end

    -- Progress scope
    local progressScope = exportContext:configureProgress {
        title = "Tweller Flow: Exporting " .. nPhotos .. " photos",
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
                if ok then
                    log( "Saved locally: " .. destPath )
                else
                    log( "Local save failed: " .. tostring( err ) )
                end
            end

            -- 2. Upload to WordPress
            if uploadToSite and siteUrl ~= "" then
                progressScope:setCaption( "Uploading " .. fileName .. " (" .. photoIndex .. "/" .. nPhotos .. ")" )

                local uploadUrl = siteUrl .. "/wp-json/tweller-flow/v1/gallery/upload"

                local mimeType = "image/jpeg"
                local fileContents = nil
                local fh = io.open( renderedPath, "rb" )
                if fh then
                    fileContents = fh:read( "*all" )
                    fh:close()
                end

                if fileContents then
                    -- Build multipart form data
                    local boundary = "----TwellerFlow" .. tostring( math.random( 100000, 999999 ) )
                    local body = ""

                    -- Session code field
                    body = body .. "--" .. boundary .. "\r\n"
                    body = body .. 'Content-Disposition: form-data; name="session_code"\r\n\r\n'
                    body = body .. sessionCode .. "\r\n"

                    -- Password field (only on first photo)
                    if photoIndex == 1 and galleryPassword ~= "" then
                        body = body .. "--" .. boundary .. "\r\n"
                        body = body .. 'Content-Disposition: form-data; name="gallery_password"\r\n\r\n'
                        body = body .. galleryPassword .. "\r\n"
                    end

                    -- Photo file
                    body = body .. "--" .. boundary .. "\r\n"
                    body = body .. 'Content-Disposition: form-data; name="photo"; filename="' .. fileName .. '"\r\n'
                    body = body .. "Content-Type: " .. mimeType .. "\r\n\r\n"
                    body = body .. fileContents .. "\r\n"

                    body = body .. "--" .. boundary .. "--\r\n"

                    local headers = {
                        { field = "Content-Type",  value = "multipart/form-data; boundary=" .. boundary },
                        { field = "Authorization", value = "Bearer " .. apiKey },
                    }

                    local respBody, respHeaders = LrHttp.post( uploadUrl, body, headers )

                    if respBody and jsonBool( respBody, "ok" ) then
                        uploadedCount = uploadedCount + 1
                        log( "Uploaded: " .. fileName )
                    else
                        failedCount = failedCount + 1
                        local errMsg = respBody and jsonValue( respBody, "message" ) or "Unknown error"
                        log( "Upload failed for " .. fileName .. ": " .. tostring( errMsg ) )
                    end
                else
                    failedCount = failedCount + 1
                    log( "Could not read file: " .. renderedPath )
                end
            end

            -- Clean up temp rendition file
            LrFileUtils.delete( renderedPath )
        end
    end

    -- Advance stage to delivered
    if advanceStage and uploadToSite and uploadedCount > 0 and siteUrl ~= "" then
        progressScope:setCaption( "Advancing session to delivered..." )

        local advanceUrl = siteUrl .. "/wp-json/tweller-flow/v1/automation/advance"
            .. "?session_code=" .. sessionCode
            .. "&target_stage=delivered"
            .. "&notes=" .. LrStringUtils.encodeBase64( uploadedCount .. " photos delivered via Lightroom" )
            .. "&photo_count=" .. uploadedCount
            .. "&api_key=" .. apiKey

        local body, headers = LrHttp.get( advanceUrl )
        if body and jsonBool( body, "ok" ) then
            log( "Session advanced to delivered" )
        else
            log( "Stage advance warning: " .. tostring( body ) )
        end
    end

    -- Summary dialog
    local summary = uploadedCount .. " of " .. nPhotos .. " photos exported successfully."
    if localSessionDir then
        summary = summary .. "\n\nLocal: " .. localSessionDir
    end
    if uploadToSite then
        summary = summary .. "\nUploaded to: " .. siteUrl
    end
    if failedCount > 0 then
        summary = summary .. "\n\n" .. failedCount .. " photo(s) failed."
    end

    LrDialogs.message( "Tweller Flow Export Complete", summary, "info" )
end

return exportServiceProvider
