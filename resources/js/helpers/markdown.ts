import { marked } from 'marked';
import hljs from 'highlight.js';
import DOMPurify from 'dompurify';

marked.setOptions({
    gfm: true,
    breaks: true,
});

const renderer = new marked.Renderer();

renderer.code = function ({ text, lang }: { text: string; lang?: string }): string {
    const language = lang && hljs.getLanguage(lang) ? lang : 'plaintext';
    const highlighted = hljs.highlight(text, { language }).value;
    const escapedCode = text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return `<div class="code-block"><div class="code-block-header"><span class="code-lang">${language}</span><button class="code-copy-btn" data-code="${escapedCode}" onclick="navigator.clipboard.writeText(decodeURIComponent(this.dataset.code)).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)})">Copy</button></div><pre><code class="hljs language-${language}">${highlighted}</code></pre></div>`;
};

renderer.codespan = function ({ text }: { text: string }): string {
    return `<code class="inline-code">${text}</code>`;
};

renderer.link = function (token: { href: string; title?: string | null; tokens?: Array<{ text?: string; raw?: string }>; text?: string }): string {
    const href = token.href;
    const titleAttr = token.title ? ` title="${token.title}"` : '';
    const text = token.tokens?.[0]?.text ?? token.text ?? href;
    return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="message-link"${titleAttr}>${text}</a>`;
};

renderer.image = function (token: { href: string; title?: string | null; text?: string }): string {
    const titleAttr = token.title ? ` title="${token.title}"` : '';
    const alt = token.text || 'image';
    return `<div class="message-image"><img src="${token.href}" alt="${alt}"${titleAttr} loading="lazy" /></div>`;
};

export function renderMarkdown(content: string): string {
    let processed = content;

    processed = processed.replace(/:([a-zA-Z0-9_+-]+):/g, (_match, code) => {
        return shortcodeToEmoji(code) || _match;
    });

    processed = processed.replace(
        /^(https?:\/\/[^\s]+(?:\.(?:png|jpe?g|gif|webp|svg|bmp|ico)(?:\?[^\s]*)?))$/gim,
        '![]($1)',
    );

    const rawHtml = marked.parse(processed) as string;
    return DOMPurify.sanitize(rawHtml, {
        ADD_TAGS: ['img'],
        ADD_ATTR: ['target', 'rel', 'loading', 'data-code'],
    });
}

const SHORTCODE_MAP: Record<string, string> = {
    smile: '😄', grinning: '😀', laughing: '😆', blush: '😊', wink: '😉',
    heart_eyes: '😍', kissing_heart: '😘', kissing: '😗', kissing_closed_eyes: '😚',
    smirk: '😏', neutral: '😐', expressionless: '😑',
    unamused: '😒', sweat: '😓', pensive: '😔', relieved: '😌',
    stuck_out_tongue: '😛', stuck_out_tongue_winking_eye: '😜', shocked: '😲',
    cry: '😢', sob: '😭', angry: '😡', rage: '😡', skull: '💀',
    heart: '❤️', thumbsup: '👍', thumbsdown: '👎', clap: '👏',
    fire: '🔥', star: '⭐', check: '✅', x: '❌', warning: '⚠️',
    rocket: '🚀', bulb: '💡', eyes: '👀', wave: '👋', pray: '🙏',
    party: '🎉', tada: '🎉', ok_hand: '👌', point_right: '👉',
    point_left: '👈', point_up: '☝️', point_down: '👇', muscle: '💪',
    sunglasses: '😎', nerd: '🤓', thinking: '🤔', zip: '🤐',
    cold_sweat: '😰', scream: '😱', sleeping: '😴', drool: '🤤',
    nerd_face: '🤓', money_mouth: '🤑', hugging: '🤗', shushing: '🤫',
    raised_hands: '🙌', foldhands: '🙏', sparkles: '✨', boom: '💥',
    plus_one: '➕', minus_one: '➖', red_heart: '❤️', orange_heart: '🧡',
    yellow_heart: '💛', green_heart: '💚', blue_heart: '💙', purple_heart: '💜',
    black_heart: '🖤', broken_heart: '💔', question: '❓', exclamation: '❗',
    '100': '💯', done: '✅', trophy: '🏆', medal: '🥇', gem: '💎',
    crown: '👑', rainbow: '🌈', sun: '☀️', moon: '🌙', cloud: '☁️',
    umbrella: '☂️', snowflake: '❄️', tree: '🌲', flower: '🌸',
    dog: '🐶', cat: '🐱', mouse: '🐭', bear: '🐻', panda: '🐼',
    coffee: '☕', beer: '🍺', wine: '🍷', food: '🍕', cake: '🎂',
    gift: '🎁', bell: '🔔', link: '🔗', hammer: '🔧', key: '🔑',
    lock: '🔒', unlock: '🔓', camera: '📷', phone: '📱', email: '📧',
};

function shortcodeToEmoji(code: string): string | null {
    const normalized = code.toLowerCase().replace(/-/g, '_');
    return SHORTCODE_MAP[normalized] ?? null;
}

export function stripMarkdown(content: string): string {
    let text = content;
    text = text.replace(/```[\s\S]*?```/g, '[code]');
    text = text.replace(/`[^`]+`/g, '[code]');
    text = text.replace(/\*\*(.+?)\*\*/g, '$1');
    text = text.replace(/\*(.+?)\*/g, '$1');
    text = text.replace(/~~(.+?)~~/g, '$1');
    text = text.replace(/^#{1,6}\s+/gm, '');
    text = text.replace(/^>\s+/gm, '');
    text = text.replace(/^[-*+]\s+/gm, '');
    text = text.replace(/^\d+\.\s+/gm, '');
    text = text.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
    return text.trim();
}