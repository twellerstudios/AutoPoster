/**
 * Session Matcher Service
 *
 * Queries WordPress (Tweller Flow) for booked sessions and matches
 * photo groups to sessions based on date and time.
 *
 * Matching strategy:
 *   1. Read EXIF timestamps from imported photos
 *   2. Group photos by time window (photos within 2hrs = same session)
 *   3. Query WP for sessions booked on those dates
 *   4. Match each photo group to the closest session by time
 *   5. If one session on a date → auto-assign all photos from that date
 *   6. If multiple sessions on same date → match by closest time
 *   7. If no session found → hold in unmatched folder for manual assignment
 */
const axios = require('axios');
const { readAllTimestamps, groupBySession, formatDate, formatTime } = require('./exifService');

class SessionMatcher {
  constructor(options = {}) {
    this.wpUrl = options.wpUrl || process.env.WP_AUTOMATION_URL || '';
    this.wpApiKey = options.wpApiKey || process.env.WP_AUTOMATION_API_KEY || '';
    this.gapMinutes = options.gapMinutes || parseInt(process.env.SESSION_GAP_MINUTES || '120', 10);
  }

  /**
   * Fetch all sessions from WordPress for a specific date.
   *
   * @param {string} date - Date in YYYY-MM-DD format
   * @returns {Array} Sessions booked on that date
   */
  async fetchSessionsByDate(date) {
    if (!this.wpUrl) {
      console.log('[Matcher] WP not configured — cannot fetch sessions');
      return [];
    }

    try {
      const response = await axios.get(
        `${this.wpUrl}/wp-json/tweller-flow/v1/sessions`,
        {
          params: { date },
          headers: {
            Authorization: `Bearer ${this.wpApiKey}`,
            'Content-Type': 'application/json',
          },
          timeout: 15000,
        }
      );

      return response.data || [];
    } catch (err) {
      console.warn(`[Matcher] Failed to fetch sessions for ${date}:`, err.message);
      return [];
    }
  }

  /**
   * Fetch all upcoming/recent sessions (last 7 days + next 7 days).
   * Used as a broader search when exact date match fails.
   */
  async fetchRecentSessions() {
    if (!this.wpUrl) return [];

    try {
      const response = await axios.get(
        `${this.wpUrl}/wp-json/tweller-flow/v1/sessions`,
        {
          params: { range: 'recent' },
          headers: {
            Authorization: `Bearer ${this.wpApiKey}`,
            'Content-Type': 'application/json',
          },
          timeout: 15000,
        }
      );

      return response.data || [];
    } catch (err) {
      console.warn('[Matcher] Failed to fetch recent sessions:', err.message);
      return [];
    }
  }

  /**
   * Match a group of photos (with timestamps) to a session.
   *
   * @param {object} photoGroup - From groupBySession()
   * @param {Array} sessions - Sessions from WordPress
   * @returns {{ session: object|null, confidence: string, reason: string }}
   */
  matchGroupToSession(photoGroup, sessions) {
    if (!sessions || sessions.length === 0) {
      return { session: null, confidence: 'none', reason: 'No sessions found for this date' };
    }

    // Filter to sessions on the same date
    const sameDateSessions = sessions.filter(
      (s) => s.session_date === photoGroup.date
    );

    if (sameDateSessions.length === 0) {
      return { session: null, confidence: 'none', reason: `No sessions on ${photoGroup.date}` };
    }

    // Only one session on this date → high confidence auto-match
    if (sameDateSessions.length === 1) {
      return {
        session: sameDateSessions[0],
        confidence: 'high',
        reason: `Only session on ${photoGroup.date}`,
      };
    }

    // Multiple sessions on same date → match by time proximity
    if (photoGroup.startTime) {
      const photoTimeMinutes = photoGroup.startTime.getHours() * 60 + photoGroup.startTime.getMinutes();

      let closest = null;
      let closestDiff = Infinity;

      for (const session of sameDateSessions) {
        if (!session.session_time) continue;

        const [hours, minutes] = session.session_time.split(':').map(Number);
        const sessionTimeMinutes = hours * 60 + minutes;
        const diff = Math.abs(photoTimeMinutes - sessionTimeMinutes);

        if (diff < closestDiff) {
          closestDiff = diff;
          closest = session;
        }
      }

      if (closest && closestDiff <= 180) {
        // Within 3 hours of session time
        const confidence = closestDiff <= 60 ? 'high' : 'medium';
        return {
          session: closest,
          confidence,
          reason: `Closest session by time (${closestDiff}min gap, ${sameDateSessions.length} sessions on date)`,
        };
      }
    }

    // Can't determine by time — pick the session in a stage that expects photos
    const photoReadySessions = sameDateSessions.filter((s) =>
      ['booked', 'deposit_received', 'session_scheduled', 'session_complete'].includes(s.current_stage)
    );

    if (photoReadySessions.length === 1) {
      return {
        session: photoReadySessions[0],
        confidence: 'medium',
        reason: 'Only session in photo-ready stage',
      };
    }

    return {
      session: null,
      confidence: 'low',
      reason: `${sameDateSessions.length} sessions on ${photoGroup.date} — cannot auto-match`,
    };
  }

  /**
   * Full matching workflow: read photos, group, fetch sessions, match.
   *
   * @param {string} importDir - Directory with newly imported photos
   * @returns {Array<{group: object, match: object}>} - Matched results
   */
  async matchPhotosToSessions(importDir) {
    console.log(`[Matcher] Scanning ${importDir} for photos...`);

    // Step 1: Read timestamps from all photos
    const photos = await readAllTimestamps(importDir);
    if (photos.length === 0) {
      console.log('[Matcher] No photos found in import directory');
      return [];
    }

    console.log(`[Matcher] Found ${photos.length} photos, reading EXIF data...`);

    // Step 2: Group by session time windows
    const groups = groupBySession(photos, this.gapMinutes);
    console.log(`[Matcher] Grouped into ${groups.length} session(s)`);

    // Step 3: Get unique dates and fetch sessions for each
    const dates = [...new Set(groups.map((g) => g.date).filter(Boolean))];
    const sessionsByDate = {};

    for (const date of dates) {
      sessionsByDate[date] = await this.fetchSessionsByDate(date);
      console.log(`[Matcher] ${date}: ${sessionsByDate[date].length} session(s) booked`);
    }

    // If no sessions found on exact dates, try recent range
    if (dates.every((d) => sessionsByDate[d].length === 0)) {
      console.log('[Matcher] No exact date matches — searching recent sessions...');
      const recentSessions = await this.fetchRecentSessions();

      for (const session of recentSessions) {
        if (session.session_date) {
          if (!sessionsByDate[session.session_date]) {
            sessionsByDate[session.session_date] = [];
          }
          sessionsByDate[session.session_date].push(session);
        }
      }
    }

    // Step 4: Match each group to a session
    const results = [];

    for (const group of groups) {
      const availableSessions = group.date ? sessionsByDate[group.date] || [] : [];

      // Also include sessions from nearby dates (±1 day for late-night sessions)
      if (group.date) {
        const prevDate = shiftDate(group.date, -1);
        const nextDate = shiftDate(group.date, 1);
        const nearbySessions = [
          ...(sessionsByDate[prevDate] || []),
          ...(sessionsByDate[nextDate] || []),
        ];
        availableSessions.push(...nearbySessions);
      }

      const match = this.matchGroupToSession(group, availableSessions);

      results.push({
        group: {
          date: group.date,
          startTime: group.startTime ? formatTime(group.startTime) : null,
          endTime: group.endTime ? formatTime(group.endTime) : null,
          photoCount: group.photos.length,
          photos: group.photos,
        },
        match,
      });

      if (match.session) {
        console.log(
          `[Matcher] Group ${group.date} (${group.photos.length} photos) → ` +
            `Session ${match.session.tracking_code} (${match.session.client_name}) [${match.confidence}]`
        );
      } else {
        console.log(
          `[Matcher] Group ${group.date} (${group.photos.length} photos) → UNMATCHED: ${match.reason}`
        );
      }
    }

    return results;
  }
}

/**
 * Shift a YYYY-MM-DD date string by N days.
 */
function shiftDate(dateStr, days) {
  const d = new Date(dateStr + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().split('T')[0];
}

module.exports = { SessionMatcher };
