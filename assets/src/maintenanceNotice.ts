// Pre-maintenance warning (operator addendum 2026-07-24): when a SCHEDULED
// maintenance window is less than an hour away, users get a recurring toast
// so they can wrap up. The status endpoint (which proxies PFM's maintenance
// contract) is polled every 5 minutes and every poll inside the final hour
// re-toasts — "every so often", per the dictate. Self-contained DOM card
// (no toast bus in this app); dark, matching the agent shell.
//
// EVERYTHING lives INSIDE the exported function on purpose: if this bundle
// ever executes in classic-script scope, a top-level function declaration
// becomes a window global — and the minifier is free to pick a two-letter
// name like `wp` for it, clobbering window.wp (it happened in the workflow
// studio). Inner declarations can never leak, whatever the minifier calls
// them.
import { __, _n, sprintf } from '@wordpress/i18n';

/** Start the recurring pre-maintenance check. Call once at boot. */
export function startMaintenanceNotice(): void {
  const POLL_MS = 5 * 60 * 1000;
  const WARN_WINDOW_SECONDS = 60 * 60;
  const TOAST_MS = 15000;

  interface MaintenanceStatus {
    in_maintenance: boolean;
    seconds_until_next: number | null;
  }

  interface PfaConfig {
    restUrl: string;
    nonce: string;
  }

  const readConfig = (): PfaConfig | null => {
    const cfg = (window as unknown as { ProjectFlashAgent?: PfaConfig }).ProjectFlashAgent;
    return cfg && typeof cfg.restUrl === 'string' ? cfg : null;
  };

  const showNotice = (message: string, context: string): void => {
    const card = document.createElement('div');
    card.setAttribute('role', 'status');
    card.style.cssText = [
      'position:fixed', 'bottom:24px', 'left:50%', 'transform:translateX(-50%)',
      'z-index:99999', 'max-width:480px', 'background:#151b23', 'color:#e6e8eb',
      'border:1px solid #2c3947', 'border-left:3px solid #4c8dff', 'border-radius:8px',
      'box-shadow:0 6px 24px rgba(0,0,0,.45)', 'padding:12px 16px',
      'font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
    ].join(';');
    const strong = document.createElement('div');
    strong.style.fontWeight = '600';
    strong.textContent = message;
    const small = document.createElement('div');
    small.style.cssText = 'font-size:12px;color:#94a3b8;margin-top:2px';
    small.textContent = context;
    card.append(strong, small);
    document.body.appendChild(card);
    window.setTimeout(() => card.remove(), TOAST_MS);
  };

  const check = async (): Promise<void> => {
    try {
      const cfg = readConfig();
      if (!cfg) {
        return;
      }
      const res = await fetch(cfg.restUrl + 'maintenance/status', {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
      });
      if (!res.ok) {
        return;
      }
      const status = (await res.json()) as MaintenanceStatus;
      const seconds = status.seconds_until_next;
      if (seconds === null || seconds === undefined || seconds <= 0 || seconds > WARN_WINDOW_SECONDS) {
        return;
      }
      const minutes = Math.max(1, Math.round(seconds / 60));
      showNotice(
        sprintf(
          _n(
            'Scheduled maintenance begins in about %d minute.',
            'Scheduled maintenance begins in about %d minutes.',
            minutes,
            'wp-pfagent'
          ),
          minutes
        ),
        __('Save your work — the system will be unavailable during maintenance.', 'wp-pfagent')
      );
    } catch {
      // The warning poll must never break or noise up the app.
    }
  };

  void check();
  window.setInterval(() => {
    void check();
  }, POLL_MS);
}
