import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Axios reads XSRF-TOKEN cookie automatically on each request (xsrfCookieName default),
// so the token stays fresh across the session without a full page reload.
