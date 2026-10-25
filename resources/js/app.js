import './bootstrap';

import Alpine from 'alpinejs';
import notificationBell from './notification-alerts';

window.Alpine = Alpine;
Alpine.data('notificationBell', notificationBell);
Alpine.start();
