// Install support. The browser fires `beforeinstallprompt` once, often before
// Alpine has started, so it is captured here at module load and the install
// button reads it from this module-level variable.

let deferredPrompt = null;

const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    window.dispatchEvent(new CustomEvent('pwa:installable'));
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa:installed'));
});

// The worker caches nothing (see public/sw.js); it only makes the app installable.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const url = document.querySelector('meta[name="sw-url"]')?.content;
        if (url) navigator.serviceWorker.register(url).catch(() => {});
    });
}

export default function installApp() {
    return {
        canPrompt: false,
        installed: isStandalone(),
        // iOS has no install prompt: people must use Share → Add to Home Screen.
        ios:
            /iphone|ipad|ipod/i.test(navigator.userAgent) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
        help: false,

        init() {
            this.canPrompt = deferredPrompt !== null;
            window.addEventListener('pwa:installable', () => (this.canPrompt = true));
            window.addEventListener('pwa:installed', () => {
                this.installed = true;
                this.canPrompt = false;
            });
        },

        get visible() {
            return !this.installed && (this.canPrompt || this.ios);
        },

        async install() {
            if (this.canPrompt && deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                deferredPrompt = null;
                this.canPrompt = false;
                if (outcome === 'accepted') this.installed = true;
            } else if (this.ios) {
                this.help = true;
            }
        },
    };
}
