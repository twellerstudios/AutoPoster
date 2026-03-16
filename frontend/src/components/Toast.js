import React, { useEffect } from 'react';
import './Toast.css';

export default function Toast({ message, type = 'info', onDismiss }) {
  useEffect(() => {
    const t = setTimeout(onDismiss, type === 'error' ? 6000 : 4000);
    return () => clearTimeout(t);
  }, [onDismiss, type]);

  return (
    <div className={`toast toast--${type}`} role="alert">
      <span className="toast-icon">{icons[type]}</span>
      <span className="toast-message">{message}</span>
      <button className="toast-close" onClick={onDismiss} aria-label="Dismiss">×</button>
    </div>
  );
}

const icons = {
  success: '✓',
  error: '✕',
  info: 'i',
  warning: '!',
};
