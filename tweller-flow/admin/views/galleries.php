<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-wrap">
    <h1>Galleries</h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Gallery settings saved.</div>
    <?php elseif ( isset( $_GET['deleted'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Gallery deleted.</div>
    <?php endif; ?>

    <!-- Manual Upload Panel -->
    <div class="tf-card tf-mb-6" id="tf-manual-upload-panel">
        <h2 style="margin:0 0 16px 0; font-size:16px;">Manual Upload</h2>
        <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
            <div>
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:4px;">Session</label>
                <select id="tf-upload-session" style="padding:7px 10px; border:1px solid #D1D5DB; border-radius:6px; font-size:13px; min-width:260px; font-family:inherit;">
                    <option value="">— select a session —</option>
                    <?php foreach ( $all_sessions as $s ) : ?>
                        <option value="<?php echo esc_attr( $s->tracking_code ); ?>">
                            <?php echo esc_html( $s->tracking_code . ' — ' . $s->client_name ); ?>
                            <?php echo $s->session_date ? ' (' . date( 'M j Y', strtotime( $s->session_date ) ) . ')' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Drop zone -->
        <div id="tf-drop-zone" style="border:2px dashed #D1D5DB; border-radius:10px; padding:40px 20px; text-align:center; cursor:pointer; transition:border-color .2s, background .2s; background:#FAFAFA;">
            <div style="font-size:32px; margin-bottom:8px;">&#128444;</div>
            <p style="margin:0 0 8px; font-size:14px; color:#374151; font-weight:600;">Drag &amp; drop photos here</p>
            <p style="margin:0 0 12px; font-size:12px; color:#9CA3AF;">JPEG, PNG, or WebP — multiple files supported</p>
            <input type="file" id="tf-file-input" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
            <button type="button" id="tf-browse-btn" class="tf-btn tf-btn--secondary tf-btn--sm">Browse files</button>
        </div>

        <!-- File list -->
        <div id="tf-file-list" style="margin-top:12px; display:none;">
            <div style="font-size:12px; color:#6B7280; margin-bottom:8px;"><span id="tf-file-count">0</span> file(s) selected</div>
            <div id="tf-file-names" style="max-height:120px; overflow-y:auto; font-size:12px; color:#374151; background:#F9FAFB; border:1px solid #E5E7EB; border-radius:6px; padding:8px 12px;"></div>
        </div>

        <!-- Progress -->
        <div id="tf-upload-progress" style="display:none; margin-top:12px;">
            <div style="height:6px; background:#E5E7EB; border-radius:3px; overflow:hidden;">
                <div id="tf-progress-bar" style="height:100%; background:#6366F1; width:0%; transition:width .3s;"></div>
            </div>
            <p id="tf-progress-text" style="font-size:12px; color:#6B7280; margin:6px 0 0;"></p>
        </div>

        <!-- Result -->
        <div id="tf-upload-result" style="display:none; margin-top:12px;"></div>

        <div style="margin-top:16px; display:flex; gap:8px;">
            <button type="button" id="tf-upload-btn" class="tf-btn tf-btn--primary" disabled>Upload Photos</button>
            <button type="button" id="tf-clear-btn" class="tf-btn tf-btn--ghost" style="display:none;">Clear</button>
        </div>
    </div>

    <script>
    (function($){
        var files = [];

        function resetUI() {
            files = [];
            $('#tf-file-input').val('');
            $('#tf-file-list').hide();
            $('#tf-file-names').empty();
            $('#tf-file-count').text('0');
            $('#tf-upload-btn').prop('disabled', true);
            $('#tf-clear-btn').hide();
            $('#tf-upload-result').hide().empty();
            $('#tf-upload-progress').hide();
            $('#tf-progress-bar').css('width','0%');
            $('#tf-drop-zone').css({borderColor:'#D1D5DB', background:'#FAFAFA'});
        }

        function showFiles(newFiles) {
            files = Array.from(newFiles);
            if (!files.length) return;
            $('#tf-file-count').text(files.length);
            $('#tf-file-names').html(files.map(function(f){ return '<div>'+$('<span>').text(f.name).html()+'</div>'; }).join(''));
            $('#tf-file-list').show();
            $('#tf-upload-btn').prop('disabled', !$('#tf-upload-session').val());
            $('#tf-clear-btn').show();
        }

        $('#tf-browse-btn').on('click', function(){ $('#tf-file-input').trigger('click'); });
        $('#tf-file-input').on('change', function(){ showFiles(this.files); });
        $('#tf-clear-btn').on('click', resetUI);
        $('#tf-upload-session').on('change', function(){
            $('#tf-upload-btn').prop('disabled', !$(this).val() || !files.length);
        });

        var dz = document.getElementById('tf-drop-zone');
        dz.addEventListener('dragover', function(e){ e.preventDefault(); $(dz).css({borderColor:'#6366F1', background:'#EEF2FF'}); });
        dz.addEventListener('dragleave', function(){ $(dz).css({borderColor:'#D1D5DB', background:'#FAFAFA'}); });
        dz.addEventListener('drop', function(e){
            e.preventDefault();
            $(dz).css({borderColor:'#D1D5DB', background:'#FAFAFA'});
            showFiles(e.dataTransfer.files);
        });

        $('#tf-upload-btn').on('click', function(){
            var sessionCode = $('#tf-upload-session').val();
            if (!sessionCode || !files.length) return;

            var total = files.length, done = 0, savedNames = [], errorMsgs = [];
            $('#tf-upload-progress').show();
            $('#tf-upload-result').hide().empty();
            $('#tf-upload-btn').prop('disabled', true);

            function uploadNext(idx) {
                if (idx >= total) {
                    // All done
                    $('#tf-progress-bar').css('width','100%');
                    var html = '';
                    if (savedNames.length) {
                        html += '<div class="tf-alert tf-alert--success">Uploaded ' + savedNames.length + ' photo(s) successfully.</div>';
                    }
                    if (errorMsgs.length) {
                        html += '<div class="tf-alert tf-alert--error" style="margin-top:8px;">' + errorMsgs.join('<br>') + '</div>';
                    }
                    $('#tf-upload-result').html(html).show();
                    setTimeout(function(){ location.reload(); }, 1500);
                    return;
                }

                var pct = Math.round((idx / total) * 100);
                $('#tf-progress-bar').css('width', pct + '%');
                $('#tf-progress-text').text('Uploading ' + (idx+1) + ' of ' + total + ': ' + files[idx].name);

                var fd = new FormData();
                fd.append('action', 'tweller_flow_admin_upload');
                fd.append('nonce', twellerFlow.nonce);
                fd.append('session_code', sessionCode);
                fd.append('photos[]', files[idx]);

                $.ajax({
                    url: twellerFlow.ajaxUrl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(res){
                        if (res.success) {
                            savedNames = savedNames.concat(res.data.saved || []);
                            errorMsgs  = errorMsgs.concat(res.data.errors || []);
                        } else {
                            errorMsgs.push(files[idx].name + ': ' + (res.data || 'Unknown error'));
                        }
                        uploadNext(idx + 1);
                    },
                    error: function(){ errorMsgs.push(files[idx].name + ': network error'); uploadNext(idx + 1); }
                });
            }

            uploadNext(0);
        });
    })(jQuery);
    </script>

    <?php if ( empty( $sessions_with_galleries ) ) : ?>
        <div class="tf-card">
            <p style="text-align:center; color:#6B7280; padding:40px 0;">
                No galleries yet. Export photos from Lightroom or let the watcher auto-upload from your exports folder.
            </p>
        </div>
    <?php else : ?>

        <?php foreach ( $sessions_with_galleries as $session ) :
            $gallery_info = TwellerFlow_Gallery::get_gallery_info( $session->id, $session->tracking_code );
            $gallery_url  = TwellerFlow_Gallery::get_gallery_url( $session->tracking_code );
            $client_link  = $tracker_url ? $tracker_url . ( strpos( $tracker_url, '?' ) !== false ? '&' : '?' ) . 'code=' . $session->tracking_code . '#gallery' : '';
        ?>
            <div class="tf-card tf-mb-6">
                <!-- Gallery Header -->
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                    <div>
                        <h2 style="margin:0 0 4px 0; font-size:18px;">
                            <?php echo esc_html( $session->client_name ); ?>
                            <span style="font-weight:400; color:#6B7280; font-size:14px; margin-left:8px;"><?php echo esc_html( $session->tracking_code ); ?></span>
                        </h2>
                        <div style="font-size:13px; color:#9CA3AF; display:flex; gap:16px; flex-wrap:wrap;">
                            <?php if ( $session->session_date ) : ?>
                                <span>Session: <?php echo date( 'M j, Y', strtotime( $session->session_date ) ); ?></span>
                            <?php endif; ?>
                            <span>Stage: <strong style="color:#374151;"><?php echo ucfirst( $session->current_stage ); ?></strong></span>
                            <span><?php echo $gallery_info['photo_count']; ?> photos<?php echo $gallery_info['total_size_mb'] ? ' (' . $gallery_info['total_size_mb'] . ' MB)' : ''; ?></span>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <?php if ( $client_link && $gallery_info['photo_count'] > 0 ) : ?>
                            <a href="<?php echo esc_url( $client_link ); ?>" target="_blank" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:12px;">Client View</a>
                        <?php endif; ?>
                        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-session&id=' . $session->id ); ?>" class="tf-btn tf-btn--ghost tf-btn--sm" style="font-size:12px;">Session Details</a>
                    </div>
                </div>

                <!-- Photo Grid -->
                <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:8px; margin-bottom:16px;">
                        <?php foreach ( array_slice( $gallery_info['photos'], 0, 20 ) as $photo ) : ?>
                            <div style="position:relative; border-radius:8px; overflow:hidden; aspect-ratio:1; background:#F3F4F6;">
                                <img src="<?php echo esc_url( $gallery_url . '/thumbs/' . $photo->filename ); ?>" alt="<?php echo esc_attr( $photo->filename ); ?>" style="width:100%; height:100%; object-fit:cover;">
                            </div>
                        <?php endforeach; ?>
                        <?php if ( $gallery_info['photo_count'] > 20 ) : ?>
                            <div style="border-radius:8px; aspect-ratio:1; background:#F3F4F6; display:flex; align-items:center; justify-content:center; color:#6B7280; font-size:14px; font-weight:600;">
                                +<?php echo $gallery_info['photo_count'] - 20; ?> more
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Gallery Controls -->
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding-top:16px; border-top:1px solid #F3F4F6;">
                    <!-- Password -->
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:13px; color:#6B7280;">
                            <?php echo $gallery_info['has_password'] ? '&#128274; Protected' : '&#128275; No password'; ?>
                        </span>
                        <form method="post" style="display:inline-flex; gap:4px; align-items:center;">
                            <?php wp_nonce_field( 'tweller_flow_gallery_password' ); ?>
                            <input type="hidden" name="tweller_flow_gallery_password" value="1">
                            <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                            <input type="hidden" name="redirect_to" value="galleries">
                            <input type="text" name="gallery_pw" placeholder="<?php echo $gallery_info['has_password'] ? 'Change password' : 'Set password'; ?>" style="padding:5px 8px; font-size:12px; border:1px solid #D1D5DB; border-radius:6px; width:120px; font-family:inherit;">
                            <button type="submit" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:11px; padding:5px 10px;">Set</button>
                        </form>
                        <?php if ( $gallery_info['has_password'] ) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'tweller_flow_gallery_password' ); ?>
                                <input type="hidden" name="tweller_flow_gallery_password" value="1">
                                <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                                <input type="hidden" name="gallery_pw" value="">
                                <input type="hidden" name="redirect_to" value="galleries">
                                <button type="submit" class="tf-btn tf-btn--ghost tf-btn--sm" style="font-size:11px; padding:5px 10px; color:#DC2626;" onclick="return confirm('Remove password protection?');">Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div style="margin-left:auto;">
                        <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-galleries&action=delete_gallery&session_id=' . $session->id ), 'tweller_flow_delete_gallery_' . $session->id ); ?>"
                               class="tf-btn tf-btn--danger tf-btn--sm" style="font-size:11px; padding:5px 10px;"
                               onclick="return confirm('Delete all <?php echo $gallery_info['photo_count']; ?> photos from this gallery? This cannot be undone.');">
                                Delete Gallery
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>
