import axios from 'axios';

window.axios = axios;

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common.Accept = 'application/json';

const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

/**
 * Session ended (logged out elsewhere, expired, or the account was
 * suspended by an administrator): reload so the server redirects to sign-in.
 */
let reloading = false;

const isAuthenticatedPage = () => {
    try {
        return Boolean(JSON.parse(document.getElementById('app-config')?.textContent || '{}').user);
    } catch {
        return false;
    }
};

axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        const message = String(error?.response?.data?.message ?? '');
        const sessionEnded = status === 401 || status === 419 || (status === 403 && /suspended|inactive/i.test(message));

        if (sessionEnded && !reloading && isAuthenticatedPage()) {
            reloading = true;
            setTimeout(() => window.location.reload(), 300);
        }

        return Promise.reject(error);
    },
);

export default axios;
