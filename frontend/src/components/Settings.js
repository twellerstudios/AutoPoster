import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import './Settings.css';

export default function Settings({ showToast, onBusinessesChanged, onNavigate }) {
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [networkInfo, setNetworkInfo] = useState(null);

  // API key form
  const [anthropicKey, setAnthropicKey] = useState('');
  const [pexelsKey, setPexelsKey] = useState('');
  const [savingKeys, setSavingKeys] = useState(false);

  // Add business form
  const [showAddForm, setShowAddForm] = useState(false);
  const [addForm, setAddForm] = useState({ name: '', wordpressUrl: '', wordpressUsername: '', wordpressAppPassword: '' });
  const [addingBusiness, setAddingBusiness] = useState(false);
  const [addErrors, setAddErrors] = useState([]);

  // Edit business
  const [editingId, setEditingId] = useState(null);
  const [editForm, setEditForm] = useState({});
  const [editingBusiness, setEditingBusiness] = useState(false);

  // Test connection
  const [testingId, setTestingId] = useState(null);

  // Delete confirmation
  const [confirmDeleteId, setConfirmDeleteId] = useState(null);

  useEffect(() => {
    loadSettings();
    loadNetworkInfo();
  }, []);

  async function loadSettings() {
    try {
      const data = await api.getSettings();
      setSettings(data);
    } catch (err) {
      showToast(`Failed to load settings: ${err.message}`, 'error');
    } finally {
      setLoading(false);
    }
  }

  async function loadNetworkInfo() {
    try {
      const data = await api.getNetworkInfo();
      setNetworkInfo(data);
    } catch {
      // Non-fatal
    }
  }

  // ── AI Mode ──────────────────────────────────────────────────────────────

  async function handleSetAiMode(mode) {
    if (mode === 'auto' && !settings?.anthropicApiKeySet) {
      showToast('Add an Anthropic API key below first to use Automatic Mode.', 'warning');
      return;
    }
    try {
      await api.updateSettings({ aiMode: mode });
      await loadSettings();
      onBusinessesChanged(); // refresh health so PostGenerator picks up the new mode
      showToast(`Switched to ${mode === 'manual' ? 'Manual' : 'Automatic'} Mode.`, 'success');
    } catch (err) {
      showToast(`Failed to switch mode: ${err.message}`, 'error');
    }
  }

  // ── API Keys ──────────────────────────────────────────────────────────────

  async function handleSaveKeys(e) {
    e.preventDefault();
    setSavingKeys(true);
    try {
      const body = {};
      if (anthropicKey) body.anthropicApiKey = anthropicKey;
      if (pexelsKey) body.pexelsApiKey = pexelsKey;

      if (Object.keys(body).length === 0) {
        showToast('Enter at least one API key to save.', 'warning');
        setSavingKeys(false);
        return;
      }

      await api.updateSettings(body);
      setAnthropicKey('');
      setPexelsKey('');
      await loadSettings();
      showToast('API keys saved successfully.', 'success');
    } catch (err) {
      showToast(`Failed to save: ${err.message}`, 'error');
    } finally {
      setSavingKeys(false);
    }
  }

  async function handleClearKey(keyName) {
    try {
      await api.updateSettings({ [keyName]: '' });
      await loadSettings();
      showToast('API key removed.', 'success');
    } catch (err) {
      showToast(`Failed to remove key: ${err.message}`, 'error');
    }
  }

  // ── Add Business ──────────────────────────────────────────────────────────

  async function handleAddBusiness(e) {
    e.preventDefault();
    setAddingBusiness(true);
    setAddErrors([]);

    try {
      const result = await api.addBusiness(addForm);
      showToast(result.message, 'success');
      setShowAddForm(false);
      setAddForm({ name: '', wordpressUrl: '', wordpressUsername: '', wordpressAppPassword: '' });
      await loadSettings();
      onBusinessesChanged();
    } catch (err) {
      if (err.details && err.details.length > 0) {
        setAddErrors(err.details);
      } else {
        setAddErrors([err.message]);
      }
    } finally {
      setAddingBusiness(false);
    }
  }

  // ── Edit Business ─────────────────────────────────────────────────────────

  function startEditing(biz) {
    setEditingId(biz.id);
    setEditForm({
      name: biz.name,
      wordpressUrl: biz.wordpressUrl,
      wordpressUsername: biz.wordpressUsername,
      wordpressAppPassword: '',
    });
  }

  async function handleSaveEdit(e) {
    e.preventDefault();
    setEditingBusiness(true);
    try {
      const body = { ...editForm };
      // Only send password if user typed a new one
      if (!body.wordpressAppPassword) delete body.wordpressAppPassword;

      await api.updateBusiness(editingId, body);
      setEditingId(null);
      await loadSettings();
      onBusinessesChanged();
      showToast('Business updated.', 'success');
    } catch (err) {
      showToast(`Failed to update: ${err.message}`, 'error');
    } finally {
      setEditingBusiness(false);
    }
  }

  // ── Test Connection ───────────────────────────────────────────────────────

  async function handleTestConnection(id) {
    setTestingId(id);
    try {
      const result = await api.testBusiness(id);
      showToast(result.message || `Connected as "${result.user}"`, 'success');
    } catch (err) {
      showToast(err.hint || err.message, 'error');
    } finally {
      setTestingId(null);
    }
  }

  // ── Delete Business ───────────────────────────────────────────────────────

  async function handleDeleteBusiness(id) {
    try {
      const result = await api.deleteBusiness(id);
      showToast(result.message, 'success');
      setConfirmDeleteId(null);
      await loadSettings();
      onBusinessesChanged();
    } catch (err) {
      showToast(`Failed to delete: ${err.message}`, 'error');
    }
  }

  if (loading) {
    return <div className="settings-loading">Loading settings...</div>;
  }

  return (
    <div className="settings">
      <div className="settings-header">
        <h1>Settings</h1>
        <p className="settings-subtitle">Manage your businesses, API keys, and app configuration. No terminal needed.</p>
      </div>

      {/* ── Network Access ── */}
      {networkInfo && networkInfo.addresses.length > 0 && (
        <section className="settings-section">
          <h2 className="section-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M5 12.55a11 11 0 0 1 14.08 0" /><path d="M1.42 9a16 16 0 0 1 21.16 0" /><path d="M8.53 16.11a6 6 0 0 1 6.95 0" /><circle cx="12" cy="20" r="1" />
            </svg>
            Access From Other Devices
          </h2>
          <p className="section-desc">Use these URLs to open AutoPoster on your phone, tablet, or other computers on your network.</p>
          <div className="network-list">
            {networkInfo.addresses.map((addr, i) => (
              <div key={i} className="network-item">
                <code className="network-url">{addr.url}</code>
                <span className="network-iface">{addr.interface}</span>
                <button
                  className="btn-sm"
                  onClick={() => {
                    navigator.clipboard.writeText(addr.url);
                    showToast('URL copied to clipboard', 'success');
                  }}
                >
                  Copy
                </button>
              </div>
            ))}
          </div>
          <p className="section-note">For the React frontend, use port 3000 instead of {networkInfo.port}. Both devices must be on the same Wi-Fi network.</p>
        </section>
      )}

      {/* ── AI Mode ── */}
      <section className="settings-section">
        <h2 className="section-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z" /><path d="M2 17l10 5 10-5" /><path d="M2 12l10 5 10-5" />
          </svg>
          AI Content Generation
        </h2>

        <div className="ai-mode-cards">
          <div
            className={`ai-mode-card clickable ${settings?.aiMode === 'manual' || (!settings?.aiMode && !settings?.anthropicApiKeySet) ? 'active' : ''}`}
            onClick={() => handleSetAiMode('manual')}
          >
            <div className="ai-mode-badge free">FREE</div>
            <h3>Manual Mode</h3>
            <p>Uses your $20/mo Claude subscription at claude.ai. The app generates the perfect prompt — you copy it to Claude, get the response, and paste it back. No API costs.</p>
            {(settings?.aiMode === 'manual' || (!settings?.aiMode && !settings?.anthropicApiKeySet)) && <span className="ai-mode-status">Currently Active</span>}
          </div>

          <div
            className={`ai-mode-card clickable ${settings?.aiMode === 'auto' || (!settings?.aiMode && settings?.anthropicApiKeySet) ? 'active' : ''}`}
            onClick={() => handleSetAiMode('auto')}
          >
            <div className="ai-mode-badge api">API</div>
            <h3>Automatic Mode</h3>
            <p>Uses the Anthropic API for fully automated generation. Requires an API key with separate billing from your Claude subscription.</p>
            {(settings?.aiMode === 'auto' || (!settings?.aiMode && settings?.anthropicApiKeySet)) && <span className="ai-mode-status">Currently Active</span>}
          </div>
        </div>

        <form className="api-keys-form" onSubmit={handleSaveKeys}>
          <div className="api-key-row">
            <div className="api-key-field">
              <label>Anthropic API Key <span className="optional">(optional — for automatic mode)</span></label>
              <div className="key-input-row">
                <input
                  type="password"
                  value={anthropicKey}
                  onChange={e => setAnthropicKey(e.target.value)}
                  placeholder={settings?.anthropicApiKeySet ? `Current: ${settings.anthropicApiKey}` : 'sk-ant-...'}
                />
                {settings?.anthropicApiKeySet && (
                  <button type="button" className="btn-sm danger" onClick={() => handleClearKey('anthropicApiKey')}>
                    Remove
                  </button>
                )}
              </div>
              <span className="field-hint">Get one at console.anthropic.com/settings/keys (separate billing)</span>
            </div>
          </div>

          <div className="api-key-row">
            <div className="api-key-field">
              <label>Pexels API Key <span className="optional">(optional — for stock images)</span></label>
              <div className="key-input-row">
                <input
                  type="password"
                  value={pexelsKey}
                  onChange={e => setPexelsKey(e.target.value)}
                  placeholder={settings?.pexelsApiKeySet ? `Current: ${settings.pexelsApiKey}` : 'Enter Pexels API key'}
                />
                {settings?.pexelsApiKeySet && (
                  <button type="button" className="btn-sm danger" onClick={() => handleClearKey('pexelsApiKey')}>
                    Remove
                  </button>
                )}
              </div>
              <span className="field-hint">Free at pexels.com/api — gives your posts professional stock photos</span>
            </div>
          </div>

          <button type="submit" className="btn-primary btn-save-keys" disabled={savingKeys || (!anthropicKey && !pexelsKey)}>
            {savingKeys ? 'Saving...' : 'Save API Keys'}
          </button>
        </form>
      </section>

      {/* ── Businesses ── */}
      <section className="settings-section">
        <div className="section-header-row">
          <h2 className="section-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><polyline points="9 22 9 12 15 12 15 22" />
            </svg>
            Businesses
          </h2>
          <button className="btn-primary btn-add" onClick={() => setShowAddForm(true)}>
            + Add Business
          </button>
        </div>
        <p className="section-desc">Each business connects to its own WordPress site. You can switch between them when creating posts.</p>

        {/* Existing businesses */}
        {settings?.businesses?.length > 0 ? (
          <div className="business-list">
            {settings.businesses.map(biz => (
              <div key={biz.id} className="business-card">
                {editingId === biz.id ? (
                  // Edit form
                  <form className="business-edit-form" onSubmit={handleSaveEdit}>
                    <div className="edit-field">
                      <label>Business Name</label>
                      <input value={editForm.name} onChange={e => setEditForm(f => ({ ...f, name: e.target.value }))} required />
                    </div>
                    <div className="edit-field">
                      <label>WordPress URL</label>
                      <input value={editForm.wordpressUrl} onChange={e => setEditForm(f => ({ ...f, wordpressUrl: e.target.value }))} placeholder="https://www.example.com" required />
                    </div>
                    <div className="edit-field">
                      <label>WordPress Username</label>
                      <input value={editForm.wordpressUsername} onChange={e => setEditForm(f => ({ ...f, wordpressUsername: e.target.value }))} required />
                    </div>
                    <div className="edit-field">
                      <label>Application Password <span className="optional">(leave blank to keep current)</span></label>
                      <input type="password" value={editForm.wordpressAppPassword} onChange={e => setEditForm(f => ({ ...f, wordpressAppPassword: e.target.value }))} placeholder="Leave blank to keep current password" />
                    </div>
                    <div className="edit-actions">
                      <button type="submit" className="btn-primary" disabled={editingBusiness}>
                        {editingBusiness ? 'Saving...' : 'Save Changes'}
                      </button>
                      <button type="button" className="btn-ghost" onClick={() => setEditingId(null)}>Cancel</button>
                    </div>
                  </form>
                ) : (
                  // Display
                  <>
                    <div className="business-info">
                      <h3 className="business-name">{biz.name}</h3>
                      <span className="business-url">{biz.wordpressUrl}</span>
                      <span className="business-user">User: {biz.wordpressUsername}</span>
                      <span className="business-pw">
                        Password: {biz.wordpressAppPasswordSet ? 'Configured' : 'Not set'}
                      </span>
                    </div>
                    <div className="business-actions">
                      <button
                        className="btn-sm"
                        onClick={() => handleTestConnection(biz.id)}
                        disabled={testingId === biz.id}
                      >
                        {testingId === biz.id ? 'Testing...' : 'Test'}
                      </button>
                      <button className="btn-sm" onClick={() => startEditing(biz)}>Edit</button>
                      {confirmDeleteId === biz.id ? (
                        <>
                          <button className="btn-sm danger" onClick={() => handleDeleteBusiness(biz.id)}>
                            Confirm Delete
                          </button>
                          <button className="btn-sm" onClick={() => setConfirmDeleteId(null)}>Cancel</button>
                        </>
                      ) : (
                        <button className="btn-sm danger" onClick={() => setConfirmDeleteId(biz.id)}>Delete</button>
                      )}
                    </div>
                  </>
                )}
              </div>
            ))}
          </div>
        ) : (
          <div className="empty-businesses">
            <p>No businesses configured yet.</p>
            <p className="empty-hint">Add your first business to start creating and publishing blog posts.</p>
          </div>
        )}

        {/* Add business form */}
        {showAddForm && (
          <div className="add-business-form">
            <h3>Add New Business</h3>
            {addErrors.length > 0 && (
              <div className="form-errors">
                {addErrors.map((err, i) => (
                  <p key={i} className="form-error">{err}</p>
                ))}
              </div>
            )}
            <form onSubmit={handleAddBusiness}>
              <div className="edit-field">
                <label>Business Name *</label>
                <input
                  value={addForm.name}
                  onChange={e => setAddForm(f => ({ ...f, name: e.target.value }))}
                  placeholder='e.g. "Journey To" or "My Travel Blog"'
                  required
                />
              </div>
              <div className="edit-field">
                <label>WordPress Site URL *</label>
                <input
                  value={addForm.wordpressUrl}
                  onChange={e => setAddForm(f => ({ ...f, wordpressUrl: e.target.value }))}
                  placeholder="https://www.letsjourneyto.com"
                  required
                />
                <span className="field-hint">The main URL of your WordPress site (no trailing slash)</span>
              </div>
              <div className="edit-field">
                <label>WordPress Username *</label>
                <input
                  value={addForm.wordpressUsername}
                  onChange={e => setAddForm(f => ({ ...f, wordpressUsername: e.target.value }))}
                  placeholder="Your WordPress login username"
                  required
                />
              </div>
              <div className="edit-field">
                <label>Application Password *</label>
                <input
                  type="password"
                  value={addForm.wordpressAppPassword}
                  onChange={e => setAddForm(f => ({ ...f, wordpressAppPassword: e.target.value }))}
                  placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                  required
                />
                <span className="field-hint">WordPress Admin &rarr; Users &rarr; Profile &rarr; Application Passwords &rarr; Add New</span>
              </div>
              <div className="edit-actions">
                <button type="submit" className="btn-primary" disabled={addingBusiness}>
                  {addingBusiness ? 'Adding & Testing Connection...' : 'Add Business'}
                </button>
                <button type="button" className="btn-ghost" onClick={() => { setShowAddForm(false); setAddErrors([]); }}>
                  Cancel
                </button>
              </div>
            </form>
          </div>
        )}
      </section>

      {/* Quick start hint if no businesses */}
      {settings?.businesses?.length > 0 && (
        <div className="settings-footer">
          <button className="btn-primary" onClick={() => onNavigate('create')}>
            &larr; Back to Create Post
          </button>
        </div>
      )}
    </div>
  );
}
