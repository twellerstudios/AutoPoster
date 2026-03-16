import React from 'react';
import './Header.css';

export default function Header() {
  return (
    <header className="header">
      <div className="header-inner">
        <div className="header-logo">
          <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
            <rect width="28" height="28" rx="8" fill="#6366f1" />
            <path d="M8 14 L14 8 L20 14 L14 20 Z" fill="white" opacity="0.9" />
            <circle cx="14" cy="14" r="3" fill="white" />
          </svg>
          <span className="header-title">AutoPoster</span>
        </div>
        <span className="header-badge">AI Content Hub</span>
      </div>
    </header>
  );
}
