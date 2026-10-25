// Header bell: polls for new notifications, updates the badge, and — when the
// user has granted browser notification permission — plays a chime and shows a
// desktop notification. No sound file needed; the chime is synthesised.

const CURSOR_KEY = 'hawala.notif.cursor';
const SEEN_KEY = 'hawala.notif.seen';

const store = {
    get(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* storage blocked */ }
    },
};

let audioCtx = null;

function unlockAudio() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    if (!audioCtx) audioCtx = new Ctx();
    if (audioCtx.state === 'suspended') audioCtx.resume();
}

function chime() {
    if (!audioCtx || audioCtx.state !== 'running') return;
    const t0 = audioCtx.currentTime;
    [880, 1318.5].forEach((freq, i) => {
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        const start = t0 + i * 0.16;
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.25, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.5);
        osc.connect(gain).connect(audioCtx.destination);
        osc.start(start);
        osc.stop(start + 0.55);
    });
}

export default function notificationBell({ pollUrl, indexUrl, unread, interval = 30000, title = '' }) {
    return {
        unread,
        permission: 'Notification' in window ? Notification.permission : 'unsupported',

        init() {
            // Browsers only allow audio after a user gesture; unlock on the first one.
            ['pointerdown', 'keydown'].forEach((evt) =>
                window.addEventListener(evt, unlockAudio, { once: true, passive: true }));

            this.poll();
            setInterval(() => { if (!document.hidden) this.poll(); }, interval);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
        },

        async enable() {
            if (!('Notification' in window)) return;
            unlockAudio();
            this.permission = await Notification.requestPermission();
            if (this.permission === 'granted') chime();
        },

        async poll() {
            const cursor = store.get(CURSOR_KEY);
            const url = cursor ? `${pollUrl}?after=${encodeURIComponent(cursor)}` : pollUrl;

            let data;
            try {
                const res = await window.axios.get(url);
                data = res.data;
            } catch (e) {
                return; // network blip or session expired — try again next tick
            }

            this.unread = data.unread;
            store.set(CURSOR_KEY, data.now);

            let seen = [];
            try { seen = JSON.parse(store.get(SEEN_KEY) || '[]'); } catch (e) { seen = []; }

            const fresh = (data.items || []).filter((n) => !seen.includes(n.id));
            if (!fresh.length) return;

            store.set(SEEN_KEY, JSON.stringify(seen.concat(fresh.map((n) => n.id)).slice(-50)));

            // Sound + desktop alert only when the user has granted permission.
            if (this.permission !== 'granted') return;

            chime();
            fresh.slice(0, 3).forEach((n) => {
                try {
                    const note = new Notification(title, { body: n.message, tag: n.id, icon: '/images/apple-touch-icon.png' });
                    note.onclick = () => { window.focus(); window.location.href = indexUrl; note.close(); };
                } catch (e) { /* some mobile browsers only allow service-worker notifications */ }
            });
        },
    };
}
