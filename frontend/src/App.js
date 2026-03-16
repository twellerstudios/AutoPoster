import React, { useState, useEffect } from 'react';
import { api } from './services/api';
import PostGenerator from './components/PostGenerator';
import Header from './components/Header';
import Toast from './components/Toast';
import './App.css';

export default function App() {
  const [businesses, setBusinesses] = useState([]);
  const [loading, setLoading] = useState(true);
  const [toast, setToast] = useState(null);

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
          <PostGenerator
            businesses={businesses}
            showToast={showToast}
          />
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
