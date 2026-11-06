import './bootstrap';

import Alpine from 'alpinejs';
import notificationBell from './notification-alerts';
import installApp from './pwa';

window.Alpine = Alpine;
Alpine.data('notificationBell', notificationBell);
Alpine.data('installApp', installApp);
Alpine.start();
