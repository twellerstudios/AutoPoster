import React, { useState } from 'react';
import './PostResult.css';

export default function PostResult({ result }) {
  const [tab, setTab] = useState('overview');
  const { post, action } = result;

  return (
    <div className="result">
      <div className="result-header">
        <div className="result-status">
          <span className="status-dot" />
          {action === 'published' ? 'Published to WordPress' : 'Generated (not published)'}
        </div>
        <div className="result-links">
          {post.url && (
            <a href={post.url} target="_blank" rel="noopener noreferrer" className="link-btn primary">
              View Post ↗
            </a>
          )}
          {post.editUrl && (
            <a href={post.editUrl} target="_blank" rel="noopener noreferrer" className="link-btn">
              Edit in WP ↗
            </a>
          )}
        </div>
      </div>

      <h2 className="result-title">{post.title}</h2>

      {/* Tabs */}
      <div className="tabs">
        {['overview', 'seo', 'content'].map(t => (
          <button
            key={t}
            className={`tab ${tab === t ? 'active' : ''}`}
            onClick={() => setTab(t)}
          >
            {t.charAt(0).toUpperCase() + t.slice(1)}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <div className="tab-panel">
          <MetaRow label="Slug" value={`/${post.slug}`} mono />
          <MetaRow label="Categories" value={post.categories?.join(', ')} />
          <MetaRow label="Tags" value={post.tags?.join(', ')} />
          {post.id && <MetaRow label="WordPress ID" value={`#${post.id}`} />}
        </div>
      )}

      {tab === 'seo' && (
        <div className="tab-panel">
          <MetaRow label="Meta Description" value={post.metaDescription} />
          <MetaRow label="Focus Keyphrase" value={post.focusKeyphrase} />
          <div className="seo-preview">
            <div className="seo-preview-label">Google Preview</div>
            <div className="seo-preview-box">
              <div className="seo-title">{post.title}</div>
              {post.url && <div className="seo-url">{post.url}</div>}
              <div className="seo-desc">{post.metaDescription}</div>
            </div>
          </div>
        </div>
      )}

      {tab === 'content' && post.htmlContent && (
        <div
          className="tab-panel content-preview"
          dangerouslySetInnerHTML={{ __html: post.htmlContent }}
        />
      )}
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
