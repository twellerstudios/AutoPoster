import React, { useState } from 'react';
import { api } from '../services/api';
import PostResult from './PostResult';
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
    publish: true,
  });
  const [status, setStatus] = useState('idle'); // idle | generating | success | error
  const [result, setResult] = useState(null);
  const [testingConn, setTestingConn] = useState(false);

  function set(field, value) {
    setForm(f => ({ ...f, [field]: value }));
  }

  async function handleTestConnection() {
    if (!form.businessId) return;
    setTestingConn(true);
    try {
      const r = await api.testBusiness(form.businessId);
      showToast(`Connected as "${r.user}" ✓`, 'success');
    } catch (err) {
      showToast(`Connection failed: ${err.message}`, 'error');
    } finally {
      setTestingConn(false);
    }
  }

  async function handleSubmit(e) {
    e.preventDefault();
    if (!form.topic.trim()) {
      showToast('Please enter a topic', 'error');
      return;
    }

    setStatus('generating');
    setResult(null);

    try {
      const keywords = form.keywords
        .split(',')
        .map(k => k.trim())
        .filter(Boolean);

      const data = await api.generatePost({
        businessId: form.businessId,
        topic: form.topic.trim(),
        tone: form.tone,
        wordCount: parseInt(form.wordCount),
        keywords,
        publish: form.publish,
      });

      setResult(data);
      setStatus('success');
      showToast(
        form.publish ? 'Post published successfully!' : 'Post generated successfully!',
        'success'
      );
    } catch (err) {
      setStatus('error');
      showToast(err.message, 'error');
    }
  }

  const selectedBusiness = businesses.find(b => b.id === form.businessId);
  const isGenerating = status === 'generating';

  return (
    <div className="generator">
      <div className="generator-header">
        <h1>Create a Blog Post</h1>
        <p className="subtitle">Generate SEO-optimised content with Claude AI and publish directly to WordPress.</p>
      </div>

      <form className="form" onSubmit={handleSubmit}>

        {/* Business selector */}
        <div className="field">
          <label htmlFor="businessId">Business</label>
          <div className="select-row">
            <select
              id="businessId"
              value={form.businessId}
              onChange={e => set('businessId', e.target.value)}
              disabled={isGenerating}
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
              disabled={testingConn || isGenerating || !form.businessId}
            >
              {testingConn ? 'Testing…' : 'Test Connection'}
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
            placeholder="e.g. Top 10 hidden gems in Bali for adventurous travellers"
            rows={3}
            disabled={isGenerating}
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
              disabled={isGenerating}
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
              disabled={isGenerating}
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
            placeholder="travel tips Bali, Bali hidden beaches, adventurous travel"
            disabled={isGenerating}
          />
          <span className="field-hint">Comma-separated keywords to weave into the post</span>
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
              disabled={isGenerating}
            >
              <span className="toggle-thumb" />
            </button>
          </div>
        </div>

        {/* Submit */}
        <button
          type="submit"
          className="btn-primary"
          disabled={isGenerating || businesses.length === 0}
        >
          {isGenerating ? (
            <>
              <span className="btn-spinner" />
              Generating your post…
            </>
          ) : (
            form.publish ? 'Generate & Publish' : 'Generate Preview'
          )}
        </button>
      </form>

      {/* Result */}
      {result && status === 'success' && (
        <PostResult result={result} />
      )}
    </div>
  );
}
