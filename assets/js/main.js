'use strict';
// Configuration: only official addresses supplied by the site owner are used in HTML.
const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#main-nav');
function closeMenu() {
  navigation.classList.remove('is-open');
  menuButton.setAttribute('aria-expanded', 'false');
  menuButton.setAttribute('aria-label', 'باز کردن منو');
}
menuButton.addEventListener('click', () => {
  const open = menuButton.getAttribute('aria-expanded') !== 'true';
  navigation.classList.toggle('is-open', open);
  menuButton.setAttribute('aria-expanded', String(open));
  menuButton.setAttribute('aria-label', open ? 'بستن منو' : 'باز کردن منو');
});
navigation.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
document.addEventListener('click', event => { if (!event.target.closest('.site-header')) closeMenu(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') { const open = menuButton.getAttribute('aria-expanded') === 'true'; closeMenu(); if (open) menuButton.focus(); } });
window.matchMedia('(min-width: 901px)').addEventListener('change', closeMenu);
const header = document.querySelector('.site-header');
window.addEventListener('scroll', () => header.classList.toggle('scrolled', window.scrollY > 20), { passive: true });
const filters = [...document.querySelectorAll('[data-filter]')];
const contentCards = [...document.querySelectorAll('.content-card')];
function filterLessons(category) {
  let count = 0;
  contentCards.forEach(card => { card.hidden = category !== 'all' && card.dataset.category !== category; if (!card.hidden) count++; });
  filters.forEach(button => { const active = button.dataset.filter === category; button.classList.toggle('active', active); button.setAttribute('aria-pressed', String(active)); });
  document.querySelector('#filter-status').textContent = `${count.toLocaleString('fa-IR')} راهنمای آموزشی نمایش داده شد.`;
}
filters.forEach(button => button.addEventListener('click', () => filterLessons(button.dataset.filter)));
document.querySelectorAll('[data-category-link]').forEach(link => link.addEventListener('click', () => filterLessons(link.dataset.categoryLink)));
const dialog = document.querySelector('#lesson-dialog');
const lessonContent = document.querySelector('#lesson-content');
let lessonCache;
let lastTrigger;
let loading = false;
function element(tag, text) { const node = document.createElement(tag); node.textContent = text; return node; }
document.querySelectorAll('[data-lesson]').forEach(button => button.addEventListener('click', async () => {
  if (loading) return;
  lastTrigger = button;
  loading = true;
  button.setAttribute('aria-busy', 'true');
  try {
    if (!lessonCache) {
      const response = await fetch('assets/js/lessons.json');
      if (!response.ok) throw new Error('Content unavailable');
      lessonCache = await response.json();
    }
    const lesson = lessonCache[button.dataset.lesson];
    const title = element('h2', lesson.title);
    title.id = 'lesson-title';
    lessonContent.replaceChildren(title, element('p', lesson.intro));
    lesson.sections.forEach(([heading, text], index) => {
      lessonContent.append(element('h3', `${(index + 1).toLocaleString('fa-IR')}. ${heading}`), element('p', text));
    });
  } catch {
    const title = element('h2', 'راهنما فعلاً در دسترس نیست');
    title.id = 'lesson-title';
    lessonContent.replaceChildren(title, element('p', 'لطفاً دوباره امتحان کن یا آموزش‌های ویدیویی را در یوتیوب ببین.'));
  } finally {
    loading = false;
    button.removeAttribute('aria-busy');
    dialog.showModal();
    dialog.scrollTop = 0;
    document.body.classList.add('modal-open');
  }
}));
document.querySelector('.dialog-close')?.addEventListener('click', () => dialog.close());
dialog?.addEventListener('click', event => { if (event.target === dialog) { const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close(); } });
dialog?.addEventListener('close', () => { document.body.classList.remove('modal-open'); if (lastTrigger) lastTrigger.focus(); });
const aboutDetails = document.querySelector('#about-details');
document.querySelector('a[href="#about-details"]')?.addEventListener('click', () => { aboutDetails.open = true; });

// Service tabs: keyboard and touch interaction with a matching content panel.
const serviceTabs = [...document.querySelectorAll('[role="tab"]')];
function activateService(tab, moveFocus = false) {
  serviceTabs.forEach(item => {
    const selected = item === tab;
    item.setAttribute('aria-selected', String(selected));
    item.tabIndex = selected ? 0 : -1;
    document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
  });
  if (moveFocus) tab.focus();
}
serviceTabs.forEach((tab, index) => {
  tab.addEventListener('click', () => activateService(tab));
  tab.addEventListener('keydown', event => {
    let next;
    if (event.key === 'ArrowDown' || event.key === 'ArrowLeft') next = (index + 1) % serviceTabs.length;
    if (event.key === 'ArrowUp' || event.key === 'ArrowRight') next = (index - 1 + serviceTabs.length) % serviceTabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = serviceTabs.length - 1;
    if (next !== undefined) { event.preventDefault(); activateService(serviceTabs[next], true); }
  });
});

// Static article archive: category and text search work together.
const journalCards = [...document.querySelectorAll('[data-journal-search]')];
const journalFilters = [...document.querySelectorAll('[data-journal-filter]')];
const journalSearch = document.querySelector('#journal-search');
let journalCategory = 'all';
function normalizePersian(value) {
  return value.normalize('NFKC').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\u064B-\u065F\u0670]/g, '').replace(/\u200c/g, ' ').trim().toLocaleLowerCase('fa');
}
function updateJournal() {
  if (!journalSearch) return;
  const query = normalizePersian(journalSearch.value);
  let visible = 0;
  journalCards.forEach(card => {
    card.hidden = (journalCategory !== 'all' && card.dataset.journalCategory !== journalCategory) || !normalizePersian(card.dataset.journalSearch).includes(query);
    if (!card.hidden) visible++;
  });
  journalFilters.forEach(button => {
    const active = button.dataset.journalFilter === journalCategory;
    button.classList.toggle('active', active);
    button.setAttribute('aria-pressed', String(active));
  });
  document.querySelector('#journal-empty').hidden = visible > 0;
  document.querySelector('#journal-status').textContent = `${visible.toLocaleString('fa-IR')} مقاله نمایش داده شد.`;
}
journalFilters.forEach(button => button.addEventListener('click', () => { journalCategory = button.dataset.journalFilter; updateJournal(); }));
journalSearch?.addEventListener('input', updateJournal);
document.querySelector('#journal-reset')?.addEventListener('click', () => {
  journalCategory = 'all'; journalSearch.value = ''; updateJournal(); journalSearch.focus();
});

// Prepare a message locally. Nothing is sent or stored by the form.
const contactForm = document.querySelector('#contact-form');
contactForm?.addEventListener('submit', event => {
  event.preventDefault();
  const name = contactForm.elements.name;
  const message = contactForm.elements.message;
  name.setCustomValidity(name.value.trim() ? '' : 'لطفاً نامت را بنویس.');
  message.setCustomValidity(message.value.trim().length >= 10 ? '' : 'لطفاً پیامت را کمی بیشتر توضیح بده؛ دست‌کم ۱۰ نویسه.');
  if (!contactForm.reportValidity()) return;
  const reply = contactForm.elements.reply.value.trim();
  const parts = ['سلام وحید،', `من ${name.value.trim()} هستم.`, `موضوع: ${contactForm.elements.topic.value}`, '', message.value.trim()];
  if (reply) parts.push('', `راه پاسخ‌گویی: ${reply}`);
  document.querySelector('#prepared-message').value = parts.join('\n');
  document.querySelector('#contact-copy-status').textContent = '';
  document.querySelector('#contact-result').hidden = false;
  document.querySelector('#contact-result-title').focus();
});
contactForm?.addEventListener('input', event => { event.target.setCustomValidity?.(''); });
document.querySelector('#copy-contact-message')?.addEventListener('click', async () => {
  const field = document.querySelector('#prepared-message');
  const status = document.querySelector('#contact-copy-status');
  try {
    await navigator.clipboard.writeText(field.value);
    status.textContent = 'متن کپی شد. حالا آن را در راه ارتباطی دلخواهت ارسال کن.';
  } catch {
    field.focus(); field.select(); field.setSelectionRange(0, field.value.length);
    status.textContent = 'متن انتخاب شد؛ آن را با گزینه کپی دستگاهت کپی کن و در راه ارتباطی دلخواه بفرست.';
  }
});
document.querySelector('[data-copy-article]')?.addEventListener('click', async event => {
  const status = document.querySelector('.copy-article-status');
  const copyButton = event.currentTarget;
  try {
    await navigator.clipboard.writeText(window.location.href.split('#')[0]);
    status.textContent = 'پیوند مقاله کپی شد.';
    copyButton.textContent = 'پیوند کپی شد ✓';
  } catch {
    status.textContent = 'برای اشتراک‌گذاری، نشانی این صفحه را از نوار مرورگر کپی کن.';
  }
});

// Enable preparation only after the local submit handler is ready.
const prepareContactButton = document.querySelector('#prepare-contact-message');
if (prepareContactButton) prepareContactButton.disabled = false;
