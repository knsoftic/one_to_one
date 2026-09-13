/**
 * Lightweight, dependency-free emoji picker.
 */
import { html, raw } from '../lib/dom';

const RECENT_KEY = 'chat:recent-emoji';

const CATEGORIES = [
    {
        id: 'smileys',
        icon: '😀',
        label: 'Smileys',
        emoji: '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👻 👽 🤖',
    },
    {
        id: 'gestures',
        icon: '👍',
        label: 'People & gestures',
        emoji: '👋 🤚 🖐️ ✋ 🖖 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💅 💪 🦾 👀 👁️ 👅 👄 🧠 👶 🧒 👦 👧 🧑 👱 👨 🧔 👩 🧓 👴 👵 🙍 🙎 🙅 🙆 💁 🙋 🧏 🙇 🤦 🤷 👮 🕵️ 💂 👷 🤴 👸 👳 🧕 🤵 👰 🤰 🤱 👼 🎅 🤶 🦸 🦹 🧙 🧚 🧛 🧜 🧝 🧞 🧟 💆 💇 🚶 🧍 🧎 🏃 💃 🕺 👯 🧖 🧗',
    },
    {
        id: 'hearts',
        icon: '❤️',
        label: 'Hearts & symbols',
        emoji: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ☮️ ✝️ ☪️ 🕉️ ☸️ ✡️ 🔯 ☯️ ☦️ 🛐 ⛎ ♈ ♉ ♊ ♋ ♌ ♍ ♎ ♏ ♐ ♑ ♒ ♓ 🆔 ⚛️ ✅ ☑️ ✔️ ❌ ❎ ➕ ➖ ➗ ✖️ ♾️ 💯 💢 💥 💫 💦 💨 🕳️ 💬 👁️‍🗨️ 🗨️ 🗯️ 💭 💤 🔥 ✨ 🌟 ⭐ ⚡ 🎉 🎊 🎈 🎁 🏆 🥇 🥈 🥉 ⚠️ 🚫 ⛔ 📛 🔞 ❗ ❓ ❕ ❔ ‼️ ⁉️ 🔔 🔕 🎵 🎶',
    },
    {
        id: 'nature',
        icon: '🐶',
        label: 'Animals & nature',
        emoji: '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🐤 🦆 🦅 🦉 🦇 🐺 🐗 🐴 🦄 🐝 🐛 🦋 🐌 🐞 🐜 🦗 🕷️ 🦂 🐢 🐍 🦎 🐙 🦑 🦐 🦀 🐡 🐠 🐟 🐬 🐳 🐋 🦈 🐊 🐅 🐆 🦓 🦍 🐘 🦛 🦏 🐪 🦒 🦘 🐃 🐂 🐄 🐎 🐖 🐏 🐑 🐐 🦌 🐕 🐩 🐈 🐓 🦃 🦚 🦜 🦢 🕊️ 🐇 🦝 🦨 🦡 🦦 🦥 🐁 🐀 🐿️ 🦔 🌵 🎄 🌲 🌳 🌴 🌱 🌿 ☘️ 🍀 🎍 🎋 🍃 🍂 🍁 🍄 🌾 💐 🌷 🌹 🥀 🌺 🌸 🌼 🌻 🌞 🌝 🌛 🌜 🌚 🌕 🌙 🌎 🪐 ☀️ 🌤️ ⛅ 🌥️ ☁️ 🌦️ 🌧️ ⛈️ 🌩️ 🌨️ ❄️ ☃️ ⛄ 🌬️ 🌪️ 🌈 ☔ 🌊',
    },
    {
        id: 'food',
        icon: '🍔',
        label: 'Food & drink',
        emoji: '🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🍆 🥑 🥦 🥬 🥒 🌶️ 🌽 🥕 🧄 🧅 🥔 🍠 🥐 🥯 🍞 🥖 🥨 🧀 🥚 🍳 🧈 🥞 🧇 🥓 🥩 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🥙 🧆 🌮 🌯 🥗 🥘 🥫 🍝 🍜 🍲 🍛 🍣 🍱 🥟 🍤 🍙 🍚 🍘 🍥 🥠 🍢 🍡 🍧 🍨 🍦 🥧 🧁 🍰 🎂 🍮 🍭 🍬 🍫 🍿 🍩 🍪 🌰 🥜 🍯 🥛 🍼 ☕ 🍵 🧃 🥤 🍶 🍺 🍻 🥂 🍷 🥃 🍸 🍹 🧉 🍾 🧊 🥄 🍴 🍽️',
    },
    {
        id: 'activities',
        icon: '⚽',
        label: 'Activities & travel',
        emoji: '⚽ 🏀 🏈 ⚾ 🥎 🎾 🏐 🏉 🥏 🎱 🏓 🏸 🏒 🏑 🥍 🏏 🥅 ⛳ 🏹 🎣 🥊 🥋 🎽 🛹 ⛸️ 🥌 🎿 ⛷️ 🏂 🏋️ 🤸 ⛹️ 🤺 🤾 🏌️ 🏇 🧘 🏄 🏊 🤽 🚣 🧗 🚵 🚴 🎖️ 🏅 🎗️ 🎫 🎟️ 🎪 🎭 🎨 🎬 🎤 🎧 🎼 🎹 🥁 🎷 🎺 🎸 🎻 🎲 ♟️ 🎯 🎳 🎮 🎰 🧩 🚗 🚕 🚙 🚌 🏎️ 🚓 🚑 🚒 🚚 🚜 🏍️ 🛵 🚲 🛴 🚨 🚆 🚇 ✈️ 🛫 🛬 🚀 🛸 🚁 ⛵ 🚤 🛳️ 🚢 ⚓ ⛽ 🚧 🗺️ 🗿 🗽 🗼 🏰 🏯 🏟️ 🎡 🎢 🎠 ⛲ 🏖️ 🏝️ 🏜️ 🌋 ⛰️ 🏔️ 🏕️ 🏠 🏡 🏢 🏥 🏦 🏨 🏪 🏫 💒 🕌 🕍 ⛪ 🌃 🏙️ 🌄 🌅 🌆 🌇 🌉',
    },
    {
        id: 'objects',
        icon: '💡',
        label: 'Objects',
        emoji: '⌚ 📱 💻 ⌨️ 🖥️ 🖨️ 🖱️ 💽 💾 💿 📀 📷 📸 📹 🎥 📞 ☎️ 📺 📻 🎙️ ⏰ ⏳ ⌛ 📡 🔋 🔌 💡 🔦 🕯️ 🧯 💸 💵 💰 💳 💎 ⚖️ 🧰 🔧 🔨 🛠️ ⛏️ 🔩 ⚙️ 🧱 ⛓️ 🧲 🔫 💣 🧨 🔪 🗡️ ⚔️ 🛡️ 🚬 ⚰️ 🏺 🔮 📿 🧿 💈 ⚗️ 🔭 🔬 💊 💉 🩸 🧬 🦠 🧫 🧪 🌡️ 🧹 🧺 🧻 🚽 🚿 🛁 🧼 🪒 🧽 🧴 🛎️ 🔑 🗝️ 🚪 🪑 🛋️ 🛏️ 🧸 🖼️ 🛍️ 🛒 🎁 🎈 🎏 🎀 🧧 ✉️ 📩 📨 📧 💌 📥 📤 📦 🏷️ 📪 📫 📬 📭 📮 📯 📜 📃 📄 📑 🧾 📊 📈 📉 🗒️ 🗓️ 📆 📅 🗑️ 📇 🗃️ 🗳️ 🗄️ 📋 📁 📂 🗂️ 🗞️ 📰 📓 📔 📒 📕 📗 📘 📙 📚 📖 🔖 🧷 🔗 📎 🖇️ 📐 📏 🧮 📌 📍 ✂️ 🖊️ 🖋️ ✒️ 🖌️ 🖍️ 📝 ✏️ 🔍 🔎 🔏 🔐 🔒 🔓',
    },
    {
        id: 'flags',
        icon: '🏳️',
        label: 'Flags',
        emoji: '🏁 🚩 🎌 🏴 🏳️ 🏳️‍🌈 🇵🇰 🇮🇳 🇧🇩 🇦🇪 🇸🇦 🇶🇦 🇰🇼 🇴🇲 🇹🇷 🇮🇷 🇦🇫 🇪🇬 🇲🇦 🇳🇬 🇿🇦 🇰🇪 🇺🇸 🇨🇦 🇲🇽 🇧🇷 🇦🇷 🇬🇧 🇮🇪 🇫🇷 🇩🇪 🇮🇹 🇪🇸 🇵🇹 🇳🇱 🇧🇪 🇨🇭 🇸🇪 🇳🇴 🇩🇰 🇫🇮 🇵🇱 🇺🇦 🇷🇺 🇨🇳 🇯🇵 🇰🇷 🇮🇩 🇲🇾 🇸🇬 🇹🇭 🇻🇳 🇵🇭 🇦🇺 🇳🇿 🇪🇺 🇺🇳',
    },
];

const parse = (list) => list.split(' ').filter(Boolean);

function loadRecent() {
    try {
        const value = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
        return Array.isArray(value) ? value.slice(0, 24) : [];
    } catch {
        return [];
    }
}

function saveRecent(emoji) {
    const recent = [emoji, ...loadRecent().filter((e) => e !== emoji)].slice(0, 24);
    try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(recent));
    } catch {
        /* ignore */
    }
}

export class EmojiPicker {
    /**
     * @param {HTMLElement} anchor   element the panel is positioned in
     * @param {(emoji: string) => void} onSelect
     */
    constructor(anchor, onSelect) {
        this.anchor = anchor;
        this.onSelect = onSelect;
        this.panel = null;
        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
    }

    get isOpen() {
        return Boolean(this.panel);
    }

    toggle() {
        this.isOpen ? this.close() : this.open();
    }

    open() {
        if (this.panel) return;

        const recent = loadRecent();
        const tabs = [
            ...(recent.length ? [{ id: 'recent', icon: '🕘', label: 'Recently used' }] : []),
            ...CATEGORIES,
        ];

        this.panel = document.createElement('div');
        this.panel.className = 'emoji-panel';
        this.panel.setAttribute('role', 'dialog');
        this.panel.setAttribute('aria-label', 'Emoji picker');
        this.panel.innerHTML = html`
            <div class="emoji-tabs" role="tablist">
                ${raw(tabs.map((t, i) => html`<button type="button" class="emoji-tab${i === 0 ? ' is-active' : ''}" data-emoji-tab="${t.id}" title="${t.label}" aria-label="${t.label}">${t.icon}</button>`).join(''))}
            </div>
            <div class="emoji-grid" data-emoji-grid>
                ${raw(
                    [
                        ...(recent.length ? [{ id: 'recent', label: 'Recently used', emoji: recent.join(' ') }] : []),
                        ...CATEGORIES,
                    ]
                        .map(
                            (c) =>
                                html`<div class="emoji-section-label" data-emoji-section="${c.id}">${c.label}</div>` +
                                parse(c.emoji)
                                    .map((e) => html`<button type="button" class="emoji-btn" data-emoji="${e}" aria-label="${e}">${e}</button>`)
                                    .join(''),
                        )
                        .join(''),
                )}
            </div>
        `;

        this.panel.addEventListener('click', (event) => {
            const emoji = event.target.closest('[data-emoji]');
            if (emoji) {
                saveRecent(emoji.dataset.emoji);
                this.onSelect(emoji.dataset.emoji);
                return;
            }

            const tab = event.target.closest('[data-emoji-tab]');
            if (tab) {
                this.panel.querySelectorAll('.emoji-tab').forEach((t) => t.classList.toggle('is-active', t === tab));
                const section = this.panel.querySelector(`[data-emoji-section="${tab.dataset.emojiTab}"]`);
                const grid = this.panel.querySelector('[data-emoji-grid]');
                grid.scrollTo({ top: section.offsetTop - grid.offsetTop, behavior: 'smooth' });
            }
        });

        this.anchor.appendChild(this.panel);
        setTimeout(() => document.addEventListener('click', this.onDocumentClick), 0);
        document.addEventListener('keydown', this.onKeydown);
    }

    close() {
        if (!this.panel) return;
        this.panel.remove();
        this.panel = null;
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
    }

    onDocumentClick(event) {
        if (this.panel?.contains(event.target) || event.target.closest('[data-emoji-toggle]')) return;
        this.close();
    }

    onKeydown(event) {
        if (event.key === 'Escape') this.close();
    }
}
