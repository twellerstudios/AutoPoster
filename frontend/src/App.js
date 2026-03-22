import React, { useState, useEffect } from 'react';
import { api } from './services/api';
import PostGenerator from './components/PostGenerator';
import LightroomUpload from './components/LightroomUpload';
import Header from './components/Header';
import Toast from './components/Toast';
import './App.css';

const TABS = [
  { id: 'blog', label: 'Blog Generator' },
  { id: 'lightroom', label: 'Photo to Blog' },
];

export default function App() {
  const [businesses, setBusinesses] = useState([]);
  const [loading, setLoading] = useState(true);
  const [toast, setToast] = useState(null);
  const [activeTab, setActiveTab] = useState('blog');

  useEffect(() => {
    api.getBusinesses()
      .then(setBusinesses)
      .catch(() => showToast('Cannot reach backend — is it running?', 'error'))
      .finally(() => setLoading(false));
  }, []);

  function showToast(message, type = 'info') {
    setToast({ message, type, id: Date.now() });
  }

  return (
    <div className="app">
      <Header />
      <main className="main">
        {loading ? (
          <div className="loading-state">
            <Spinner />
            <p>Connecting to backend…</p>
          </div>
        ) : (
          <>
            <nav className="tab-nav">
              {TABS.map(tab => (
                <button
                  key={tab.id}
                  className={`tab-btn ${activeTab === tab.id ? 'active' : ''}`}
                  onClick={() => setActiveTab(tab.id)}
                >
                  {tab.id === 'lightroom' && (
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={{ marginRight: 6 }}>
                      <rect x="3" y="3" width="18" height="18" rx="2" />
                      <circle cx="8.5" cy="8.5" r="1.5" />
                      <path d="M21 15l-5-5L5 21" />
                    </svg>
                  )}
                  {tab.id === 'blog' && (
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={{ marginRight: 6 }}>
                      <path d="M12 20h9" />
                      <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
                    </svg>
                  )}
                  {tab.label}
                </button>
              ))}
            </nav>

            {activeTab === 'blog' && (
              <PostGenerator businesses={businesses} showToast={showToast} />
            )}
            {activeTab === 'lightroom' && (
              <LightroomUpload businesses={businesses} showToast={showToast} />
            )}
          </>
        )}
      </main>
      {toast && (
        <Toast key={toast.id} message={toast.message} type={toast.type} onDismiss={() => setToast(null)} />
      )}
    </div>
  );
}

function Spinner() {
  return (
    <div style={{
      width: 32, height: 32,
      border: '3px solid #334155',
      borderTopColor: '#6366f1',
      borderRadius: '50%',
      animation: 'spin 0.8s linear infinite',
    }} />
  );
}
