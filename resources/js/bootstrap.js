import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * NOTE: Broadcasting/Real-time features are disabled
 * Echo and Reverb have been removed to eliminate frontend errors
 * Filament will use polling for notifications instead
 */
