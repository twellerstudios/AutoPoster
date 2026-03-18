import React, { useState, useRef } from 'react';
import './PostPreview.css';

export default function PostPreview({ data, step, businessName, business, onPublish, onBack, onNewPost, onAddImages, errorInfo, selectedPlatforms, onTogglePlatform }) {
  const [tab, setTab] = useState('content');
  const [editingTitle, setEditingTitle] = useState(false);
  const [title, setTitle] = useState(data?.post?.title || '');
  const [editingContent, setEditingContent] = useState(false);
  const [htmlContent, setHtmlContent] = useState(data?.post?.htmlContent || '');
  const [isDragging, setIsDragging] = useState(false);
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef(null);

  if (!data || !data.post) return null;

  const { post } = data;
  const isPublishing = step === 'publishing';
  const isPublished = step === 'published';
  const hasStockImages = post.stockImages && post.stockImages.length > 0;
  const hasUserImages = post.userImages && post.userImages.length > 0;

  function handlePublish() {
    onPublish({ title, htmlContent });
  }

  async function handleAddFiles(files) {
    const validFiles = Array.from(files).filter(f =>
      /\.(jpg|jpeg|png|gif|webp)$/i.test(f.name)
    );
    if (validFiles.length === 0) return;
    if (!onAddImages) return;

    setUploading(true);
    try {
      const result = await onAddImages(validFiles);
      if (result?.htmlContent) {
        setHtmlContent(result.htmlContent);
      }
    } finally {
      setUploading(false);
    }
  }

  function handleDragOver(e) { e.preventDefault(); setIsDragging(true); }
  function handleDragLeave(e) { e.preventDefault(); setIsDragging(false); }
  function handleDrop(e) { e.preventDefault(); setIsDragging(false); handleAddFiles(e.dataTransfer.files); }

  return (
    <div className="preview">
      {/* Status Banner */}
      <div className={`preview-banner ${isPublished ? 'published' : 'review'}`}>
        {isPublished ? (
          <div className="banner-content">
            <span className="banner-icon">&#10003;</span>
            <div>
              <strong>Published!</strong>
              <span className="banner-sub">Your blog post is now live on {businessName}</span>
            </div>
          </div>
        ) : (
          <div className="banner-content">
            <span className="banner-icon">&#128270;</span>
            <div>
              <strong>Review Your Post</strong>
              <span className="banner-sub">Check the content below, edit if needed, then publish when ready</span>
            </div>
          </div>
        )}
      </div>

      {/* Title (editable) */}
      <div className="preview-title-section">
        {editingTitle && !isPublished ? (
          <div className="editable-title">
            <input
              type="text"
              value={title}
              onChange={e => setTitle(e.target.value)}
              className="title-input"
              autoFocus
            />
            <button className="btn-sm" onClick={() => setEditingTitle(false)}>Done</button>
          </div>
        ) : (
          <h2
            className={`preview-title ${!isPublished ? 'clickable' : ''}`}
            onClick={() => !isPublished && setEditingTitle(true)}
            title={!isPublished ? 'Click to edit title' : ''}
          >
            {title || post.title}
            {!isPublished && <span className="edit-icon">&#9998;</span>}
          </h2>
        )}
      </div>

      {/* Published links */}
      {isPublished && (
        <div className="published-links">
          {data?.platforms?.wordpress?.success && data.platforms.wordpress.url && (
            <>
              <a href={data.platforms.wordpress.url} target="_blank" rel="noopener noreferrer" className="link-btn primary">
                View on WordPress &#8599;
              </a>
              {data.platforms.wordpress.editUrl && (
                <a href={data.platforms.wordpress.editUrl} target="_blank" rel="noopener noreferrer" className="link-btn">
                  Edit in WordPress &#8599;
                </a>
              )}
            </>
          )}
          {data?.platforms?.facebook?.success && data.platforms.facebook.url && (
            <a href={data.platforms.facebook.url} target="_blank" rel="noopener noreferrer" className="link-btn primary">
              View on Facebook &#8599;
            </a>
          )}
          {data?.platforms?.facebook && !data.platforms.facebook.success && (
            <div className="platform-error">
              Facebook: {data.platforms.facebook.error || 'Publishing failed'}
            </div>
          )}
          {/* Fallback for backward compat */}
          {!data?.platforms && post.url && (
            <>
              <a href={post.url} target="_blank" rel="noopener noreferrer" className="link-btn primary">
                View Live Post &#8599;
              </a>
              {post.editUrl && (
                <a href={post.editUrl} target="_blank" rel="noopener noreferrer" className="link-btn">
                  Edit in WordPress &#8599;
                </a>
              )}
            </>
          )}
        </div>
      )}

      {/* Tabs */}
      <div className="tabs">
        {['content', 'images', 'seo', 'overview'].map(t => (
          <button
            key={t}
            className={`tab ${tab === t ? 'active' : ''}`}
            onClick={() => setTab(t)}
          >
            {t === 'content' ? 'Content' :
             t === 'images' ? `Images${(hasStockImages || hasUserImages) ? '' : ''}` :
             t === 'seo' ? 'SEO' : 'Overview'}
          </button>
        ))}
      </div>

      {/* Content tab */}
      {tab === 'content' && (
        <div className="tab-panel">
          {!isPublished && (
            <div className="edit-bar">
              <button
                className={`btn-sm ${editingContent ? 'active' : ''}`}
                onClick={() => setEditingContent(!editingContent)}
              >
                {editingContent ? 'Preview' : 'Edit HTML'}
              </button>
              {editingContent && (
                <span className="edit-hint">Editing the raw HTML. Switch back to preview when done.</span>
              )}
            </div>
          )}
          {editingContent && !isPublished ? (
            <textarea
              className="html-editor"
              value={htmlContent}
              onChange={e => setHtmlContent(e.target.value)}
              rows={20}
            />
          ) : (
            <div
              className="content-preview"
              dangerouslySetInnerHTML={{ __html: htmlContent || post.htmlContent }}
            />
          )}
        </div>
      )}

      {/* Images tab */}
      {tab === 'images' && (
        <div className="tab-panel">
          {hasStockImages && (
            <div className="images-section">
              <h3 className="section-label">Stock Images (Pexels)</h3>
              <div className="images-grid">
                {post.stockImages.map((img, i) => (
                  <div key={i} className="image-card">
                    <img src={img.url} alt={`Stock ${i + 1}`} loading="lazy" />
                    <div className="image-card-info">
                      <span className="image-role">{img.role === 'featured' ? 'Featured Image' : 'Inline'}</span>
                      <span className="image-credit">
                        Photo by{' '}
                        <a href={img.photographerUrl} target="_blank" rel="noopener noreferrer">
                          {img.photographer}
                        </a>
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {hasUserImages && (
            <div className="images-section">
              <h3 className="section-label">Your Uploaded Photos</h3>
              <div className="images-grid">
                {post.userImages.map((img, i) => (
                  <div key={i} className="image-card">
                    <img src={img.url} alt={img.originalName} loading="lazy" />
                    <div className="image-card-info">
                      <span className="image-name">{img.originalName}</span>
                      {img.caption && <span className="image-caption">{img.caption}</span>}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {!hasStockImages && !hasUserImages && (
            <p className="empty-state">No images found. Stock images may not be available without a Pexels API key.</p>
          )}

          {/* Add more images */}
          {!isPublished && (
            <div className="images-section add-images-section">
              <h3 className="section-label">Add More Images</h3>
              <div
                className={`preview-drop-zone ${isDragging ? 'dragging' : ''}`}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                onClick={() => fileInputRef.current?.click()}
              >
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/gif,image/webp"
                  multiple
                  onChange={e => { handleAddFiles(e.target.files); e.target.value = ''; }}
                  style={{ display: 'none' }}
                  disabled={uploading}
                />
                {uploading ? (
                  <div className="drop-zone-content">
                    <span className="btn-spinner" />
                    <span>Uploading...</span>
                  </div>
                ) : (
                  <div className="drop-zone-content">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                      <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                      <circle cx="8.5" cy="8.5" r="1.5"/>
                      <polyline points="21 15 16 10 5 21"/>
                    </svg>
                    <span>Drop images here or click to add more</span>
                    <span className="drop-zone-hint">Images will be appended to the post body</span>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      )}

      {/* SEO tab */}
      {tab === 'seo' && (
        <div className="tab-panel">
          <MetaRow label="Meta Description" value={post.metaDescription} />
          <MetaRow label="Focus Keyphrase" value={post.focusKeyphrase} />
          <div className="seo-preview">
            <div className="seo-preview-label">Google Preview</div>
            <div className="seo-preview-box">
              <div className="seo-title">{title || post.title}</div>
              {post.url && <div className="seo-url">{post.url}</div>}
              {!post.url && post.slug && (
                <div className="seo-url">{businessName} &rsaquo; blog &rsaquo; {post.slug}</div>
              )}
              <div className="seo-desc">{post.metaDescription}</div>
            </div>
          </div>
        </div>
      )}

      {/* Overview tab */}
      {tab === 'overview' && (
        <div className="tab-panel">
          <MetaRow label="Slug" value={`/${post.slug}`} mono />
          <MetaRow label="Categories" value={post.categories?.join(', ')} />
          <MetaRow label="Tags" value={post.tags?.join(', ')} />
          {post.id && <MetaRow label="WordPress ID" value={`#${post.id}`} />}
          {hasUserImages && (
            <MetaRow label="Your Photos" value={`${post.userImages.length} image(s) uploaded`} />
          )}
          {hasStockImages && (
            <MetaRow label="Stock Images" value={`${post.stockImages.length} image(s) from Pexels`} />
          )}
        </div>
      )}

      {/* Error display */}
      {errorInfo && (
        <div className="preview-error">
          <p className="preview-error-msg">{errorInfo.message}</p>
          {errorInfo.hint && <p className="preview-error-hint">{errorInfo.hint}</p>}
        </div>
      )}

      {/* Platform selection */}
      {!isPublished && !isPublishing && selectedPlatforms && (
        <div className="platform-selection">
          <h3 className="platform-selection-title">Publish To</h3>
          <div className="platform-checkboxes">
            <label className={`platform-checkbox ${selectedPlatforms.includes('wordpress') ? 'checked' : ''}`}>
              <input
                type="checkbox"
                checked={selectedPlatforms.includes('wordpress')}
                onChange={() => onTogglePlatform('wordpress')}
              />
              <span className="platform-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM3.443 12c0-1.178.25-2.3.69-3.318L8.1 20.27A8.56 8.56 0 013.443 12zm8.557 8.557c-.88 0-1.728-.144-2.52-.41l2.676-7.774 2.742 7.51c.018.044.04.085.064.124a8.502 8.502 0 01-2.962.55zM13.44 7.16c.537-.028 1.02-.085 1.02-.085.48-.057.424-.762-.057-.734 0 0-1.443.113-2.374.113-.876 0-2.346-.113-2.346-.113-.48-.028-.537.706-.057.762 0 0 .455.057.935.085l1.39 3.812-1.952 5.856L7.02 7.16c.538-.028 1.02-.085 1.02-.085.48-.057.424-.762-.057-.734 0 0-1.443.113-2.374.113-.167 0-.364-.004-.572-.012A8.533 8.533 0 0112 3.443c2.27 0 4.342.886 5.88 2.328-.037-.002-.074-.008-.113-.008-.876 0-1.497.762-1.497 1.582 0 .734.424 1.355.876 2.09.34.594.735 1.355.735 2.455 0 .762-.293 1.645-.678 2.877l-.89 2.972L13.44 7.16zm3.823 12.28l2.72-7.864c.51-1.27.678-2.284.678-3.188 0-.328-.02-.632-.063-.918A8.539 8.539 0 0120.557 12a8.564 8.564 0 01-3.294 6.74z"/></svg>
              </span>
              <span className="platform-name">WordPress</span>
            </label>
            <label className={`platform-checkbox ${selectedPlatforms.includes('facebook') ? 'checked' : ''} ${!(business?.facebookConfigured) ? 'disabled' : ''}`}>
              <input
                type="checkbox"
                checked={selectedPlatforms.includes('facebook')}
                onChange={() => onTogglePlatform('facebook')}
                disabled={!(business?.facebookConfigured)}
              />
              <span className="platform-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
              </span>
              <span className="platform-name">Facebook</span>
              {!(business?.facebookConfigured) && (
                <span className="platform-hint">Not configured</span>
              )}
            </label>
          </div>
        </div>
      )}

      {/* Action buttons */}
      <div className="preview-actions">
        {isPublished ? (
          <button className="btn-primary" onClick={onNewPost}>
            Create Another Post
          </button>
        ) : (
          <>
            <button
              className="btn-ghost"
              onClick={onBack}
              disabled={isPublishing}
            >
              &larr; Back to Edit
            </button>
            <button
              className="btn-primary btn-publish"
              onClick={handlePublish}
              disabled={isPublishing || (selectedPlatforms && selectedPlatforms.length === 0)}
            >
              {isPublishing ? (
                <>
                  <span className="btn-spinner" />
                  Publishing{selectedPlatforms && selectedPlatforms.length > 1 ? ` to ${selectedPlatforms.length} platforms` : ''}...
                </>
              ) : (
                selectedPlatforms && selectedPlatforms.length > 1
                  ? `Publish to ${selectedPlatforms.length} Platforms`
                  : selectedPlatforms && selectedPlatforms.length === 1
                    ? `Publish to ${selectedPlatforms[0].charAt(0).toUpperCase() + selectedPlatforms[0].slice(1)}`
                    : 'Select a Platform'
              )}
            </button>
          </>
        )}
      </div>
    </div>
  );
}

function MetaRow({ label, value, mono }) {
  if (!value) return null;
  return (
    <div className="meta-row">
      <span className="meta-label">{label}</span>
      <span className={`meta-value ${mono ? 'mono' : ''}`}>{value}</span>
    </div>
  );
}
