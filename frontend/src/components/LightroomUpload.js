import React, { useState, useRef } from 'react';
import { api } from '../services/api';
import PostResult from './PostResult';
import './LightroomUpload.css';

const TONES = [
  { value: 'professional', label: 'Professional' },
  { value: 'adventurous', label: 'Adventurous' },
  { value: 'casual', label: 'Casual & Friendly' },
  { value: 'inspiring', label: 'Inspiring' },
  { value: 'authoritative', label: 'Authoritative' },
  { value: 'conversational', label: 'Conversational' },
];

const WORD_COUNTS = [
  { value: 600, label: '~600 words (Quick read)' },
  { value: 1000, label: '~1,000 words (Standard)' },
  { value: 1500, label: '~1,500 words (In-depth)' },
  { value: 2000, label: '~2,000 words (Comprehensive)' },
];

export default function LightroomUpload({ businesses, showToast }) {
  const [form, setForm] = useState({
    businessId: businesses[0]?.id || '',
    tone: 'professional',
    wordCount: 1000,
    publish: true,
    customPrompt: '',
  });
  const [files, setFiles] = useState([]);
  const [status, setStatus] = useState('idle');
  const [results, setResults] = useState([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  const fileInputRef = useRef(null);

  function set(field, value) {
    setForm(f => ({ ...f, [field]: value }));
  }

  function handleFiles(e) {
    const selected = Array.from(e.target.files);
    setFiles(selected);
    setResults([]);
  }

  function handleDrop(e) {
    e.preventDefault();
    const dropped = Array.from(e.dataTransfer.files).filter(f =>
      f.type.startsWith('image/')
    );
    setFiles(dropped);
    setResults([]);
  }

  async function handleSubmit(e) {
    e.preventDefault();
    if (files.length === 0) {
      showToast('Please select at least one photo', 'error');
      return;
    }
    if (!form.businessId) {
      showToast('Please select a business', 'error');
      return;
    }

    setStatus('uploading');
    setResults([]);

    const allResults = [];
    for (let i = 0; i < files.length; i++) {
      setCurrentIndex(i);
      const file = files[i];

      try {
        const formData = new FormData();
        formData.append('photo', file);
        formData.append('businessId', form.businessId);
        formData.append('generateBlog', 'true');
        formData.append('tone', form.tone);
        formData.append('wordCount', String(form.wordCount));
        formData.append('publish', String(form.publish));
        formData.append('customPrompt', form.customPrompt);
        formData.append('metadata', JSON.stringify({ title: file.name }));

        const result = await api.lightroomUpload(formData);
        allResults.push({ file: file.name, ...result });
      } catch (err) {
        allResults.push({ file: file.name, success: false, error: err.message });
      }
    }

    setResults(allResults);
    setStatus('done');

    const successCount = allResults.filter(r => r.success).length;
    if (successCount === allResults.length) {
      showToast(`All ${successCount} photo(s) processed successfully!`, 'success');
    } else {
      showToast(`${successCount}/${allResults.length} succeeded`, successCount > 0 ? 'info' : 'error');
    }
  }

  const isUploading = status === 'uploading';
  const selectedBusiness = businesses.find(b => b.id === form.businessId);

  return (
    <div className="generator">
      <div className="generator-header">
        <h1>Photo to Blog Post</h1>
        <p className="subtitle">
          Upload photos and let Gemini AI write SEO-optimized blog posts, then publish directly to WordPress.
        </p>
      </div>

      <form className="form" onSubmit={handleSubmit}>
        {/* Drop zone */}
        <div
          className={`drop-zone ${files.length > 0 ? 'has-files' : ''}`}
          onDrop={handleDrop}
          onDragOver={e => e.preventDefault()}
          onClick={() => !isUploading && fileInputRef.current?.click()}
        >
          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            multiple
            onChange={handleFiles}
            style={{ display: 'none' }}
            disabled={isUploading}
          />
          {files.length === 0 ? (
            <div className="drop-zone-empty">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                <rect x="3" y="3" width="18" height="18" rx="2" />
                <circle cx="8.5" cy="8.5" r="1.5" />
                <path d="M21 15l-5-5L5 21" />
              </svg>
              <p>Drop photos here or click to browse</p>
              <span className="drop-hint">JPEG, TIFF, PNG supported</span>
            </div>
          ) : (
            <div className="drop-zone-files">
              <p>{files.length} photo{files.length !== 1 ? 's' : ''} selected</p>
              <div className="file-preview-grid">
                {files.slice(0, 6).map((file, i) => (
                  <div key={i} className="file-preview-thumb">
                    <img src={URL.createObjectURL(file)} alt={file.name} />
                  </div>
                ))}
                {files.length > 6 && (
                  <div className="file-preview-thumb more">+{files.length - 6}</div>
                )}
              </div>
              <span className="drop-hint">Click to change selection</span>
            </div>
          )}
        </div>

        {/* Business selector */}
        <div className="field">
          <label htmlFor="lr-businessId">Business</label>
          <select
            id="lr-businessId"
            value={form.businessId}
            onChange={e => set('businessId', e.target.value)}
            disabled={isUploading}
          >
            {businesses.length === 0 && <option value="">No businesses configured</option>}
            {businesses.map(b => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </select>
          {selectedBusiness && (
            <span className="field-hint">{selectedBusiness.wordpressUrl}</span>
          )}
        </div>

        {/* Tone + Word Count */}
        <div className="field-row">
          <div className="field">
            <label htmlFor="lr-tone">Tone</label>
            <select id="lr-tone" value={form.tone} onChange={e => set('tone', e.target.value)} disabled={isUploading}>
              {TONES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="lr-wordCount">Length</label>
            <select id="lr-wordCount" value={form.wordCount} onChange={e => set('wordCount', e.target.value)} disabled={isUploading}>
              {WORD_COUNTS.map(w => <option key={w.value} value={w.value}>{w.label}</option>)}
            </select>
          </div>
        </div>

        {/* Custom prompt */}
        <div className="field">
          <label htmlFor="lr-prompt">Custom Instructions <span className="optional">(optional)</span></label>
          <textarea
            id="lr-prompt"
            value={form.customPrompt}
            onChange={e => set('customPrompt', e.target.value)}
            placeholder="e.g. Focus on the travel destination, mention camera settings, include a call to action..."
            rows={2}
            disabled={isUploading}
          />
        </div>

        {/* Publish toggle */}
        <div className="field">
          <div className="toggle-row">
            <div>
              <div className="toggle-label">Publish immediately</div>
              <div className="toggle-desc">Post goes live on WordPress as soon as it's generated</div>
            </div>
            <button
              type="button"
              role="switch"
              aria-checked={form.publish}
              className={`toggle ${form.publish ? 'on' : 'off'}`}
              onClick={() => set('publish', !form.publish)}
              disabled={isUploading}
            >
              <span className="toggle-thumb" />
            </button>
          </div>
        </div>

        {/* Submit */}
        <button type="submit" className="btn-primary" disabled={isUploading || files.length === 0}>
          {isUploading ? (
            <>
              <span className="btn-spinner" />
              Processing photo {currentIndex + 1} of {files.length}...
            </>
          ) : (
            form.publish ? 'Upload & Publish' : 'Upload & Generate'
          )}
        </button>
      </form>

      {/* Results */}
      {results.length > 0 && (
        <div className="lr-results">
          <h2>Results</h2>
          {results.map((r, i) => (
            <div key={i} className={`lr-result-card ${r.success ? 'success' : 'failed'}`}>
              <div className="lr-result-header">
                <span className={`lr-status-dot ${r.success ? 'ok' : 'err'}`} />
                <strong>{r.file}</strong>
                {r.post?.url && (
                  <a href={r.post.url} target="_blank" rel="noopener noreferrer" className="btn-ghost btn-sm">
                    View Post
                  </a>
                )}
              </div>
              {r.success && r.post && (
                <div className="lr-result-meta">
                  <span>{r.post.title}</span>
                </div>
              )}
              {!r.success && (
                <div className="lr-result-error">{r.error}</div>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Plugin setup instructions */}
      <div className="lr-setup-section">
        <h2>Lightroom Classic Plugin Setup</h2>
        <ol className="lr-steps">
          <li>
            <strong>Get a free Gemini API key</strong> at{' '}
            <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">
              aistudio.google.com/apikey
            </a>{' '}
            and add it to your <code>.env</code> as <code>GEMINI_API_KEY=your_key</code>
          </li>
          <li>
            <strong>Install the plugin:</strong> In Lightroom Classic, go to{' '}
            <em>File &rarr; Plug-in Manager &rarr; Add</em> and select the{' '}
            <code>lightroom-plugin</code> folder from this repo
          </li>
          <li>
            <strong>Export photos:</strong> Select photos &rarr; <em>File &rarr; Export</em> &rarr; choose{' '}
            <strong>"AutoPoster &mdash; WordPress"</strong> from the export dropdown
          </li>
          <li>
            <strong>Configure:</strong> Enter this server's URL and your Business ID, then click Export
          </li>
        </ol>
      </div>
    </div>
  );
}
