import React, { useState, useRef, useCallback } from 'react';
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

export default function PostGenerator({ businesses, showToast, hasApiKey, onGoToSettings }) {
  const [form, setForm] = useState({
    businessId: businesses[0]?.id || '',
    topic: '',
    tone: 'professional',
    wordCount: 1000,
    keywords: '',
  });

  // Image upload state
  const [images, setImages] = useState([]);
  const [imagePreviews, setImagePreviews] = useState([]);
  const fileInputRef = useRef(null);
  const [isDragging, setIsDragging] = useState(false);

  // Workflow state: input | generating | manual-prompt | manual-paste | preview | publishing | published
  const [step, setStep] = useState('input');
  const [previewData, setPreviewData] = useState(null);
  const [publishResult, setPublishResult] = useState(null);
  const [testingConn, setTestingConn] = useState(false);
  const [errorInfo, setErrorInfo] = useState(null);

  // Platform selection for publishing
  const [selectedPlatforms, setSelectedPlatforms] = useState(['wordpress']);

  // Manual mode state
  const [manualPrompt, setManualPrompt] = useState('');
  const [manualSessionId, setManualSessionId] = useState('');
  const [pastedContent, setPastedContent] = useState('');
  const [parsingContent, setParsingContent] = useState(false);

  function set(field, value) {
    setForm(f => ({ ...f, [field]: value }));
    setErrorInfo(null);
  }

  // ── Image handling ──────────────────────────────────────────────────────────

  function addFiles(files) {
    const validFiles = Array.from(files).filter(f =>
      /\.(jpg|jpeg|png|gif|webp)$/i.test(f.name)
    );
    if (validFiles.length === 0) {
      showToast('Only image files are allowed (JPG, PNG, GIF, WEBP)', 'error');
      return;
    }
    if (images.length + validFiles.length > 10) {
      showToast('You can upload up to 10 images per post.', 'warning');
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

  function handleDragOver(e) { e.preventDefault(); setIsDragging(true); }
  function handleDragLeave(e) { e.preventDefault(); setIsDragging(false); }
  function handleDrop(e) { e.preventDefault(); setIsDragging(false); addFiles(e.dataTransfer.files); }

  // ── Connection test ─────────────────────────────────────────────────────────

  async function handleTestConnection() {
    if (!form.businessId) return;
    setTestingConn(true);
    try {
      const r = await api.testBusiness(form.businessId);
      showToast(r.message || `Connected as "${r.user}"`, 'success');
    } catch (err) {
      showToast(err.hint || `Connection failed: ${err.message}`, 'error');
    } finally {
      setTestingConn(false);
    }
  }

  // ── Generate (API mode — automatic) ─────────────────────────────────────────

  async function handleGenerateAPI(e) {
    e.preventDefault();
    if (!validateForm()) return;

    setStep('generating');
    setPreviewData(null);
    setPublishResult(null);
    setErrorInfo(null);

    try {
      const data = await api.generatePost(buildFormPayload());
      setPreviewData(data);
      setStep('preview');
      showToast('Blog post generated! Review it below before publishing.', 'success');
    } catch (err) {
      setStep('input');
      setErrorInfo({ message: err.message, hint: err.hint });
      showToast(err.message, 'error');
    }
  }

  // ── Generate (Manual mode — free) ───────────────────────────────────────────

  async function handleGenerateManual(e) {
    e.preventDefault();
    if (!validateForm()) return;

    setStep('generating');
    setErrorInfo(null);

    try {
      const data = await api.getManualPrompt(buildFormPayload());
      setManualPrompt(data.prompt);
      setManualSessionId(data.sessionId);
      setStep('manual-prompt');
    } catch (err) {
      setStep('input');
      setErrorInfo({ message: err.message, hint: err.hint });
      showToast(err.message, 'error');
    }
  }

  async function handlePasteContent() {
    if (!pastedContent.trim()) {
      showToast('Please paste Claude\'s response first.', 'error');
      return;
    }

    setParsingContent(true);
    setErrorInfo(null);

    try {
      const data = await api.parseManualContent({
        sessionId: manualSessionId,
        content: pastedContent,
      });
      setPreviewData(data);
      setStep('preview');
      showToast('Content parsed successfully! Review your post below.', 'success');
    } catch (err) {
      setErrorInfo({ message: err.message, hint: err.hint });
      showToast(err.hint || err.message, 'error');
    } finally {
      setParsingContent(false);
    }
  }

  // ── Publish ─────────────────────────────────────────────────────────────────

  async function handlePublish({ title, htmlContent }) {
    if (selectedPlatforms.length === 0) {
      showToast('Please select at least one platform to publish to.', 'error');
      return;
    }
    setStep('publishing');
    setErrorInfo(null);

    try {
      const data = await api.publishPost({
        previewId: previewData.previewId,
        title,
        htmlContent,
        platforms: selectedPlatforms,
      });
      setPublishResult(data);
      setStep('published');

      // Build success message based on results
      const platformResults = data.platforms || {};
      const successPlatforms = Object.entries(platformResults)
        .filter(([, v]) => v.success)
        .map(([k]) => k.charAt(0).toUpperCase() + k.slice(1));
      const failedPlatforms = Object.entries(platformResults)
        .filter(([, v]) => !v.success)
        .map(([k]) => k.charAt(0).toUpperCase() + k.slice(1));

      if (failedPlatforms.length > 0) {
        showToast(
          `Published to ${successPlatforms.join(', ')}. Failed on: ${failedPlatforms.join(', ')}. Check the results for details.`,
          'warning'
        );
      } else {
        showToast(`Published to ${successPlatforms.join(' & ')}!`, 'success');
      }
    } catch (err) {
      setStep('preview');
      setErrorInfo({ message: err.message, hint: err.hint });
      showToast(err.hint || `Publish failed: ${err.message}`, 'error');
    }
  }

  function togglePlatform(platform) {
    setSelectedPlatforms(prev =>
      prev.includes(platform)
        ? prev.filter(p => p !== platform)
        : [...prev, platform]
    );
  }

  // ── Add images to preview ───────────────────────────────────────────────────

  const handleAddPreviewImages = useCallback(async (files) => {
    if (!previewData?.previewId) return null;

    try {
      const result = await api.addPreviewImages({
        previewId: previewData.previewId,
        images: files,
        imageCaptions: [],
      });

      // Update the preview data with new images and content
      setPreviewData(prev => ({
        ...prev,
        post: {
          ...prev.post,
          userImages: result.userImages,
          htmlContent: result.htmlContent,
        },
      }));

      showToast(`${files.length} image(s) added to your post!`, 'success');
      return result;
    } catch (err) {
      showToast(err.message || 'Failed to add images.', 'error');
      return null;
    }
  }, [previewData?.previewId, showToast]);

  // ── Helpers ─────────────────────────────────────────────────────────────────

  function validateForm() {
    if (!form.businessId) {
      setErrorInfo({
        message: 'Please select a business.',
        hint: 'If no businesses appear, go to Settings to add one.',
      });
      return false;
    }
    if (!form.topic.trim()) {
      setErrorInfo({ message: 'Please enter a topic for your blog post.' });
      return false;
    }
    return true;
  }

  function buildFormPayload() {
    return {
      businessId: form.businessId,
      topic: form.topic.trim(),
      tone: form.tone,
      wordCount: parseInt(form.wordCount),
      keywords: form.keywords,
      images,
      imageCaptions: imagePreviews.map(img => img.caption),
    };
  }

  function handleBackToInput() {
    setStep('input');
    setPreviewData(null);
    setPublishResult(null);
    setErrorInfo(null);
    setManualPrompt('');
    setPastedContent('');
  }

  function handleNewPost() {
    setForm(f => ({ ...f, topic: '', keywords: '' }));
    setImages([]);
    setImagePreviews([]);
    setStep('input');
    setPreviewData(null);
    setPublishResult(null);
    setErrorInfo(null);
    setManualPrompt('');
    setPastedContent('');
    setSelectedPlatforms(['wordpress']);
  }

  const selectedBusiness = businesses.find(b => b.id === form.businessId);
  const isWorking = step === 'generating' || step === 'publishing';

  // ── Manual prompt step ──────────────────────────────────────────────────────

  if (step === 'manual-prompt') {
    return (
      <div className="generator">
        <div className="generator-header">
          <div className="step-indicator">
            <span className="step done">1. Create</span>
            <span className="step-arrow">&rarr;</span>
            <span className="step active">2. Copy to Claude</span>
            <span className="step-arrow">&rarr;</span>
            <span className="step">3. Review</span>
            <span className="step-arrow">&rarr;</span>
            <span className="step">4. Publish</span>
          </div>
        </div>

        <div className="manual-section">
          <div className="manual-banner">
            <strong>Free AI Mode</strong>
            <span>Use your Claude subscription — no API costs</span>
          </div>

          <div className="manual-instructions">
            <div className="manual-step-list">
              <div className="manual-step-item">
                <span className="manual-step-num">1</span>
                <span>Copy the prompt below</span>
              </div>
              <div className="manual-step-item">
                <span className="manual-step-num">2</span>
                <span>Open <a href="https://claude.ai" target="_blank" rel="noopener noreferrer">claude.ai</a> and paste it in a new chat</span>
              </div>
              <div className="manual-step-item">
                <span className="manual-step-num">3</span>
                <span>Wait for Claude to generate the blog post</span>
              </div>
              <div className="manual-step-item">
                <span className="manual-step-num">4</span>
                <span>Copy Claude's <strong>entire</strong> response and paste it below</span>
              </div>
            </div>
          </div>

          <div className="manual-prompt-box">
            <div className="prompt-header">
              <label>Your AI Prompt</label>
              <button
                className="btn-sm"
                onClick={() => {
                  navigator.clipboard.writeText(manualPrompt);
                  showToast('Prompt copied! Now paste it into claude.ai', 'success');
                }}
              >
                Copy to Clipboard
              </button>
            </div>
            <textarea
              className="prompt-text"
              value={manualPrompt}
              readOnly
              rows={8}
              onClick={e => e.target.select()}
            />
          </div>

          <div className="manual-paste-box">
            <label>Paste Claude's Response Here</label>
            <textarea
              className="paste-text"
              value={pastedContent}
              onChange={e => setPastedContent(e.target.value)}
              placeholder="After Claude generates the blog post, copy its entire response and paste it here..."
              rows={12}
            />
            {errorInfo && (
              <div className="inline-error">
                <p>{errorInfo.message}</p>
                {errorInfo.hint && <p className="error-hint">{errorInfo.hint}</p>}
              </div>
            )}
          </div>

          <div className="manual-actions">
            <button className="btn-ghost" onClick={handleBackToInput}>
              &larr; Back
            </button>
            <button
              className="btn-primary"
              onClick={handlePasteContent}
              disabled={parsingContent || !pastedContent.trim()}
            >
              {parsingContent ? (
                <>
                  <span className="btn-spinner" />
                  Processing...
                </>
              ) : (
                'Continue to Preview'
              )}
            </button>
          </div>
        </div>
      </div>
    );
  }

  // ── Preview / Published view ────────────────────────────────────────────────

  if (step === 'preview' || step === 'publishing' || step === 'published') {
    return (
      <div className="generator">
        <div className="generator-header">
          <div className="step-indicator">
            <span className="step done">1. Create</span>
            <span className="step-arrow">&rarr;</span>
            <span className={`step ${step === 'preview' || step === 'publishing' ? 'active' : 'done'}`}>
              {hasApiKey ? '2. Review' : '3. Review'}
            </span>
            <span className="step-arrow">&rarr;</span>
            <span className={`step ${step === 'published' ? 'done' : ''}`}>
              {hasApiKey ? '3. Publish' : '4. Publish'}
            </span>
          </div>
        </div>

        <PostPreview
          data={step === 'published' ? publishResult : previewData}
          step={step}
          businessName={selectedBusiness?.name}
          business={selectedBusiness}
          onPublish={handlePublish}
          onBack={handleBackToInput}
          onNewPost={handleNewPost}
          onAddImages={handleAddPreviewImages}
          errorInfo={errorInfo}
          selectedPlatforms={selectedPlatforms}
          onTogglePlatform={togglePlatform}
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
          {!hasApiKey && (
            <>
              <span className="step">2. Copy to Claude</span>
              <span className="step-arrow">&rarr;</span>
            </>
          )}
          <span className="step">{hasApiKey ? '2. Review' : '3. Review'}</span>
          <span className="step-arrow">&rarr;</span>
          <span className="step">{hasApiKey ? '3. Publish' : '4. Publish'}</span>
        </div>
      </div>

      {/* No businesses warning */}
      {businesses.length === 0 && (
        <div className="warning-box">
          <strong>No businesses configured</strong>
          <p>You need to add at least one business (with WordPress connection) before you can create posts.</p>
          <button className="btn-primary" onClick={onGoToSettings}>Go to Settings</button>
        </div>
      )}

      {/* Error display */}
      {errorInfo && (
        <div className="error-box">
          <p className="error-message">{errorInfo.message}</p>
          {errorInfo.hint && <p className="error-hint">{errorInfo.hint}</p>}
          <button className="error-dismiss" onClick={() => setErrorInfo(null)}>&times;</button>
        </div>
      )}

      <form className="form" onSubmit={hasApiKey ? handleGenerateAPI : handleGenerateManual}>

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
                <option value="">No businesses — add one in Settings</option>
              )}
              {businesses.map(b => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
            {businesses.length > 0 && (
              <button
                type="button"
                className="btn-ghost"
                onClick={handleTestConnection}
                disabled={testingConn || isWorking || !form.businessId}
              >
                {testingConn ? 'Testing...' : 'Test Connection'}
              </button>
            )}
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
            placeholder='e.g. Make me a blog post on "World Down Syndrome Day" with awareness info and resources'
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
            <select id="tone" value={form.tone} onChange={e => set('tone', e.target.value)} disabled={isWorking}>
              {TONES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="wordCount">Length</label>
            <select id="wordCount" value={form.wordCount} onChange={e => set('wordCount', e.target.value)} disabled={isWorking}>
              {WORD_COUNTS.map(w => <option key={w.value} value={w.value}>{w.label}</option>)}
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
              onChange={e => { addFiles(e.target.files); e.target.value = ''; }}
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
              <span className="drop-zone-hint">JPG, PNG, GIF, WEBP — up to 10 images, 10 MB each</span>
            </div>
          </div>

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

        {/* AI mode indicator + submit */}
        <div className="ai-mode-indicator">
          {hasApiKey ? (
            <span className="mode-tag api">Automatic AI Mode</span>
          ) : (
            <span className="mode-tag free">Free Mode — uses your Claude subscription</span>
          )}
          <button
            type="button"
            className="mode-switch"
            onClick={onGoToSettings}
          >
            Change in Settings
          </button>
        </div>

        <button
          type="submit"
          className="btn-primary"
          disabled={isWorking || businesses.length === 0}
        >
          {isWorking ? (
            <>
              <span className="btn-spinner" />
              {hasApiKey ? 'Generating your post...' : 'Preparing your prompt...'}
            </>
          ) : (
            hasApiKey ? 'Generate Preview' : 'Generate Prompt'
          )}
        </button>
        <p className="submit-hint">
          {hasApiKey
            ? 'Your post will be generated for review before publishing.'
            : 'A prompt will be generated for you to paste into claude.ai (free).'}
        </p>
      </form>
    </div>
  );
}
