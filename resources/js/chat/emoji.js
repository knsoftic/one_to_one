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
     * @param {{modes?: {id: string, label: string, render: (container: HTMLElement, picker: EmojiPicker) => void}[]}} options
     *        extra panels next to "Emoji" (stickers, GIFs)
     */
    constructor(anchor, onSelect, { modes = [] } = {}) {
        this.anchor = anchor;
        this.onSelect = onSelect;
        this.modes = modes;
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
        const modeBar = this.modes.length
            ? html`<div class="picker-modes" role="tablist" aria-label="Emoji, stickers and GIFs">
                <button type="button" class="picker-mode is-active" data-picker-mode="emoji" role="tab" aria-selected="true">Emoji</button>
                ${raw(this.modes.map((m) => html`<button type="button" class="picker-mode" data-picker-mode="${m.id}" role="tab" aria-selected="false">${m.label}</button>`).join(''))}
            </div>`
            : '';

        this.panel.innerHTML = modeBar + html`
            <div class="picker-body" data-picker-body="emoji">
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
            </div>
            <div class="picker-body" data-picker-body="extra" hidden></div>
        `;

        this.panel.addEventListener('click', (event) => {
            const mode = event.target.closest('[data-picker-mode]');
            if (mode) {
                this.showMode(mode.dataset.pickerMode);
                return;
            }

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

    showMode(id) {
        this.panel.querySelectorAll('[data-picker-mode]').forEach((button) => {
            const active = button.dataset.pickerMode === id;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
        });

        const emojiBody = this.panel.querySelector('[data-picker-body="emoji"]');
        const extra = this.panel.querySelector('[data-picker-body="extra"]');
        const mode = this.modes.find((m) => m.id === id);
        emojiBody.hidden = Boolean(mode);
        extra.hidden = !mode;
        if (mode) {
            extra.replaceChildren();
            mode.render(extra, this);
        }
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
