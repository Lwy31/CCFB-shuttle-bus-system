import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// Custom metric trackers
export const errorRate = new Rate('custom_error_rate');
export const bookingCount = new Counter('successful_bookings');
export const pageResponseTrend = new Trend('page_response_time');

// Read target URL from environment variable or fallback to localhost
const BASE_URL = __ENV.TARGET_URL ? __ENV.TARGET_URL.replace(/\/$/, '') : 'http://localhost';

// Credentials for simulation (default admin account or test accounts)
const SIM_USER_EMAIL = __ENV.SIM_EMAIL || 'admin@example.com';
const SIM_USER_PASSWORD = __ENV.SIM_PASSWORD || 'admin123';

export const options = {
  stages: [
    { duration: '30s', target: 20 },   // 1. Warm-up: 20 Virtual Users (VUs)
    { duration: '30s', target: 200 },  // 2. SURGE SPIKE: Ramp aggressively to 200 concurrent users
    { duration: '2m',  target: 200 },  // 3. Peak: Hold 200 VUs to observe ASG scale-out & DB pool
    { duration: '30s', target: 50 },   // 4. Recovery: Drop back to moderate load
    { duration: '30s', target: 0 },    // 5. Cooldown: Wind down to 0
  ],
  thresholds: {
    // 95% of all HTTP requests must complete within 1500ms
    http_req_duration: ['p(95)<1500'],
    // HTTP failure rate should stay under 5% during the surge
    http_req_failed: ['rate<0.05'],
    custom_error_rate: ['rate<0.05'],
  },
};

// Generates a random future date within the next 7 days in YYYY-MM-DD format
function getRandomFutureDate() {
  const now = new Date();
  const dayOffset = Math.floor(Math.random() * 6) + 1; // 1 to 7 days ahead
  now.setDate(now.getDate() + dayOffset);
  return now.toISOString().split('T')[0];
}

// Extracts CSRF token from HTML response body
function extractCsrfToken(html) {
  if (!html) return null;
  const match = html.match(/name="csrf_token"\s+value="([^"]+)"/);
  return match ? match[1] : null;
}

export default function () {
  const rand = Math.random();

  // Traffic weighting simulates realistic user behavior during a ticket release:
  // 65% - Browsing routes, schedule, and home page
  // 25% - Rapidly checking route availability (AJAX polling)
  // 10% - Authenticated booking submission

  if (rand < 0.65) {
    // ------------------------------------------------------------------------
    // Scenario A: Page Browsing (Homepage & Schedule)
    // ------------------------------------------------------------------------
    group('Browse Pages', () => {
      const homeRes = http.get(`${BASE_URL}/index.php`);
      const homeOk = check(homeRes, {
        'home status 200': (r) => r.status === 200,
        'home has title': (r) => r.body && r.body.includes('Shuttle Bus'),
      });
      errorRate.add(!homeOk);
      pageResponseTrend.add(homeRes.timings.duration);

      sleep(Math.random() * 1.5 + 0.5);

      const schedRes = http.get(`${BASE_URL}/schedule.php`);
      const schedOk = check(schedRes, {
        'schedule status 200': (r) => r.status === 200,
      });
      errorRate.add(!schedOk);
      pageResponseTrend.add(schedRes.timings.duration);
    });

  } else if (rand < 0.90) {
    // ------------------------------------------------------------------------
    // Scenario B: Route Availability AJAX Polling
    // ------------------------------------------------------------------------
    group('Check Route Availability', () => {
      const travelDate = getRandomFutureDate();
      const availRes = http.get(`${BASE_URL}/route_availability.php?travel_date=${travelDate}`);
      const availOk = check(availRes, {
        'availability status 200': (r) => r.status === 200,
        'availability is JSON': (r) => {
          try {
            const body = JSON.parse(r.body);
            return body && Array.isArray(body.full_trip_ids);
          } catch (e) {
            return false;
          }
        },
      });
      errorRate.add(!availOk);
      pageResponseTrend.add(availRes.timings.duration);
      sleep(1);
    });

  } else {
    // ------------------------------------------------------------------------
    // Scenario C: Authenticated Ticket Booking Flow
    // ------------------------------------------------------------------------
    group('Booking Flow', () => {
      // 1. Visit login page to acquire session cookie and CSRF token
      const loginPageRes = http.get(`${BASE_URL}/login.php`);
      const loginPageOk = check(loginPageRes, {
        'login page 200': (r) => r.status === 200,
      });
      errorRate.add(!loginPageOk);

      const loginCsrf = extractCsrfToken(loginPageRes.body);

      if (loginCsrf) {
        // 2. Submit credentials
        const loginPayload = {
          csrf_token: loginCsrf,
          email: SIM_USER_EMAIL,
          password: SIM_USER_PASSWORD,
        };

        const loginRes = http.post(`${BASE_URL}/login.php`, loginPayload, {
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          redirects: 1,
        });

        const loginOk = check(loginRes, {
          'login handled': (r) => [200, 302].includes(r.status),
        });
        errorRate.add(!loginOk);

        // 3. Fetch booking page to get booking form CSRF token
        const bookPageRes = http.get(`${BASE_URL}/create.php`);
        const bookCsrf = extractCsrfToken(bookPageRes.body) || loginCsrf;

        // 4. Submit booking for a future date
        const travelDate = getRandomFutureDate();
        const bookingPayload = {
          csrf_token: bookCsrf,
          trip_id: '1',
          travel_date: travelDate,
          seat_quantity: '1',
        };

        const bookRes = http.post(`${BASE_URL}/create.php`, bookingPayload, {
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          redirects: 1,
        });

        const bookOk = check(bookRes, {
          'booking succeeded or validated': (r) => [200, 302].includes(r.status),
        });

        if (bookOk) {
          bookingCount.add(1);
        }
        errorRate.add(!bookOk);
      }
      sleep(2);
    });
  }

  sleep(1);
}
