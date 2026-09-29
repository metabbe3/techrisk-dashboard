import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// A bundle built for one origin must not open sockets at another: production
// serves the committed public/build, which was Vite-built against the dev
// .env (wsHost "localhost", port 8081, key "local-key") — every prod page
// then tried wss://localhost:8081 and failed (2026-09-29). Connect only when
// the baked wsHost matches where the page is actually served. To enable
// realtime in production, build with VITE_REVERB_HOST set to the public host.
const wsHost = import.meta.env.VITE_REVERB_HOST;
const isLocal = (h) => ['localhost', '127.0.0.1'].includes(h);
if (wsHost === window.location.hostname || (isLocal(wsHost) && isLocal(window.location.hostname))) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
