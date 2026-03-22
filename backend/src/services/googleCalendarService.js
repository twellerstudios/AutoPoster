/**
 * Google Calendar Service — Fetches booking/session data from Google Calendar.
 *
 * Bookings flow: SureCart → OttoKit → Google Calendar
 * This service reads those calendar events to match photo imports to clients.
 */
const axios = require('axios');

const API_BASE = 'https://www.googleapis.com/calendar/v3';

class GoogleCalendarService {
  constructor() {
    this.apiKey = process.env.GOOGLE_CALENDAR_API_KEY;
    this.calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';
  }

  /**
   * Fetch events from Google Calendar within a date range.
   * @param {Date} timeMin - Start of range
   * @param {Date} timeMax - End of range
   * @returns {Array} Calendar events
   */
  async getEvents(timeMin, timeMax) {
    if (!this.apiKey) {
      throw new Error('GOOGLE_CALENDAR_API_KEY is not configured');
    }

    const params = {
      key: this.apiKey,
      timeMin: timeMin.toISOString(),
      timeMax: timeMax.toISOString(),
      singleEvents: true,
      orderBy: 'startTime',
      maxResults: 50,
    };

    const res = await axios.get(
      `${API_BASE}/calendars/${encodeURIComponent(this.calendarId)}/events`,
      { params }
    );

    return res.data.items || [];
  }

  /**
   * Find the booking that best matches a given photo date.
   * Looks for events on the same day, returns the closest match.
   * @param {Date} photoDate - Date from photo EXIF
   * @returns {{ clientName: string, sessionDate: Date, eventTitle: string } | null}
   */
  async findBookingForDate(photoDate) {
    const dayStart = new Date(photoDate);
    dayStart.setHours(0, 0, 0, 0);

    const dayEnd = new Date(photoDate);
    dayEnd.setHours(23, 59, 59, 999);

    try {
      const events = await this.getEvents(dayStart, dayEnd);
      if (events.length === 0) return null;

      // Find the event closest to the photo time
      let bestMatch = null;
      let bestDiff = Infinity;

      for (const event of events) {
        const eventStart = new Date(event.start.dateTime || event.start.date);
        const diff = Math.abs(photoDate.getTime() - eventStart.getTime());

        if (diff < bestDiff) {
          bestDiff = diff;
          bestMatch = event;
        }
      }

      if (!bestMatch) return null;

      const clientName = this.extractClientName(bestMatch.summary || '');
      const sessionDate = new Date(bestMatch.start.dateTime || bestMatch.start.date);

      return {
        clientName,
        sessionDate,
        eventTitle: bestMatch.summary,
        eventDescription: bestMatch.description || '',
        eventId: bestMatch.id,
      };
    } catch (err) {
      console.error('[GoogleCalendar] Error fetching events:', err.message);
      return null;
    }
  }

  /**
   * Extract client name from calendar event title.
   * Common formats from SureCart/OttoKit:
   *   "John Smith - Portrait Session"
   *   "Smith Wedding"
   *   "Booking: Jane Doe"
   */
  extractClientName(title) {
    // Strip common prefixes
    let name = title
      .replace(/^(booking|session|shoot|appointment):\s*/i, '')
      .trim();

    // If there's a dash separator, take the first part (usually the client name)
    if (name.includes(' - ')) {
      name = name.split(' - ')[0].trim();
    }

    // If there's a colon separator, take after the colon
    if (name.includes(': ')) {
      name = name.split(': ').pop().trim();
    }

    return name;
  }

  /**
   * Get all bookings for a date range (for the dashboard).
   * @param {number} daysBack - How many days back to look
   * @param {number} daysForward - How many days forward to look
   * @returns {Array}
   */
  async getRecentBookings(daysBack = 7, daysForward = 7) {
    const now = new Date();
    const timeMin = new Date(now.getTime() - daysBack * 86400000);
    const timeMax = new Date(now.getTime() + daysForward * 86400000);

    try {
      const events = await this.getEvents(timeMin, timeMax);
      return events.map(event => ({
        id: event.id,
        title: event.summary,
        clientName: this.extractClientName(event.summary || ''),
        start: event.start.dateTime || event.start.date,
        end: event.end.dateTime || event.end.date,
        description: event.description || '',
      }));
    } catch (err) {
      console.error('[GoogleCalendar] Error fetching recent bookings:', err.message);
      return [];
    }
  }
}

module.exports = new GoogleCalendarService();
