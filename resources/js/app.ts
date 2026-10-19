import { createApp } from 'vue';
import { createPinia } from 'pinia';
import GlobalLoader from './components/layout/GlobalLoader.vue';

import router from '@/router';
import App from '@/App.vue';

const app = createApp(App);

app.use(createPinia());
app.use(router);

router
    .isReady()
    .catch(() => {}) // still mount on failure so the app can show an error state
    .finally(() => app.mount('#app'));