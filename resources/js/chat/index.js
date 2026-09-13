import { readJsonScript } from '../lib/dom';
import { ChatApp } from './ChatApp';

const root = document.querySelector('[data-chat-app]');
const config = readJsonScript('chat-config', null);

if (root && config) {
    const chat = new ChatApp(config);
    window.Chat = chat;
    chat.init();
}
