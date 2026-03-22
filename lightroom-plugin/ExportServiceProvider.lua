--[[
    AutoPoster Export Service Provider for Lightroom Classic
    Exports selected photos to WordPress via the AutoPoster backend,
    with optional AI-generated blog posts (powered by Google Gemini).
]]

local LrDialogs        = import "LrDialogs"
local LrHttp           = import "LrHttp"
local LrPathUtils      = import "LrPathUtils"
local LrFileUtils      = import "LrFileUtils"
local LrTasks          = import "LrTasks"
local LrView           = import "LrView"
local LrBinding        = import "LrBinding"
local LrFunctionContext = import "LrFunctionContext"
local LrApplication    = import "LrApplication"

local JSON = require "JSON"

--------------------------------------------------------------------------------
-- Helpers
--------------------------------------------------------------------------------

local function trim(s)
    return s:match("^%s*(.-)%s*$")
end

local function buildUrl(serverUrl, path)
    local base = serverUrl:gsub("/$", "")
    return base .. path
end

--------------------------------------------------------------------------------
-- Export Service Provider definition
--------------------------------------------------------------------------------

local exportServiceProvider = {}

exportServiceProvider.supportsIncrementalPublish = false
exportServiceProvider.hideSections               = { "postProcessing" }
exportServiceProvider.allowFileFormats           = { "JPEG", "TIFF", "ORIGINAL" }
exportServiceProvider.allowColorSpaces           = { "sRGB" }
exportServiceProvider.canExportVideo             = false

exportServiceProvider.exportPresetFields = {
    { key = "serverUrl",       default = "http://localhost:3001" },
    { key = "businessId",      default = "" },
    { key = "generateBlog",    default = true },
    { key = "tone",            default = "professional" },
    { key = "wordCount",       default = 1000 },
    { key = "publishToWP",     default = true },
    { key = "customPrompt",    default = "" },
}

--------------------------------------------------------------------------------
-- UI Sections
--------------------------------------------------------------------------------

function exportServiceProvider.sectionsForTopOfDialog(f, propertyTable)
    local bind = LrView.bind

    return {
        -- Server Connection
        {
            title = "AutoPoster Server",
            synopsis = bind { key = "serverUrl", object = propertyTable },

            f:row {
                f:static_text { title = "Server URL:", alignment = "right", width = LrView.share "label_width" },
                f:edit_field   { value = bind "serverUrl", width_in_chars = 40, immediate = true },
            },

            f:row {
                f:static_text { title = "Business ID:", alignment = "right", width = LrView.share "label_width" },
                f:edit_field   { value = bind "businessId", width_in_chars = 30, immediate = true },
            },

            f:row {
                f:push_button {
                    title  = "Test Connection",
                    action = function()
                        LrTasks.startAsyncTask(function()
                            local url = buildUrl(propertyTable.serverUrl, "/api/health")
                            local body, headers = LrHttp.get(url, nil)
                            if body then
                                local ok, data = pcall(JSON.decode, JSON, body)
                                if ok and data and data.status == "ok" then
                                    LrDialogs.message("Connection successful!", "AutoPoster server is running.", "info")
                                else
                                    LrDialogs.message("Unexpected response", body, "warning")
                                end
                            else
                                LrDialogs.message("Connection failed", "Could not reach " .. url, "critical")
                            end
                        end)
                    end,
                },
            },
        },

        -- Blog Generation Settings
        {
            title = "AI Blog Generation (Gemini)",
            synopsis = function(props)
                return props.generateBlog and "Enabled" or "Disabled"
            end,

            f:row {
                f:checkbox {
                    title = "Generate AI blog post from photo metadata",
                    value = bind "generateBlog",
                },
            },

            f:row {
                f:static_text { title = "Tone:", alignment = "right", width = LrView.share "label_width" },
                f:popup_menu {
                    value   = bind "tone",
                    enabled = bind "generateBlog",
                    items   = {
                        { title = "Professional",   value = "professional" },
                        { title = "Adventurous",     value = "adventurous" },
                        { title = "Casual",          value = "casual" },
                        { title = "Inspiring",       value = "inspiring" },
                        { title = "Authoritative",   value = "authoritative" },
                        { title = "Conversational",  value = "conversational" },
                    },
                },
            },

            f:row {
                f:static_text { title = "Word Count:", alignment = "right", width = LrView.share "label_width" },
                f:popup_menu {
                    value   = bind "wordCount",
                    enabled = bind "generateBlog",
                    items   = {
                        { title = "~600 words",  value = 600 },
                        { title = "~1000 words", value = 1000 },
                        { title = "~1500 words", value = 1500 },
                        { title = "~2000 words", value = 2000 },
                    },
                },
            },

            f:row {
                f:static_text { title = "Custom Prompt:", alignment = "right", width = LrView.share "label_width" },
                f:edit_field {
                    value         = bind "customPrompt",
                    enabled       = bind "generateBlog",
                    width_in_chars = 50,
                    height_in_lines = 3,
                    immediate     = true,
                },
            },

            f:row {
                f:checkbox {
                    title   = "Publish to WordPress immediately",
                    value   = bind "publishToWP",
                    enabled = bind "generateBlog",
                },
            },
        },
    }
end

--------------------------------------------------------------------------------
-- Export process
--------------------------------------------------------------------------------

function exportServiceProvider.processRenderedPhotos(functionContext, exportContext)
    local exportSession  = exportContext.exportSession
    local exportSettings = exportContext.propertyTable
    local nPhotos        = exportSession:countRenditions()

    local progressScope = exportContext:configureProgress {
        title = nPhotos > 1
            and string.format("Uploading %d photos to AutoPoster", nPhotos)
            or "Uploading photo to AutoPoster",
    }

    local failures   = {}
    local successes  = 0

    for _, rendition in exportSession:renditions { stopIfCanceled = true } do
        progressScope:setPortionComplete(successes, nPhotos)

        local success, pathOrMessage = rendition:waitForRender()
        if not success then
            table.insert(failures, { photo = rendition.photo, message = pathOrMessage })
        else
            local filePath = pathOrMessage

            -- Gather EXIF / metadata
            local photo    = rendition.photo
            local title    = photo:getFormattedMetadata("title") or ""
            local caption  = photo:getFormattedMetadata("caption") or ""
            local keywords = {}
            local kwItems  = photo:getRawMetadata("keywords") or {}
            for _, kw in ipairs(kwItems) do
                table.insert(keywords, kw:getName())
            end

            local camera     = photo:getFormattedMetadata("cameraModel") or ""
            local lens       = photo:getFormattedMetadata("lens") or ""
            local exposure   = photo:getFormattedMetadata("exposure") or ""
            local focalLen   = photo:getFormattedMetadata("focalLength") or ""
            local isoRating  = photo:getFormattedMetadata("isoSpeedRating") or ""
            local gps        = photo:getFormattedMetadata("gps") or ""
            local dateTime   = photo:getFormattedMetadata("dateTimeOriginal") or ""

            -- Build multipart form body
            local fileName  = LrPathUtils.leafName(filePath)
            local fileData  = LrFileUtils.readFile(filePath)

            if not fileData then
                table.insert(failures, { photo = photo, message = "Could not read rendered file" })
            else
                local metadata = JSON:encode({
                    title        = title,
                    caption      = caption,
                    keywords     = keywords,
                    camera       = camera,
                    lens         = lens,
                    exposure     = exposure,
                    focalLength  = focalLen,
                    iso          = isoRating,
                    gps          = gps,
                    dateTime     = dateTime,
                })

                local mimeType = "image/jpeg"
                local ext = LrPathUtils.extension(filePath):lower()
                if ext == "tif" or ext == "tiff" then
                    mimeType = "image/tiff"
                elseif ext == "png" then
                    mimeType = "image/png"
                end

                local url = buildUrl(exportSettings.serverUrl, "/api/lightroom/upload")

                local multipartBody = {
                    { name = "photo",        fileName = fileName, filePath = filePath, contentType = mimeType },
                    { name = "metadata",     value = metadata },
                    { name = "businessId",   value = exportSettings.businessId },
                    { name = "generateBlog", value = tostring(exportSettings.generateBlog) },
                    { name = "tone",         value = exportSettings.tone },
                    { name = "wordCount",    value = tostring(exportSettings.wordCount) },
                    { name = "publish",      value = tostring(exportSettings.publishToWP) },
                    { name = "customPrompt", value = exportSettings.customPrompt or "" },
                }

                local body, headers = LrHttp.postMultipart(url, multipartBody)

                if not body then
                    table.insert(failures, { photo = photo, message = "No response from server" })
                else
                    local ok, result = pcall(JSON.decode, JSON, body)
                    if ok and result and result.success then
                        successes = successes + 1
                        if result.post and result.post.url then
                            rendition:recordPublishedPhotoUrl(result.post.url)
                        end
                    else
                        local errMsg = (ok and result and result.error) or body
                        table.insert(failures, { photo = photo, message = errMsg })
                    end
                end
            end

            -- Clean up temp file
            LrFileUtils.delete(filePath)
        end
    end

    -- Report results
    if #failures > 0 then
        local msgs = {}
        for _, f in ipairs(failures) do
            table.insert(msgs, f.message or "Unknown error")
        end
        LrDialogs.message(
            string.format("Upload completed with %d error(s)", #failures),
            table.concat(msgs, "\n"),
            "warning"
        )
    elseif successes > 0 then
        LrDialogs.message(
            "Upload complete!",
            string.format("%d photo(s) uploaded to AutoPoster successfully.", successes),
            "info"
        )
    end
end

return exportServiceProvider
