import React, { useState, useEffect, useCallback } from 'react';
import { api } from './services/api';
import PostGenerator from './components/PostGenerator';
import Settings from './components/Settings';
import Header from './components/Header';
import Toast from './components/Toast';
import './App.css';

export default function App() {
  const [businesses, setBusinesses] = useState([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState('create'); // create | settings
  const [toast, setToast] = useState(null);
  const [health, setHealth] = useState(null);

  const showToast = useCallback((message, type = 'info') => {
    setToast({ message, type, id: Date.now() });
  }, []);

  const refreshBusinesses = useCallback(() => {
    return api.getBusinesses()
      .then(setBusinesses)
      .catch(() => {});
  }, []);

  useEffect(() => {
    Promise.all([
      api.getBusinesses().catch(() => []),
      api.health().catch(() => null),
    ]).then(([biz, h]) => {
      setBusinesses(biz);
      setHealth(h);

      // Guide new users to Settings if no businesses configured
      if (biz.length === 0) {
        setPage('settings');
        showToast('Welcome! Add a business to get started.', 'info');
      }
    }).finally(() => setLoading(false));
  }, [showToast]);

  return (
    <div className="app">
      <Header page={page} onNavigate={setPage} />
      <main className="main">
        {loading ? (
          <div className="loading-state">
            <Spinner />
            <p>Connecting to backend...</p>
          </div>
        ) : page === 'settings' ? (
          <Settings
            showToast={showToast}
            onBusinessesChanged={refreshBusinesses}
            onNavigate={setPage}
          />
        ) : (
          <PostGenerator
            businesses={businesses}
            showToast={showToast}
            hasApiKey={health?.hasApiKey}
            onGoToSettings={() => setPage('settings')}
          />
        )}
      </main>
      {toast && (
        <Toast
          key={toast.id}
          message={toast.message}
          type={toast.type}
          onDismiss={() => setToast(null)}
        />
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
