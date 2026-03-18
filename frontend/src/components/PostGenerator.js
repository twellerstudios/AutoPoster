import React, { useState, useRef } from 'react';
import { api } from '../services/api';
import PostPreview from './PostPreview';
import './PostGenerator.css';

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

export default function PostGenerator({ businesses, showToast }) {
  const [form, setForm] = useState({
    businessId: businesses[0]?.id || '',
    topic: '',
    tone: 'professional',
    wordCount: 1000,
    keywords: '',
  });

  // Image upload state
  const [images, setImages] = useState([]);       // File objects
  const [imagePreviews, setImagePreviews] = useState([]); // { file, previewUrl, caption }
  const fileInputRef = useRef(null);
  const dropZoneRef = useRef(null);
  const [isDragging, setIsDragging] = useState(false);

  // Workflow state
  const [step, setStep] = useState('input');      // input | generating | preview | publishing | published
  const [previewData, setPreviewData] = useState(null);
  const [publishResult, setPublishResult] = useState(null);
  const [testingConn, setTestingConn] = useState(false);

  function set(field, value) {
    setForm(f => ({ ...f, [field]: value }));
  }

  // ── Image handling ──────────────────────────────────────────────────────────

  function addFiles(files) {
    const validFiles = Array.from(files).filter(f =>
      /\.(jpg|jpeg|png|gif|webp)$/i.test(f.name)
    );
    if (validFiles.length === 0) {
      showToast('Only image files (jpg, png, gif, webp) are allowed', 'error');
      return;
    }
    const newPreviews = validFiles.map(file => ({
      file,
      previewUrl: URL.createObjectURL(file),
      caption: '',
    }));
    setImages(prev => [...prev, ...validFiles]);
    setImagePreviews(prev => [...prev, ...newPreviews]);
  }

  function removeImage(index) {
    URL.revokeObjectURL(imagePreviews[index].previewUrl);
    setImages(prev => prev.filter((_, i) => i !== index));
    setImagePreviews(prev => prev.filter((_, i) => i !== index));
  }

  function updateCaption(index, caption) {
    setImagePreviews(prev => prev.map((img, i) =>
      i === index ? { ...img, caption } : img
    ));
  }

  function handleDragOver(e) {
    e.preventDefault();
    setIsDragging(true);
  }

  function handleDragLeave(e) {
    e.preventDefault();
    setIsDragging(false);
  }

  function handleDrop(e) {
    e.preventDefault();
    setIsDragging(false);
    addFiles(e.dataTransfer.files);
  }

  // ── Connection test ─────────────────────────────────────────────────────────

  async function handleTestConnection() {
    if (!form.businessId) return;
    setTestingConn(true);
    try {
      const r = await api.testBusiness(form.businessId);
      showToast(`Connected as "${r.user}"`, 'success');
    } catch (err) {
      showToast(`Connection failed: ${err.message}`, 'error');
    } finally {
      setTestingConn(false);
    }
  }

  // ── Step 1: Generate preview ────────────────────────────────────────────────

  async function handleGenerate(e) {
    e.preventDefault();
    if (!form.topic.trim()) {
      showToast('Please enter a topic', 'error');
      return;
    }

    setStep('generating');
    setPreviewData(null);
    setPublishResult(null);

    try {
      const data = await api.generatePost({
        businessId: form.businessId,
        topic: form.topic.trim(),
        tone: form.tone,
        wordCount: parseInt(form.wordCount),
        keywords: form.keywords,
        images,
        imageCaptions: imagePreviews.map(img => img.caption),
      });

      setPreviewData(data);
      setStep('preview');
      showToast('Blog post generated! Review it below before publishing.', 'success');
    } catch (err) {
      setStep('input');
      showToast(err.message, 'error');
    }
  }

  // ── Step 2: Publish ─────────────────────────────────────────────────────────

  async function handlePublish({ title, htmlContent }) {
    setStep('publishing');

    try {
      const data = await api.publishPost({
        previewId: previewData.previewId,
        title,
        htmlContent,
      });

      setPublishResult(data);
      setStep('published');
      showToast('Post published successfully!', 'success');
    } catch (err) {
      setStep('preview');
      showToast(`Publish failed: ${err.message}`, 'error');
    }
  }

  // ── Go back to edit ─────────────────────────────────────────────────────────

  function handleBackToInput() {
    setStep('input');
    setPreviewData(null);
    setPublishResult(null);
  }

  function handleNewPost() {
    setForm(f => ({ ...f, topic: '', keywords: '' }));
    setImages([]);
    setImagePreviews([]);
    setStep('input');
    setPreviewData(null);
    setPublishResult(null);
  }

  const selectedBusiness = businesses.find(b => b.id === form.businessId);
  const isWorking = step === 'generating' || step === 'publishing';

  // ── Preview / Published view ────────────────────────────────────────────────

  if (step === 'preview' || step === 'publishing' || step === 'published') {
    return (
      <div className="generator">
        <div className="generator-header">
          <div className="step-indicator">
            <span className="step done">1. Create</span>
            <span className="step-arrow">&rarr;</span>
            <span className={`step ${step === 'preview' || step === 'publishing' ? 'active' : 'done'}`}>
              2. Review
            </span>
            <span className="step-arrow">&rarr;</span>
            <span className={`step ${step === 'published' ? 'done' : ''}`}>
              3. Publish
            </span>
          </div>
        </div>

        <PostPreview
          data={step === 'published' ? publishResult : previewData}
          step={step}
          businessName={selectedBusiness?.name}
          onPublish={handlePublish}
          onBack={handleBackToInput}
          onNewPost={handleNewPost}
        />
      </div>
    );
  }

  // ── Input form view ─────────────────────────────────────────────────────────

  return (
    <div className="generator">
      <div className="generator-header">
        <h1>Create a Blog Post</h1>
        <p className="subtitle">Generate SEO-optimised content with AI, review it, then publish to WordPress.</p>
        <div className="step-indicator">
          <span className="step active">1. Create</span>
          <span className="step-arrow">&rarr;</span>
          <span className="step">2. Review</span>
          <span className="step-arrow">&rarr;</span>
          <span className="step">3. Publish</span>
        </div>
      </div>

      <form className="form" onSubmit={handleGenerate}>

        {/* Business selector */}
        <div className="field">
          <label htmlFor="businessId">Business</label>
          <div className="select-row">
            <select
              id="businessId"
              value={form.businessId}
              onChange={e => set('businessId', e.target.value)}
              disabled={isWorking}
            >
              {businesses.length === 0 && (
                <option value="">No businesses configured</option>
              )}
              {businesses.map(b => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
            <button
              type="button"
              className="btn-ghost"
              onClick={handleTestConnection}
              disabled={testingConn || isWorking || !form.businessId}
            >
              {testingConn ? 'Testing...' : 'Test Connection'}
            </button>
          </div>
          {selectedBusiness && (
            <span className="field-hint">{selectedBusiness.wordpressUrl}</span>
          )}
        </div>

        {/* Topic */}
        <div className="field">
          <label htmlFor="topic">Topic *</label>
          <textarea
            id="topic"
            value={form.topic}
            onChange={e => set('topic', e.target.value)}
            placeholder='e.g. Make me a blog post on "Downs Syndrome Day" with awareness info and resources'
            rows={3}
            disabled={isWorking}
            required
          />
          <span className="field-hint">Be specific — the more detail, the better the post</span>
        </div>

        {/* Tone + Word Count row */}
        <div className="field-row">
          <div className="field">
            <label htmlFor="tone">Tone</label>
            <select
              id="tone"
              value={form.tone}
              onChange={e => set('tone', e.target.value)}
              disabled={isWorking}
            >
              {TONES.map(t => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
          </div>

          <div className="field">
            <label htmlFor="wordCount">Length</label>
            <select
              id="wordCount"
              value={form.wordCount}
              onChange={e => set('wordCount', e.target.value)}
              disabled={isWorking}
            >
              {WORD_COUNTS.map(w => (
                <option key={w.value} value={w.value}>{w.label}</option>
              ))}
            </select>
          </div>
        </div>

        {/* Keywords */}
        <div className="field">
          <label htmlFor="keywords">SEO Keywords <span className="optional">(optional)</span></label>
          <input
            id="keywords"
            type="text"
            value={form.keywords}
            onChange={e => set('keywords', e.target.value)}
            placeholder="downs syndrome day, world down syndrome, awareness"
            disabled={isWorking}
          />
          <span className="field-hint">Comma-separated keywords to weave into the post</span>
        </div>

        {/* Photo Upload */}
        <div className="field">
          <label>Your Photos <span className="optional">(optional)</span></label>
          <div
            ref={dropZoneRef}
            className={`drop-zone ${isDragging ? 'dragging' : ''}`}
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
              onChange={e => addFiles(e.target.files)}
              style={{ display: 'none' }}
              disabled={isWorking}
            />
            <div className="drop-zone-content">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
              </svg>
              <span>Drop images here or click to browse</span>
              <span className="drop-zone-hint">JPG, PNG, GIF, WEBP — up to 10 images</span>
            </div>
          </div>

          {/* Image previews */}
          {imagePreviews.length > 0 && (
            <div className="image-previews">
              {imagePreviews.map((img, index) => (
                <div key={index} className="image-preview-card">
                  <div className="image-preview-img">
                    <img src={img.previewUrl} alt={img.file.name} />
                    <button
                      type="button"
                      className="image-remove-btn"
                      onClick={() => removeImage(index)}
                      title="Remove image"
                    >
                      &times;
                    </button>
                  </div>
                  <input
                    type="text"
                    className="image-caption-input"
                    placeholder="Add a caption (optional)"
                    value={img.caption}
                    onChange={e => updateCaption(index, e.target.value)}
                    disabled={isWorking}
                  />
                  <span className="image-preview-name">{img.file.name}</span>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Submit */}
        <button
          type="submit"
          className="btn-primary"
          disabled={isWorking || businesses.length === 0}
        >
          {isWorking ? (
            <>
              <span className="btn-spinner" />
              Generating your post...
            </>
          ) : (
            'Generate Preview'
          )}
        </button>
        <p className="submit-hint">Your post will be generated for review before publishing.</p>
      </form>
    </div>
  );
}
