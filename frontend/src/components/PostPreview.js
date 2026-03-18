import React, { useState } from 'react';
import './PostPreview.css';

export default function PostPreview({ data, step, businessName, onPublish, onBack, onNewPost, errorInfo }) {
  const [tab, setTab] = useState('content');
  const [editingTitle, setEditingTitle] = useState(false);
  const [title, setTitle] = useState(data?.post?.title || '');
  const [editingContent, setEditingContent] = useState(false);
  const [htmlContent, setHtmlContent] = useState(data?.post?.htmlContent || '');

  if (!data || !data.post) return null;

  const { post } = data;
  const isPublishing = step === 'publishing';
  const isPublished = step === 'published';
  const hasStockImages = post.stockImages && post.stockImages.length > 0;
  const hasUserImages = post.userImages && post.userImages.length > 0;

  function handlePublish() {
    onPublish({ title, htmlContent });
  }

  return (
    <div className="preview">
      {/* Status Banner */}
      <div className={`preview-banner ${isPublished ? 'published' : 'review'}`}>
        {isPublished ? (
          <div className="banner-content">
            <span className="banner-icon">&#10003;</span>
            <div>
              <strong>Published to WordPress!</strong>
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
      {isPublished && post.url && (
        <div className="published-links">
          <a href={post.url} target="_blank" rel="noopener noreferrer" className="link-btn primary">
            View Live Post &#8599;
          </a>
          {post.editUrl && (
            <a href={post.editUrl} target="_blank" rel="noopener noreferrer" className="link-btn">
              Edit in WordPress &#8599;
            </a>
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
              disabled={isPublishing}
            >
              {isPublishing ? (
                <>
                  <span className="btn-spinner" />
                  Publishing to WordPress...
                </>
              ) : (
                'Publish to WordPress'
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
