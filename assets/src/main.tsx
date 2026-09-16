import { createRoot } from 'react-dom/client';

import { App } from './App';
import { startMaintenanceNotice } from './maintenanceNotice';
import './styles.css';

const root = document.getElementById('wp-pfagent-root');

if (root) {
  createRoot(root).render(<App />);
  // Module scope (not an effect): exactly one recurring pre-maintenance
  // check per page load.
  startMaintenanceNotice();
}
