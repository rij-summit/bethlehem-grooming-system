const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../scripts/components/home-ai-chatbot.js'), 'utf8');
const storageKey = 'bethlehem.chatbot.conversation.v1';

class Element {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase();
    this.children = [];
    this.listeners = {};
    this.attributes = {};
    this.dataset = {};
    this.className = '';
    this.value = '';
    this.textContent = '';
    this.hidden = false;
    this.disabled = false;
  }
  addEventListener(event, handler) { this.listeners[event] = handler; }
  setAttribute(name, value) { this.attributes[name] = value; }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  replaceChildren() { this.children = []; }
  remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
  querySelector(selector) { return this.children.find(child => child.className === selector.slice(1)) || null; }
  focus() { this.focused = true; }
  requestSubmit() { this.submission = this.listeners.submit({preventDefault() {}}); }
}

function createChat({stored = [], signedIn = false} = {}) {
  const ids = ['button','panel','close','reset','messages','loading','error','form','input','send'];
  const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
  elements.panel.hidden = true;
  const storage = new Map([[storageKey, JSON.stringify(stored)]]);
  const documentEvents = {};
  const requests = [];
  let resolveReply;
  const document = {
    currentScript: {src: 'http://localhost/scripts/components/home-ai-chatbot.js'},
    baseURI: 'http://localhost/',
    head: new Element(),
    body: {matches: () => false},
    querySelectorAll: () => [],
    createElement: tag => new Element(tag),
    createTextNode: text => ({textContent: text}),
    getElementById: id => elements[id.replace('ai-chat-', '')],
    addEventListener: (event, handler) => { documentEvents[event] = handler; },
  };
  const windowEvents = {};
  const window = {
    location: {pathname: '/index.html'},
    addEventListener: (event, handler) => { windowEvents[event] = handler; },
    API: {
      getCustomerToken: () => signedIn ? 'test-session' : null,
      sendChatbotMessage: (message, history) => {
        requests.push({message, history});
        return new Promise(resolve => { resolveReply = resolve; });
      },
    },
  };
  const sessionStorage = {
    getItem: key => storage.get(key),
    setItem: (key, value) => storage.set(key, value),
    removeItem: key => storage.delete(key),
  };
  vm.runInNewContext(source, {window, document, sessionStorage, URL, console});
  windowEvents.DOMContentLoaded();
  return {elements, storage, requests, documentEvents, finish: async reply => {
    resolveReply({reply});
    await elements.form.submission;
  }};
}

(async () => {
  const chat = createChat();
  const {elements} = chat;
  let topics = elements.messages.querySelector('.ai-chatbot-topics');
  assert.equal(topics.children.length, 6);
  assert.equal(topics.attributes['aria-label'], 'Suggested topics');
  assert.ok(topics.children.every(button => button.tagName === 'BUTTON' && button.type === 'button'));

  elements.button.listeners.click();
  assert.equal(elements.panel.hidden, false);
  assert.equal(elements.button.attributes['aria-expanded'], 'true');
  assert.equal(elements.input.focused, true);
  topics.children[0].listeners.click();
  assert.equal(chat.requests.length, 1);
  assert.equal(chat.requests[0].message, 'How long will grooming take?');
  assert.equal(chat.requests[0].history.length, 0);
  assert.equal(elements.messages.querySelector('.ai-chatbot-topics'), null);
  assert.equal(elements.send.disabled, true);
  assert.equal(elements.reset.disabled, true);
  elements.form.requestSubmit();
  assert.equal(chat.requests.length, 1, 'Submitting again while loading cannot duplicate a request');
  await chat.finish('What size and haircut?');
  assert.equal(elements.send.disabled, false);
  assert.equal(JSON.parse(chat.storage.get(storageKey)).length, 2);

  const restored = createChat({stored: JSON.parse(chat.storage.get(storageKey))});
  assert.equal(restored.elements.messages.children.length, 2);
  assert.equal(restored.elements.messages.querySelector('.ai-chatbot-topics'), null);
  restored.elements.reset.listeners.click();
  assert.equal(restored.elements.messages.querySelector('.ai-chatbot-topics').children.length, 6);
  assert.equal(restored.storage.has(storageKey), false);

  const signedIn = createChat({signedIn: true});
  topics = signedIn.elements.messages.querySelector('.ai-chatbot-topics');
  assert.ok(topics.children.some(button => button.textContent === 'Check my schedule'));
  assert.ok(topics.children.some(button => button.textContent === 'Grooming Tracker'));
  assert.ok(!topics.children.some(button => button.textContent === 'Create an account'));

  elements.input.value = 'My password is PrivateTest123';
  elements.form.requestSubmit();
  await chat.finish('Please do not share private information.');
  assert.ok(!chat.storage.get(storageKey).includes('PrivateTest123'));
  chat.documentEvents.keydown({key:'Escape'});
  assert.equal(elements.panel.hidden, true);
  assert.equal(elements.button.focused, true);

  const longChat = createChat({stored: Array.from({length:12}, (_,i) => ({role:i % 2 ? 'assistant' : 'user',content:`Message ${i}`}))});
  longChat.elements.input.value = 'Large';
  longChat.elements.form.requestSubmit();
  assert.equal(longChat.requests[0].history.length, 8);
  assert.equal(longChat.requests[0].history[0].content, 'Message 4');
  await longChat.finish('Around 2 hours.');
  assert.equal(JSON.parse(longChat.storage.get(storageKey)).length, 12);
  console.log('PASS: chatbot topics, submit, reset, restoration, keyboard, privacy and bounded context.');
})().catch(error => { console.error(error); process.exitCode = 1; });
