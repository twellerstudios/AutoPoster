import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../services/api';
import './IngestDashboard.css';

export default function IngestDashboard({ showToast }) {
  const [status, setStatus] = useState(null);
  const [bookings, setBookings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [manualPath, setManualPath] = useState('');
  const [manualClient, setManualClient] = useState('');
  const [manualDate, setManualDate] = useState('');
  const [importing, setImporting] = useState(false);

  const fetchStatus = useCallback(async () => {
    try {
      const data = await api.ingestStatus();
      setStatus(data);
    } catch {
      // Backend may not be running yet
    }
  }, []);

  const fetchBookings = useCallback(async () => {
    try {
      const data = await api.ingestBookings();
      setBookings(data.bookings || []);
    } catch {
      // Calendar may not be configured
    }
  }, []);

  useEffect(() => {
    Promise.all([fetchStatus(), fetchBookings()]).finally(() => setLoading(false));
    // Poll status every 5 seconds while active
    const interval = setInterval(fetchStatus, 5000);
    return () => clearInterval(interval);
  }, [fetchStatus, fetchBookings]);

  async function toggleWatcher() {
    try {
      if (status?.watcher?.watching) {
        await api.ingestStop();
        showToast('Card watcher stopped', 'info');
      } else {
        await api.ingestStart();
        showToast('Card watcher started — insert your SD card', 'success');
      }
      await fetchStatus();
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  async function handleManualImport(e) {
    e.preventDefault();
    if (!manualPath) {
      showToast('Enter a source folder path', 'error');
      return;
    }
    setImporting(true);
    try {
      const result = await api.ingestImport({
        sourcePath: manualPath,
        clientName: manualClient || undefined,
        sessionDate: manualDate || undefined,
      });
      showToast(
        `Imported ${result.stats.copied} photos → ${result.destination}`,
        result.stats.errors > 0 ? 'warning' : 'success'
      );
      setManualPath('');
      setManualClient('');
      setManualDate('');
      await fetchStatus();
    } catch (err) {
      showToast(err.message, 'error');
    } finally {
      setImporting(false);
    }
  }

  if (loading) return <div className="ingest-loading">Loading ingest status…</div>;

  const watching = status?.watcher?.watching;
  const activeImport = status?.import?.activeImport;
  const recentImports = status?.import?.recentImports || [];
  const calendarConfigured = status?.import?.googleCalendarConfigured;

  return (
    <div className="ingest-dashboard">
      <div className="ingest-header">
        <h2>Photo Ingest</h2>
        <p className="ingest-subtitle">
          Auto-import from SD card → Sort by booking → Imagen AI → Lightroom → WordPress
        </p>
      </div>

      {/* Workflow Pipeline Visual */}
      <div className="pipeline-visual">
        <div className="pipeline-step active">
          <div className="step-icon">📷</div>
          <div className="step-label">SD Card</div>
        </div>
        <div className="pipeline-arrow">→</div>
        <div className={`pipeline-step ${watching ? 'active' : ''}`}>
          <div className="step-icon">📂</div>
          <div className="step-label">Auto-Sort</div>
        </div>
        <div className="pipeline-arrow">→</div>
        <div className="pipeline-step">
          <div className="step-icon">🤖</div>
          <div className="step-label">Imagen AI</div>
        </div>
        <div className="pipeline-arrow">→</div>
        <div className="pipeline-step">
          <div className="step-icon">🎨</div>
          <div className="step-label">Lightroom</div>
        </div>
        <div className="pipeline-arrow">→</div>
        <div className="pipeline-step">
          <div className="step-icon">🌐</div>
          <div className="step-label">WordPress</div>
        </div>
      </div>

      {/* Card Watcher Controls */}
      <div className="ingest-section">
        <div className="section-header">
          <h3>SD Card Watcher</h3>
          <button
            className={`watcher-btn ${watching ? 'watching' : ''}`}
            onClick={toggleWatcher}
          >
            {watching ? 'Stop Watching' : 'Start Watching'}
          </button>
        </div>

        <div className="watcher-status">
          <span className={`status-dot ${watching ? 'active' : 'inactive'}`} />
          <span>
            {watching
              ? 'Watching for SD cards…'
              : 'Not watching — click Start to begin'}
          </span>
        </div>

        {watching && status?.watcher?.knownDrives?.length > 0 && (
          <div className="known-drives">
            <strong>Detected drives:</strong>{' '}
            {status.watcher.knownDrives.join(', ')}
          </div>
        )}

        {!calendarConfigured && (
          <div className="config-warning">
            Google Calendar not configured — photos will be sorted by date only.
            Add <code>GOOGLE_CALENDAR_API_KEY</code> to your .env file to match
            bookings automatically.
          </div>
        )}
      </div>

      {/* Active Import Progress */}
      {activeImport && (
        <div className="ingest-section import-active">
          <h3>Importing…</h3>
          <div className="import-progress">
            <div
              className="progress-bar"
              style={{
                width: `${(activeImport.copiedFiles / activeImport.totalFiles) * 100}%`,
              }}
            />
          </div>
          <p>
            {activeImport.copiedFiles} / {activeImport.totalFiles} files from{' '}
            {activeImport.sourceDrive}
          </p>
        </div>
      )}

      {/* Manual Import */}
      <div className="ingest-section">
        <h3>Manual Import</h3>
        <p className="section-desc">Import from a specific folder (e.g., SD card path or desktop folder)</p>
        <form className="manual-import-form" onSubmit={handleManualImport}>
          <div className="form-row">
            <label>Source Folder</label>
            <input
              type="text"
              placeholder="E:\DCIM\100CANON"
              value={manualPath}
              onChange={(e) => setManualPath(e.target.value)}
            />
          </div>
          <div className="form-row">
            <label>Client Name <span className="optional">(optional — auto-matched from calendar)</span></label>
            <input
              type="text"
              placeholder="John Smith"
              value={manualClient}
              onChange={(e) => setManualClient(e.target.value)}
            />
          </div>
          <div className="form-row">
            <label>Session Date <span className="optional">(optional — from photo metadata)</span></label>
            <input
              type="date"
              value={manualDate}
              onChange={(e) => setManualDate(e.target.value)}
            />
          </div>
          <button type="submit" className="import-btn" disabled={importing || !manualPath}>
            {importing ? 'Importing…' : 'Import Photos'}
          </button>
        </form>
      </div>

      {/* Upcoming Bookings */}
      {bookings.length > 0 && (
        <div className="ingest-section">
          <h3>Upcoming Bookings</h3>
          <div className="bookings-list">
            {bookings.map((b) => (
              <div key={b.id} className="booking-card">
                <div className="booking-client">{b.clientName}</div>
                <div className="booking-date">
                  {new Date(b.start).toLocaleDateString('en-GB', {
                    weekday: 'short',
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                  })}
                </div>
                <div className="booking-title">{b.title}</div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Recent Imports */}
      {recentImports.length > 0 && (
        <div className="ingest-section">
          <h3>Recent Imports</h3>
          <div className="imports-list">
            {recentImports.map((imp) => (
              <div key={imp.id} className={`import-card ${imp.status}`}>
                <div className="import-card-header">
                  <span className={`import-status ${imp.status}`}>
                    {imp.status === 'completed' && '✓'}
                    {imp.status === 'completed_with_errors' && '⚠'}
                    {imp.status === 'failed' && '✗'}
                    {' '}{imp.status.replace(/_/g, ' ')}
                  </span>
                  <span className="import-time">
                    {new Date(imp.timestamp).toLocaleString()}
                  </span>
                </div>
                {imp.clientName && (
                  <div className="import-client">{imp.clientName}</div>
                )}
                {imp.destination && (
                  <div className="import-dest">{imp.destination}</div>
                )}
                {imp.stats && (
                  <div className="import-stats">
                    {imp.stats.copied} copied
                    {imp.stats.skipped > 0 && `, ${imp.stats.skipped} skipped`}
                    {imp.stats.errors > 0 && `, ${imp.stats.errors} errors`}
                    {' · '}
                    {(imp.stats.durationMs / 1000).toFixed(1)}s
                  </div>
                )}
                {imp.booking && (
                  <div className="import-booking">
                    Matched: {imp.booking.eventTitle}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Setup Instructions */}
      <div className="ingest-section setup-info">
        <h3>Tweller Flow Setup</h3>
        <ol className="setup-steps">
          <li>
            <strong>Google Calendar:</strong> Add <code>GOOGLE_CALENDAR_API_KEY</code> and{' '}
            <code>GOOGLE_CALENDAR_ID</code> to your .env file. Your SureCart → OttoKit
            automation creates calendar events that this reads.
          </li>
          <li>
            <strong>Import Destination:</strong> Set <code>IMPORT_DESTINATION</code> in .env
            (default: <code>D:\Photography\Sessions</code>). Photos are sorted into{' '}
            <code>dd-mm-yyyy-CLIENTS-NAME</code> folders.
          </li>
          <li>
            <strong>Imagen AI:</strong> Point Imagen AI to watch your import destination
            folder. It will auto-cull and edit new imports.
          </li>
          <li>
            <strong>Lightroom:</strong> Open the culled/edited folder in Lightroom for
            final review, then export via the AutoPoster plugin to publish to WordPress.
          </li>
        </ol>
      </div>
    </div>
  );
}
